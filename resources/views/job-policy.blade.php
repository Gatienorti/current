@php
    $policy = json_decode($attempt->policy_json ?? 'null', true);
    $hasPolicy = is_array($policy) && ($policy['version'] ?? null) === 1;
    if (!$hasPolicy) {
        $policy = [
            'tries' => ['value' => $job->max_tries, 'source' => $job->max_tries === null ? 'Worker default not captured' : 'Enqueue payload'],
            'timeout' => ['value' => $job->timeout, 'source' => $job->timeout === null ? 'Worker default not captured' : 'Enqueue payload'],
            'backoff' => ['value' => $job->backoff, 'source' => $job->backoff === null ? 'Worker default not captured' : 'Enqueue payload'],
            'retry_until' => ['value' => $job->retry_until, 'source' => 'Enqueue payload'],
            'retry_after' => ['value' => null, 'source' => 'Historical connection setting not captured'],
        ];
    }
    $policyLabels = ['tries' => 'Attempts limit', 'timeout' => 'Timeout', 'backoff' => 'Backoff', 'retry_until' => 'Retry deadline', 'retry_after' => 'Reservation · retry_after', 'max_exceptions' => 'Exception limit', 'fail_on_timeout' => 'Fail on timeout'];
    $policyWarnings = $hasPolicy ? \Current\Support\AttemptPolicy::warnings($policy, $attempt) : [];
    $settingWarnings = [];
    foreach ($policyWarnings as $warning) {
        $warningKeys = match (true) {
            str_starts_with($warning, 'Duplicate execution risk') => ['timeout', 'retry_after'],
            str_starts_with($warning, 'No worker timeout') => ['timeout'],
            str_starts_with($warning, 'No retry backoff') => ['backoff'],
            str_starts_with($warning, 'Retry deadline') => ['retry_until', 'tries'],
            default => ['tries'],
        };
        foreach ($warningKeys as $warningKey) {
            $settingWarnings[$warningKey][] = $warning;
        }
    }
@endphp
<section class="panel job-policy" id="execution-settings">
    <div class="panel-heading"><h2>Execution settings</h2></div>
    @unless($hasPolicy)<p class="small-note">Enqueue snapshot · worker settings unavailable</p>@endunless
    <dl class="policy-grid">
        @foreach($policyLabels as $key => $label)
            @continue(!array_key_exists($key, $policy))
            @php
                $entry = $policy[$key];
                $value = $entry['value'] ?? null;
                $formatted = $value === null ? (!empty($entry['unset']) ? 'None' : 'Unknown') : match($key) {
                    'tries' => $value == 0 ? 'Unlimited' : $value,
                    'timeout' => $value == 0 ? 'No timeout' : $value.' s',
                    'backoff', 'retry_after' => $value.' s',
                    'retry_until' => $value ? gmdate('M j, Y H:i:s', (int) $value).' UTC' : 'None',
                    'fail_on_timeout' => $value ? 'Yes' : 'No',
                    default => $value,
                };
            @endphp
            <div class="policy-item {{ isset($settingWarnings[$key]) ? 'policy-item-warning' : '' }}">
                <dt>
                    @if(isset($settingWarnings[$key]))
                        <button type="button" class="policy-warning-label" aria-describedby="policy-warning-{{ $key }}">{{ $label }} @include('current::icon', ['iconName' => 'alert'])</button>
                    @else
                        {{ $label }}
                    @endif
                </dt>
                <dd>{{ $formatted }}<small class="policy-source">{{ $entry['source'] ?? 'Not captured' }}</small>
                @if(isset($settingWarnings[$key]))
                    <span class="policy-tooltip" role="tooltip" id="policy-warning-{{ $key }}">{{ implode(' ', $settingWarnings[$key]) }}</span>
                @endif
                </dd>
            </div>
        @endforeach
    </dl>
</section>
