<?php

namespace HorizonLens\Http;

use HorizonLens\Broadcasts\BroadcastIntegration;
use HorizonLens\Store;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class BroadcastAuthorizationController
{
    public function __invoke(Request $request, Store $store)
    {
        abort_unless(BroadcastIntegration::visible(), 404);
        $filters = $request->validate([
            'route' => 'nullable|string|max:160', 'status' => 'nullable|integer|min:400|max:599', 'channel' => 'nullable|string|max:160',
            'from' => 'nullable|date_format:Y-m-d\TH:i:s', 'to' => 'nullable|date_format:Y-m-d\TH:i:s',
        ]);
        if (! empty($filters['from']) && ! empty($filters['to']) && $filters['from'] >= $filters['to']) {
            throw ValidationException::withMessages(['to' => 'The end must be after the start.']);
        }
        $query = $store->table('broadcast_auth_failures')->where('expires_at', '>', microtime(true));
        foreach (['route' => 'route_name', 'status' => 'http_status'] as $input => $column) {
            if (! empty($filters[$input])) {
                $query->where($column, $filters[$input]);
            }
        }
        if (! empty($filters['channel'])) {
            $query->whereJsonContains('channels_json', $filters['channel']);
        }
        foreach (['from' => '>=', 'to' => '<'] as $input => $operator) {
            if (! empty($filters[$input])) {
                $query->where('observed_at', $operator, (new \DateTimeImmutable($filters[$input], new \DateTimeZone('UTC')))->getTimestamp());
            }
        }

        return view('horizon-lens::broadcast-authorization', ['filters' => $filters, 'failures' => $query->orderByDesc('observed_at')->orderByDesc('id')->simplePaginate(25)->withQueryString()]);
    }
}
