<?php

namespace HorizonLens\Http;

use HorizonLens\Broadcasts\BroadcastIntegration;
use HorizonLens\Dashboards\ActivityDashboard;
use Illuminate\Http\Request;

final class ActivityDashboardController
{
    public function __invoke(Request $request, ActivityDashboard $dashboard)
    {
        $area = $request->route('area');
        abort_if($area === 'broadcasts' && ! BroadcastIntegration::visible(), 404);
        $range = $request->validate(['range' => 'nullable|in:1h,24h,7d'])['range'] ?? '24h';
        [$seconds, $buckets] = match ($range) {
            '1h' => [3600, 12], '7d' => [604800, 28], default => [86400, 24]
        };
        $to = (float) time();
        $from = $to - $seconds;
        $window = ['from' => gmdate('Y-m-d\TH:i:s', (int) $from), 'to' => gmdate('Y-m-d\TH:i:s', (int) $to)];
        $definitions = $area === 'webhooks' ? [
            'incoming' => ['webhook_receipts', 'received_at', 'provider', "http_status >= 400 OR signature = 'invalid' OR exception_class IS NOT NULL", 'receipt_key', false],
            'outgoing' => ['webhook_deliveries', 'started_at', 'destination', 'http_status >= 400 OR exception_class IS NOT NULL', 'delivery_key', false],
        ] : [
            'submissions' => ['broadcasts', 'started_at', 'event', "outcome = 'failed'", null, false],
            'authorization' => ['broadcast_auth_failures', 'observed_at', 'route_name', '1 = 1', null, true],
        ];
        $streams = [];
        foreach ($definitions as $key => [$table, $time, $dimension, $failure, $group, $auth]) {
            try {
                $streams[$key] = $dashboard->stream($table, $time, $dimension, $failure, $from, $to, $buckets, $group, $auth);
            } catch (\Throwable) {
                $streams[$key] = ['available' => false];
            }
        }
        $patterns = null;
        if ($area === 'broadcasts') {
            try {
                $patterns = $dashboard->patterns($from, $to);
            } catch (\Throwable) {
            }
        }

        return view('horizon-lens::activity-dashboard', compact('area', 'range', 'window', 'streams', 'patterns'));
    }
}
