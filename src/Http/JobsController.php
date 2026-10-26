<?php

namespace Current\Http;

use Current\Broadcasts\BroadcastIntegration;
use Current\DebugPrompt;
use Current\HorizonAdapter;
use Current\RetryContext;
use Current\Store;
use Current\Webhooks\WebhookRelations;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class JobsController
{
    public function __construct(private Store $store) {}

    public function index(Request $request, string $section = 'dashboard')
    {
        $scopeStatus = in_array($section, ['pending', 'processing', 'completed', 'failed'], true) ? $section : null;
        $pages = [
            'monitoring' => ['Monitoring', 'Find the right job by combining filters across retained observations.'],
            'dashboard' => ['Dashboard', 'Horizon operations and captured job history in one place.'],
            'recent' => ['Recent jobs', 'The latest job observations across the retained Lens dataset.'],
            'pending' => ['Pending jobs', 'Jobs last observed enqueued or released back to the queue.'],
            'processing' => ['Processing jobs', 'Jobs with an observed execution start and no observed finish.'],
            'completed' => ['Completed jobs', 'Worker completions captured without an observed failure or release.'],
            'failed' => ['Failed jobs', 'Terminal failures ready for investigation.'],
            'silenced' => ['Silenced jobs', 'Captured completions marked silenced by Horizon. Earlier silenced history is not backfilled.'],
        ];
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'processing', 'completed', 'failed', 'unknown', 'incomplete', 'conflicting'])],
            'history' => ['nullable', Rule::in(['unfinished', 'overlapping', 'partial'])],
            'queue' => ['nullable', Rule::when(is_array($request->input('queue')), ['array', 'max:50'], ['string', 'max:100'])],
            'queue.*' => 'nullable|string|max:100', 'job_class' => 'nullable|string|max:240',
            'connection' => 'nullable|string|max:100', 'job_id' => 'nullable|string|max:100', 'exception_class' => 'nullable|string|max:240',
            'tags' => 'nullable|string|max:500',
            'from' => 'nullable|date_format:Y-m-d\TH:i,Y-m-d\TH:i:s', 'to' => ['nullable', 'date_format:Y-m-d\TH:i,Y-m-d\TH:i:s', Rule::when($request->filled('from'), 'after:from')],
        ]);
        if (is_array($filters['queue'] ?? null)) {
            $filters['queue'] = array_values(array_unique(array_filter($filters['queue'], fn ($queue) => $queue !== null && $queue !== '')));
        }
        if (in_array($filters['status'] ?? '', ['incomplete', 'conflicting'], true)) {
            $parameters = $request->query();
            $parameters['history'] = $filters['status'] === 'incomplete' ? 'unfinished' : 'overlapping';
            unset($parameters['status']);

            return redirect()->route('current.'.$section, $parameters);
        }
        $requestedFilters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        if ($scopeStatus) {
            $filters['status'] = $scopeStatus;
        }
        if ($section === 'dashboard' && $requestedFilters) {
            $parameters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
            $cursor = $request->query('cursor');
            if (is_string($cursor) && strlen($cursor) <= 4096) {
                $parameters['cursor'] = $cursor;
            }

            return redirect()->route('current.monitoring', $parameters);
        }
        $now = microtime(true);
        $base = $this->store->table('jobs')->where('expires_at', '>', $now);
        $query = $this->store->jobsWithObservationState()->where('expires_at', '>', $now);
        if ($section === 'silenced') {
            $query->where('silenced', true)->whereRaw($this->store->outcomeSql()." = 'completed'");
        } elseif ($section === 'completed') {
            $query->where(fn ($q) => $q->where('silenced', false)->orWhereNull('silenced'));
        }
        foreach (['queue', 'connection', 'job_class', 'exception_class'] as $field) {
            if (! empty($filters[$field])) {
                if ($field === 'queue') {
                    $query->whereIn($field, (array) $filters[$field]);
                } else {
                    $query->where($field, $filters[$field]);
                }
            }
        }
        if (! empty($filters['status'])) {
            $query->whereRaw($this->store->outcomeSql().' = ?', [$filters['status']]);
        }
        if (($filters['history'] ?? '') === 'unfinished') {
            $query->whereRaw($this->store->unfinishedSql().' > 0');
        } elseif (($filters['history'] ?? '') === 'overlapping') {
            $query->whereRaw($this->store->unfinishedSql().' > 1');
        } elseif (($filters['history'] ?? '') === 'partial') {
            $query->where('coverage', 'start_only');
        }
        if (! empty($filters['job_id'])) {
            $query->where('external_id', $filters['job_id']);
        }
        foreach (array_unique(array_filter(array_map('trim', explode(',', $filters['tags'] ?? '')))) as $tag) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('lens_tags')->whereColumn('lens_tags.job_id', 'lens_jobs.id')->where('tag', $tag));
        }
        foreach (['from' => '>=', 'to' => '<'] as $field => $operator) {
            if (! empty($filters[$field])) {
                $query->where('observed_at', $operator, strtotime($filters[$field].' UTC'));
            }
        }
        $total = (clone $query)->count();
        $jobs = $query->orderByDesc('observed_at')->orderByDesc('id')->cursorPaginate(20)->withQueryString();
        $tags = $this->store->table('tags')->whereIn('job_id', $jobs->pluck('id'))->get()->groupBy('job_id');
        $outcomes = (clone $base)->selectRaw($this->store->outcomeSql().' AS display_status');
        $counts = $this->store->connection()->query()->fromSub($outcomes, 'observed_outcomes')
            ->select('display_status')->selectRaw('COUNT(*) AS aggregate')->groupBy('display_status')->pluck('aggregate', 'display_status');

        return view(match ($section) {
            'dashboard' => 'current::dashboard',
            'monitoring' => 'current::monitoring',
            default => 'current::index',
        }, [
            'section' => $section, 'pageTitle' => $pages[$section][0], 'pageDescription' => $pages[$section][1],
            'scopeStatus' => $scopeStatus, 'compactFilters' => true, 'pageRoute' => 'current.'.$section,
            'queueSummary' => (clone $base)->select('queue')->selectRaw('COUNT(*) AS jobs')->groupBy('queue')->orderBy('queue')->get(),
            'jobs' => $jobs, 'total' => $total, 'filters' => $filters, 'tags' => $tags, 'counts' => $counts,
            'detailContext' => $request->getRequestUri(),
            'queues' => (clone $base)->distinct()->orderBy('queue')->pluck('queue'),
            'classes' => (clone $base)->distinct()->orderBy('job_class')->limit(100)->pluck('job_class'),
            'exceptions' => (clone $base)->whereNotNull('exception_class')->distinct()->limit(100)->pluck('exception_class'),
            'coverage' => $this->store->table('meta')->pluck('value', 'key'),
            'health' => $this->health(),
            'horizon' => $section === 'dashboard' ? app(HorizonAdapter::class)->dashboard() : null,
            'silencedCoverage' => $section === 'silenced' ? $this->store->table('meta')->where('key', 'silenced_capture_started_at')->value('value') : null,
        ]);
    }

    public function show(Request $request, string $id)
    {
        $job = $this->store->jobsWithObservationState()->where('id', $id)->where('expires_at', '>', microtime(true))->first();
        abort_unless($job, 404);
        $attempts = $this->store->table('attempts')->where('job_id', $id)->orderByDesc('started_at')->orderByDesc('id')->get();
        $attempts->each(function ($item) use ($attempts) {
            $retryAfterError = $item->status === 'released' && $item->exception_class;
            $retried = $retryAfterError && $item->finished_at && $attempts->contains(fn ($next) => $next->id !== $item->id && $next->started_at >= $item->finished_at);
            $item->display_status = $retryAfterError ? 'failed' : $item->status;
            $item->display_label = $retryAfterError ? ($retried ? 'Retried' : 'Retry pending') : ucfirst(str_replace('_', ' ', $item->status));
        });
        $validated = $request->validate(['attempt' => 'nullable|string|size:26', 'return' => 'nullable|string|max:8192']);
        $selected = $validated['attempt'] ?? null;
        $attempt = $selected ? $attempts->firstWhere('id', $selected) : $attempts->first();
        abort_if($selected && ! $attempt, 404);
        $steps = $attempt ? $this->store->table('steps')->where('attempt_id', $attempt->id)->orderBy('sequence')->get() : collect();
        $operations = $attempt ? json_decode($attempt->source_operations ?? '[]', true) : [];
        $frames = $attempt ? json_decode($attempt->exception_trace ?? '[]', true) : [];
        $base = rtrim(route('horizon.index'), '/');
        $context = $this->returnContext($validated['return'] ?? null);
        $failedStep = $attempt?->exception_class ? $steps->filter(fn ($step) => $step->status === 'failed'
            && $step->exception_class === $attempt->exception_class
            && $step->exception_file === $attempt->exception_file
            && $step->exception_line === $attempt->exception_line)->last() : null;
        $defaultInspector = $attempt?->exception_class ? ($failedStep ? 'step-'.$failedStep->id : 'attempt-context') : ($steps->isNotEmpty() ? 'step-'.$steps->first()->id : 'attempt-context');

        $retryState = json_decode($this->store->table('meta')->where('key', 'retry:'.$id)->value('value') ?? '{}', true);
        $retryJob = isset($retryState['uuid']) ? $this->store->findCycle($retryState['uuid'], $job->connection) : null;

        $retryContext = app(RetryContext::class)->forJob($job);
        $argumentsSource = $retryContext['sources']['arguments'] ?? $job;
        $metadataSource = $retryContext['sources']['metadata'] ?? $job;

        $debugPrompt = ($attempt?->exception_class || $attempt?->status === 'failed' || $job->display_status === 'failed')
            ? app(DebugPrompt::class)->make($job, $attempt, $steps, $frames, $argumentsSource, $metadataSource) : null;

        return view('current::show', [
            'debugPrompt' => $debugPrompt,
            'relatedBroadcasts' => BroadcastIntegration::visible() ? $this->store->table('broadcasts')->where('job_id', $id)->where('expires_at', '>', microtime(true))->orderByDesc('started_at')->orderByDesc('id')->limit(100)->get() : collect(),
            'webhookReceipts' => app(WebhookRelations::class)->receipts($id),
            'webhookDeliveries' => app(WebhookRelations::class)->deliveries([$id]),
            'retryContext' => $retryContext, 'argumentsSource' => $argumentsSource, 'metadataSource' => $metadataSource,
            'watch' => $request->boolean('watch') && ! $selected,
            'retryRequested' => ! empty($retryState), 'retryJob' => $retryJob,
            'job' => $job, 'attempts' => $attempts, 'attempt' => $attempt, 'steps' => $steps,
            'operations' => $operations, 'frames' => $frames,
            'failedStep' => $failedStep, 'defaultInspector' => $defaultInspector,
            'argumentsSnapshot' => $this->snapshot($argumentsSource->arguments_json, $argumentsSource->arguments_state),
            'metadataSnapshot' => $this->snapshot($metadataSource->metadata_json, $metadataSource->metadata_state),
            'backUrl' => $context['url'], 'backLabel' => $context['label'], 'section' => $context['section'],
            'returnTo' => $context['return'],
            'jobTags' => $this->store->table('tags')->where('job_id', $id)->pluck('tag'),
            'horizonUrl' => $base.($job->display_status === 'failed' ? '/failed/' : '/jobs/recent/').rawurlencode($job->external_id),
        ]);
    }

    private function returnContext(?string $return): array
    {
        $pages = ['index' => 'Dashboard', 'dashboard' => 'Dashboard', 'monitoring' => 'Monitoring', 'recent' => 'Recent jobs', 'pending' => 'Pending jobs', 'processing' => 'Processing jobs', 'completed' => 'Completed jobs', 'failed' => 'Failed jobs', 'silenced' => 'Silenced jobs'];
        $parts = $return && ! preg_match('/[\\\\\x00-\x20]/', $return) ? parse_url($return) : false;
        if ($parts && ! array_intersect(['scheme', 'host', 'user', 'pass', 'port', 'fragment'], array_keys($parts))) {
            foreach ($pages as $section => $label) {
                if (($parts['path'] ?? '') === route('current.'.$section, [], false)) {
                    return ['url' => $return, 'label' => $label, 'section' => $section === 'index' ? 'dashboard' : $section, 'return' => $return];
                }
            }
        }

        return ['url' => route('current.monitoring'), 'label' => 'Monitoring', 'section' => 'monitoring', 'return' => null];
    }

    private function snapshot(?string $json, ?string $state): array
    {
        if ($json === null) {
            return ['state' => $state ?? 'not_captured', 'value' => null, 'json' => null];
        }
        try {
            $value = json_decode($json, false, 32, JSON_THROW_ON_ERROR);

            return ['state' => $state ?? 'captured', 'value' => $value, 'json' => json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        } catch (\JsonException) {
            return ['state' => 'failed', 'value' => null, 'json' => null];
        }
    }

    private function health(): ?array
    {
        $path = config('current.diagnostic_path');

        return $path && is_file($path) ? json_decode(file_get_contents($path), true) : null;
    }
}
