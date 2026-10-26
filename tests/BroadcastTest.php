<?php

namespace Current\Tests;

use Current\Broadcasts\BroadcastRecorder;
use Current\Broadcasts\BroadcastIntegration;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Schema;

final class BroadcastTest extends TestCase
{
    public function test_navigation_detects_later_configuration_and_overrides_without_a_stored_install_flag(): void
    {
        config()->set('broadcasting.default', 'log');
        $this->get('/current')->assertOk()->assertDontSee('href="'.route('current.broadcasts').'"', false);
        config()->set('broadcasting.connections.remote-reverb.driver', 'reverb');
        config()->set('broadcasting.default', 'remote-reverb');
        $this->assertTrue(BroadcastIntegration::visible());
        $this->get('/current/broadcasts')->assertOk()->assertSee('Broadcast observation is available');
        config()->set('current.broadcasts.visible', false);
        $this->get('/current/broadcasts')->assertNotFound();
        config()->set('current.broadcasts.visible', true);
        $this->get('/current/broadcasts')->assertOk();
    }

    public function test_approved_names_patterns_retention_auth_and_unknown_are_separate(): void
    {
        config()->set('current.broadcasts', ['visible' => true, 'enabled' => true, 'events' => ['OrderUpdated'], 'channels' => ['private-orders.*']]);
        $recorder = app(BroadcastRecorder::class);
        $this->assertNull($recorder->start('reverb', ['private-orders.123'], 'NotApproved'));
        $this->assertNull($recorder->start('reverb', ['private-other.123'], 'OrderUpdated'));
        $context = $recorder->start('reverb', ['private-orders.123', 'private-other.456'], 'OrderUpdated');
        $row = $this->store()->table('broadcasts')->first();
        $this->assertSame('unknown', $row->outcome);
        $this->assertSame('["private-orders.*"]', $row->channels_json);
        $this->get('/current/broadcasts?channel=private-orders.*')->assertOk()->assertSee($row->id);
        $this->get('/current/broadcasts?channel=private-other.*')->assertOk()->assertSee('No broadcast submissions observed');
        $this->get('/current/broadcasts?outcome=unknown')->assertOk()->assertSee($row->id);
        BroadcastRecorder::finish($context, new \RuntimeException('private-error'));
        $row = $this->store()->table('broadcasts')->first();
        $this->assertSame('failed', $row->outcome);
        $this->assertStringNotContainsString('private-error', json_encode($row));
        $this->actingAs(new GenericUser(['id' => 2, 'is_admin' => false]));
        $this->get('/current/broadcasts')->assertForbidden();
        $this->get('/current/broadcasts/'.$row->id)->assertForbidden();
        $this->actingAs(new GenericUser(['id' => 1, 'is_admin' => true]));
        $this->store()->table('broadcasts')->update(['expires_at' => time() - 1]);
        $this->get('/current/broadcasts/'.$row->id)->assertNotFound();
        $this->assertSame(1, $recorder->prune());
        Schema::connection('lens')->drop('lens_broadcasts');
        BroadcastRecorder::finish($context); // failed completion writes do not escape.
    }
}
