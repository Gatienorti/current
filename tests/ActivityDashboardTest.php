<?php

namespace Current\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ActivityDashboardTest extends TestCase
{
    private function receipt(array $values = []): string
    {
        $id = (string) Str::ulid();
        $this->store()->table('webhook_receipts')->insert($values + ['id' => $id, 'provider' => 'demo-provider', 'route' => 'hooks/demo', 'method' => 'POST', 'received_at' => time() - 60, 'finished_at' => time() - 59, 'duration_ms' => 100, 'http_status' => 202, 'expires_at' => time() + 3600]);

        return $id;
    }

    public function test_webhook_dashboard_totals_trends_failure_scope_and_explicit_repeats(): void
    {
        $one = $this->receipt(['http_status' => 503, 'receipt_key' => str_repeat('a', 64)]);
        $two = $this->receipt(['receipt_key' => str_repeat('a', 64)]);
        $this->receipt(['http_status' => null, 'finished_at' => null, 'duration_ms' => null]);
        $expired = $this->receipt(['expires_at' => time() - 1, 'exception_class' => 'ExpiredSecret']);
        $old = $this->receipt(['received_at' => time() - 90000, 'exception_class' => 'OldSecret']);
        $this->receipt(['received_at' => time() + 100, 'exception_class' => 'FutureSecret']);
        foreach ([503, 200] as $status) {
            $this->store()->table('webhook_deliveries')->insert(['id' => (string) Str::ulid(), 'destination' => 'demo-destination', 'endpoint' => 'https://example.test/hook', 'method' => 'POST', 'started_at' => time() - 60, 'finished_at' => time() - 59, 'http_status' => $status, 'duration_ms' => 50, 'delivery_key' => str_repeat('b', 64), 'expires_at' => time() + 3600]);
        }
        $page = $this->get('/current/webhooks/dashboard')->assertOk()->assertSee('Webhook dashboard')->assertSee('demo-provider')->assertSee('demo-destination')->assertSee($one)->assertSee($two)->assertDontSee($expired)->assertDontSee($old)->assertDontSee('FutureSecret');
        $streams = $page->viewData('streams');
        $this->assertSame(3, $streams['incoming']['stats']->total);
        $this->assertSame(1, $streams['incoming']['stats']->failures);
        $this->assertSame(1, $streams['incoming']['stats']->unfinished);
        $this->assertSame(3, array_sum(array_column($streams['incoming']['series'], 'total')));
        $this->assertCount(24, $streams['incoming']['series']);
        $this->assertSame(2, $streams['incoming']['repeated'][0]->total);
        $this->assertSame(2, $streams['outgoing']['stats']->total);
        $this->assertSame(1, $streams['outgoing']['stats']->failures);
        $this->assertSame(2, $streams['outgoing']['repeated'][0]->total);
        $this->assertSame(4, $this->get('/current/webhooks/dashboard?range=7d')->viewData('streams')['incoming']['stats']->total);
        $this->assertCount(12, $this->get('/current/webhooks/dashboard?range=1h')->viewData('streams')['incoming']['series']);
    }

    public function test_broadcast_dashboard_counts_submission_and_authorization_failures_separately(): void
    {
        config()->set('current.broadcasts.visible', true);
        foreach (['accepted', 'failed', 'unknown'] as $outcome) {
            $this->store()->table('broadcasts')->insert(['id' => (string) Str::ulid(), 'connection' => 'reverb', 'event' => 'SafeEvent', 'channels_json' => '["private-orders.*","private-orders.*"]', 'outcome' => $outcome, 'started_at' => time() - 90, 'finished_at' => $outcome === 'unknown' ? null : time() - 89, 'duration_ms' => $outcome === 'unknown' ? null : 80, 'expires_at' => time() + 3600]);
        }
        $this->store()->table('broadcast_auth_failures')->insert(['id' => (string) Str::ulid(), 'route_name' => 'broadcasting/auth', 'method' => 'POST', 'http_status' => 403, 'channel_type' => 'Private', 'channels_json' => '[]', 'observed_at' => time() - 90, 'expires_at' => time() + 3600]);
        $page = $this->get('/current/broadcasts/dashboard')->assertOk()->assertSee('SafeEvent')->assertSee('broadcasting/auth')->assertSee('Inspect unfinished')->assertSee('capture: disabled');
        $streams = $page->viewData('streams');
        $this->assertSame(3, $streams['submissions']['stats']->total);
        $this->assertSame(1, $streams['submissions']['stats']->failures);
        $this->assertSame(1, $streams['submissions']['stats']->unfinished);
        $this->assertEquals(80, $streams['submissions']['stats']->duration);
        $this->assertSame(1, $streams['authorization']['stats']->total);
        $this->assertSame(3, $page->viewData('patterns')['counts']['private-orders.*']);
        $this->assertCount(1, $streams['submissions']['recent']);
    }

    public function test_missing_storage_is_unavailable_and_empty_history_is_not_claimed_healthy(): void
    {
        $this->get('/current/webhooks/dashboard')->assertOk()->assertSee('No retained observations')->assertSee('Missing telemetry is unknown');
        Schema::connection('lens')->drop('lens_webhook_receipts');
        $page = $this->get('/current/webhooks/dashboard')->assertOk()->assertSee('History unavailable');
        $this->assertFalse($page->viewData('streams')['incoming']['available']);
        $this->assertTrue($page->viewData('streams')['outgoing']['available']);
    }

    public function test_dashboard_navigation_preserves_lists_and_access_controls(): void
    {
        config()->set('current.broadcasts.visible', true);
        $this->get('/current')->assertOk()->assertSee('href="'.route('current.webhooks.dashboard').'"', false)->assertSee('href="'.route('current.broadcasts.dashboard').'"', false);
        $this->get('/current/webhooks')->assertOk()->assertSee('Incoming webhooks');
        $this->get('/current/broadcasts')->assertOk()->assertSee('Submissions');
        $this->getJson('/current/webhooks/dashboard?range=bad')->assertUnprocessable();
        $this->actingAs(new GenericUser(['id' => 2, 'is_admin' => false]));
        $this->get('/current/webhooks/dashboard')->assertForbidden();
        $this->get('/current/broadcasts/dashboard')->assertForbidden();
        $this->actingAs(new GenericUser(['id' => 1, 'is_admin' => true]));
        config()->set('current.broadcasts.visible', false);
        $this->get('/current/broadcasts/dashboard')->assertNotFound();
    }
}
