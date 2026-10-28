<?php

namespace Current\Tests;

use Current\Native\Silencing;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Gate;

final class NativeSilencingTest extends TestCase
{
    public function test_underlying_wrapper_classes_match_horizon_without_constructing_or_delivering_them(): void
    {
        $wrappers = [
            [new BroadcastEvent(new SilenceFixtureEvent), SilenceFixtureEvent::class],
            [new CallQueuedListener(SilenceFixtureListener::class, 'handle', [new SilenceFixtureEvent]), SilenceFixtureListener::class],
            [new SendQueuedMailable(new SilenceFixtureMail), SilenceFixtureMail::class],
            [new SendQueuedNotifications([], new SilenceFixtureNotification), SilenceFixtureNotification::class],
        ];
        foreach ($wrappers as [$wrapper, $underlying]) {
            config()->set('current.native.silenced', [$underlying]); config()->set('horizon.silenced', [$underlying]);
            $this->assertTrue(app(Silencing::class)->for($wrapper, []));
            if (class_exists(\Laravel\Horizon\JobPayload::class)) { $this->assertTrue((new \Laravel\Horizon\JobPayload('{}'))->prepare($wrapper)->isSilenced()); }
            config()->set('current.native.silenced', [get_class($wrapper)]); config()->set('horizon.silenced', [get_class($wrapper)]);
            $this->assertFalse(app(Silencing::class)->for($wrapper, []));
            if (class_exists(\Laravel\Horizon\JobPayload::class)) { $this->assertFalse((new \Laravel\Horizon\JobPayload('{}'))->prepare($wrapper)->isSilenced()); }
        }
    }

    public function test_native_and_optional_horizon_markers_are_recognized_on_the_underlying_target(): void
    {
        $this->assertTrue(app(Silencing::class)->for(new SilenceFixtureJob, []));
        $this->assertTrue(app(Silencing::class)->for(new BroadcastEvent(new SilenceFixtureMarkedEvent), []));
        if (class_exists(SilenceFixtureHorizonMarkedEvent::class)) { $this->assertTrue(app(Silencing::class)->for(new BroadcastEvent(new SilenceFixtureHorizonMarkedEvent), [])); }
        $this->assertTrue(app(Silencing::class)->for(new CallQueuedListener(SilenceFixtureMarkedListener::class, 'handle', []), []));
        $this->assertFalse(app(Silencing::class)->for(new SilenceFixtureEvent, []));
    }

    public function test_private_tag_can_classify_without_persisting_it_and_execution_is_unchanged(): void
    {
        config()->set('current.execution_backend', 'native'); config()->set('queue.connections.redis.driver', 'current-redis');
        Gate::define('viewCurrent', fn ($user) => $user->is_admin);
        config()->set('current.tag_prefixes', ['public:']); config()->set('current.native.silenced_tags', ['private:classification']);
        dispatch(new SilenceFixtureTaggedJob);
        $reserved = Queue::connection()->pop('lens-demo');
        $this->assertTrue($reserved->payload()['silenced']); $this->assertSame([], $reserved->payload()['tags']);
        $this->assertSame(0, $this->store()->table('tags')->count());
        // Release then let Laravel's real worker execute the same queue payload.
        $reserved->release(0); $this->work();
        $job = $this->store()->table('jobs')->sole();
        $this->assertSame('completed', $job->status); $this->assertSame(1, (int) $job->silenced);
        $this->get('/current/silenced')->assertOk()->assertSee($job->external_id)->assertDontSee('private:classification');
        $this->get('/current/jobs/completed')->assertOk()->assertDontSee($job->external_id);
    }

    public function test_payload_hook_tags_merge_with_discovered_tags_before_private_classification(): void
    {
        config()->set('current.execution_backend', 'native'); config()->set('queue.connections.redis.driver', 'current-redis');
        config()->set('current.tag_prefixes', ['public:']); config()->set('current.native.silenced_tags', ['private:from-hook']);
        Queue::createPayloadUsing(fn () => ['tags' => ['public:from-hook', 'private:from-hook']]);
        try {
            dispatch(new SilenceFixtureTaggedJob);
            $job = Queue::connection()->pop('lens-demo');
            $this->assertSame(['public:from-hook'], $job->payload()['tags']);
            $this->assertTrue($job->payload()['silenced']);
            $this->assertSame(['public:from-hook'], $this->store()->table('tags')->pluck('tag')->all());
        } finally { Queue::createPayloadUsing(null); }
    }
}

class SilenceFixtureEvent { public function tags(): array { return []; } }
class SilenceFixtureMarkedEvent implements \Current\Contracts\Silenced {}
if (interface_exists(\Laravel\Horizon\Contracts\Silenced::class)) {
    class SilenceFixtureHorizonMarkedEvent implements \Laravel\Horizon\Contracts\Silenced {}
}
class SilenceFixtureListener
{
    public function __construct() { throw new \LogicException('Listener constructor must not run.'); }
    public function handle(): void {}
}
class SilenceFixtureMarkedListener extends SilenceFixtureListener implements \Current\Contracts\Silenced {}
class SilenceFixtureMail extends Mailable {}
class SilenceFixtureNotification extends Notification {}
class SilenceFixtureJob implements ShouldQueue, \Current\Contracts\Silenced
{
    use \Illuminate\Foundation\Queue\Queueable;
    public function handle(): void {}
}
class SilenceFixtureTaggedJob implements ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;
    public function __construct() { $this->onQueue('lens-demo'); }
    public function tags(): array { return ['private:classification']; }
    public function handle(): void {}
}
