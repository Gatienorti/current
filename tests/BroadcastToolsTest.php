<?php

namespace Current\Tests;

use Current\Broadcasts\AuthorizationRecorder;
use Current\Broadcasts\BroadcastDebugPrompt;
use Current\Broadcasts\BroadcastRecorder;
use Current\Broadcasts\SubmissionComparison;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class BroadcastToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('current.broadcasts', ['visible' => true, 'enabled' => true, 'events' => ['OrderUpdated'], 'channels' => ['private-orders.*', 'presence-team.*']]);
    }

    private function submission(array $payload = [], bool $fail = false): object
    {
        $context = app(BroadcastRecorder::class)->start('reverb', ['private-orders.1'], 'OrderUpdated', $payload);
        BroadcastRecorder::finish($context, $fail ? new \RuntimeException('private-message') : null);

        return $this->store()->table('broadcasts')->where('id', $context['id'])->first();
    }

    public function test_authorization_capture_is_explicit_private_and_never_changes_responses(): void
    {
        Route::post('/__auth', fn (Request $request) => response('private-auth-body', (int) $request->input('status', 403))->header('X-Unchanged', 'yes'))->name('test.broadcast.auth');
        $body = ['channel_name' => 'private-orders.123456', 'socket_id' => 'secret-socket', 'token' => 'secret-token'];
        $this->post('/__auth', $body)->assertStatus(403)->assertHeader('X-Unchanged', 'yes')->assertSee('private-auth-body');
        $this->assertSame(0, $this->store()->table('broadcast_auth_failures')->count());
        config()->set('current.broadcasts.authorization', ['enabled' => true, 'routes' => ['different']]);
        $this->post('/__auth', $body)->assertStatus(403);
        $this->assertSame(0, $this->store()->table('broadcast_auth_failures')->count());
        config()->set('current.broadcasts.authorization.routes', ['test.broadcast.auth']);
        foreach ([200, 302, 401, 403, 419, 429, 500] as $status) {
            $this->post('/__auth', $body + ['status' => $status])->assertStatus($status)->assertHeader('X-Unchanged', 'yes');
        }
        $rows = $this->store()->table('broadcast_auth_failures')->get();
        $this->assertCount(5, $rows);
        foreach (['123456', 'secret-socket', 'secret-token', 'private-auth-body'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($rows));
        }
        $this->assertSame('["private-orders.*"]', $rows[0]->channels_json);
        $this->get('/current/broadcasts/authorization?status=419&channel=private-orders.*&route=test.broadcast.auth')->assertOk()->assertSee('Session / CSRF response')->assertDontSee('Forbidden response');
        $this->get('/current/broadcasts/authorization?status=403&channel=presence-team.*')->assertOk()->assertSee('No retained authorization failures');
        config()->set('current.broadcasts.capture_channels', true);
        $this->post('/__auth', ['channel_name' => 'presence-team.42'])->assertStatus(403);
        $this->assertSame('["presence-team.42"]', $this->store()->table('broadcast_auth_failures')->orderByDesc('id')->value('exact_channels_json'));
        $this->post('/__auth', ['channel_name' => 'private-unapproved.99'])->assertStatus(403);
        $this->assertNull($this->store()->table('broadcast_auth_failures')->orderByDesc('id')->value('exact_channels_json'));
        $this->post('/__auth', ['channel_name' => ['malformed']])->assertStatus(403);
        $this->assertSame('Unknown', $this->store()->table('broadcast_auth_failures')->orderByDesc('id')->value('channel_type'));
        Route::post('/__unnamed-auth', fn () => response('unchanged', 403));
        config()->set('current.broadcasts.authorization.paths', ['__unnamed-auth']);
        $this->post('/__unnamed-auth', $body)->assertStatus(403);
        $this->assertSame('__unnamed-auth', $this->store()->table('broadcast_auth_failures')->orderByDesc('id')->value('route_name'));
        $this->store()->table('broadcast_auth_failures')->update(['expires_at' => time() - 1]);
        $this->get('/current/broadcasts/authorization')->assertOk()->assertSee('No retained authorization failures');
        $this->assertSame(9, app(AuthorizationRecorder::class)->prune());
        Schema::connection('lens')->drop('lens_broadcast_auth_failures');
        $this->post('/__auth', $body)->assertStatus(403)->assertSee('private-auth-body');
    }

    public function test_comparison_preserves_missing_null_types_and_capture_coverage(): void
    {
        config()->set('current.broadcasts.context', fn ($event, $channels, $payload) => ['payload' => $payload, 'metadata' => ['source' => 'test']]);
        $a = $this->submission(['same' => 1, 'changed' => 1, 'removed' => null, 'nested' => ['a/b' => true], 'xss' => '</textarea><script>bad()</script>']);
        $b = $this->submission(['same' => 1, 'changed' => '1', 'added' => null, 'nested' => ['a/b' => false], 'xss' => 'safe'], true);
        $result = app(SubmissionComparison::class)->compare($a, $b);
        $rows = collect($result['rows'])->keyBy('path');
        $this->assertFalse($rows->has('/payload/same'));
        $this->assertSame('Removed', $rows['/payload/removed']['kind']);
        $this->assertSame('null', $rows['/payload/removed']['left']);
        $this->assertSame('Added', $rows['/payload/added']['kind']);
        $this->assertSame('1', $rows['/payload/changed']['left']);
        $this->assertSame('"1"', $rows['/payload/changed']['right']);
        $this->assertTrue($rows->has('/payload/nested/a~1b'));
        $this->assertFalse($result['coverage']['exact_channels']['compared']);
        $this->get('/current/broadcasts/'.$a->id.'/compare?with='.$b->id)->assertOk()->assertSee('Snapshot coverage')->assertSee('/payload/changed')->assertSee('Unavailable — not compared')->assertDontSee('</textarea><script>bad()</script>', false);
        $b->payload_state = 'limited';
        $result = app(SubmissionComparison::class)->compare($a, $b);
        $this->assertFalse($result['coverage']['payload']['compared']);
        $this->assertFalse(collect($result['rows'])->contains(fn ($row) => str_starts_with($row['path'], '/payload')));
        $b->payload_state = 'captured';
        $a->payload_json = '{}';
        $b->payload_json = json_encode(array_fill_keys(range(1, 250), true));
        $result = app(SubmissionComparison::class)->compare($a, $b);
        $this->assertTrue($result['limited']);
        $this->assertCount(200, $result['rows']);
    }

    public function test_prompt_uses_only_retained_sanitized_context_and_is_bounded_escaped_and_available_for_accepted_events(): void
    {
        config()->set('current.broadcasts.context', fn ($event, $channels, $payload) => ['payload' => $payload]);
        $row = $this->submission(['token' => 'private-token', 'note' => '</textarea><script>bad()</script>']);
        $prompt = app(BroadcastDebugPrompt::class)->make($row, null, null);
        $this->assertStringContainsString('untrusted diagnostic data', $prompt);
        $this->assertStringContainsString('acceptance does not prove', $prompt);
        $this->assertStringContainsString('[REDACTED]', $prompt);
        $this->assertStringNotContainsString('private-token', $prompt);
        $this->get('/current/broadcasts/'.$row->id)->assertOk()->assertSee('Debug with AI')->assertSee('data-debug-dialog', false)->assertDontSee('</textarea><script>bad()</script>', false);
        $row->payload_json = json_encode(['huge' => str_repeat('x', 30000)]);
        $prompt = app(BroadcastDebugPrompt::class)->make($row, null, null);
        $this->assertStringContainsString('Section truncated at 12,000 bytes', $prompt);
        $this->assertLessThan(20000, strlen($prompt));
        $failed = $this->submission([], true);
        $this->get('/current/broadcasts/'.$failed->id)->assertOk()->assertSee('Debug with AI')->assertDontSee('private-message');
    }

    public function test_new_views_respect_authorization_visibility_and_retention(): void
    {
        $a = $this->submission();
        $b = $this->submission();
        $url = '/current/broadcasts/'.$a->id.'/compare?with='.$b->id;
        $this->actingAs(new GenericUser(['id' => 2, 'is_admin' => false]));
        $this->get($url)->assertForbidden();
        $this->get('/current/broadcasts/authorization')->assertForbidden();
        $this->actingAs(new GenericUser(['id' => 1, 'is_admin' => true]));
        config()->set('current.broadcasts.visible', false);
        $this->get($url)->assertNotFound();
        $this->get('/current/broadcasts/authorization')->assertNotFound();
        config()->set('current.broadcasts.visible', true);
        $this->store()->table('broadcasts')->where('id', $b->id)->update(['expires_at' => time() - 1]);
        $this->get($url)->assertNotFound();
        $this->get('/current/broadcasts/'.$a->id.'/compare')->assertOk()->assertDontSee($b->id);
        $this->getJson('/current/broadcasts/'.$a->id.'/compare?with=invalid')->assertUnprocessable();
    }
}
