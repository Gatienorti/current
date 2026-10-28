<?php

namespace Current\Http;

use Current\Monitoring\Tags;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

final class MonitoredTagsController
{
    public function store(Request $request, Tags $tags) { return $this->change($request, $tags, false); }
    public function destroy(Request $request, Tags $tags) { return $this->change($request, $tags, true); }

    public function history(Request $request, Tags $tags)
    {
        $input = $request->validate(['tag' => ['required', 'string', 'max:100'], 'kind' => ['nullable', 'in:monitored,failed'],
            'offset' => ['nullable', 'integer', 'min:0', 'max:10000000']]);
        try { $history = $tags->history($input['tag'], ($input['kind'] ?? '') === 'failed', (int) ($input['offset'] ?? 0)); }
        catch (\InvalidArgumentException) { throw ValidationException::withMessages(['tag' => 'Use an approved tag.']); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) { throw $exception; }
        catch (Throwable $exception) { $history = ['available' => false, 'collector_unknown' => $exception instanceof \Current\Native\MonitoredRetentionUnavailable]; }
        return view('current::monitored-history', $history + ['available' => true, 'tag' => $input['tag'],
            'kind' => $input['kind'] ?? 'monitored', 'section' => 'monitoring']);
    }

    private function change(Request $request, Tags $tags, bool $remove)
    {
        abort_unless(Gate::has('operateCurrent') && Gate::allows('operateCurrent'), 403);
        $input = $request->validate(['tag' => ['required', 'string', 'max:100']]);
        try {
            $remove ? $tags->remove($input['tag']) : $tags->add($input['tag']);
            $notice = $remove ? 'Tag monitoring stopped. Existing Current detail keeps its established retention.' : 'Tag monitoring enabled for future observations.';
        } catch (\InvalidArgumentException $exception) { throw ValidationException::withMessages(['tag' => $exception->getMessage()]); }
        catch (Throwable $exception) { $notice = $exception instanceof \Current\Native\MonitoredRetentionUnavailable
            ? 'Start the matching collector with monitored retention support before enabling monitoring. No monitoring change was made.'
            : 'The monitoring change could not be confirmed. Inspect the monitored tags before another action.'; }
        return redirect()->route('current.monitoring', ['manage_tags' => 1])->with('current_monitoring_notice', $notice);
    }
}
