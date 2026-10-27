<?php

namespace Current\Tests;

use Current\Native\Tags;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Redis;

final class NativeTagsTest extends TestCase
{
    public function test_model_discovery_is_bounded_and_explicit_tags_take_precedence(): void
    {
        $model = new NativeTagModel;
        $model->id = 42;
        $target = new class($model) {
            private Model $record;
            public Model $uninitialized;
            public static ?Model $shared = null;
            public function __construct(Model $record) { $this->record = $record; }
        };
        $target::$shared = (new NativeTagModel)->forceFill(['id' => 99]);
        $this->assertSame([NativeTagModel::class.':42'], app(Tags::class)->for($target));
        $target = new class($model) {
            public function __construct(public Model $record) {}
            public function tags(): array { return ['order:explicit', 'order:explicit']; }
        };
        $this->assertSame(['order:explicit'], app(Tags::class)->for($target));
        $models = new Collection(array_map(fn ($id) => (new NativeTagModel)->forceFill(['id' => $id]), range(1, 120)));
        $this->assertCount(100, app(Tags::class)->for(new class($models) { public function __construct(public Collection $models) {} }));
    }

    public function test_wrappers_discover_the_underlying_tags_without_constructing_listeners(): void
    {
        $mail = new class extends Mailable { public function tags(): array { return ['order:mail']; } };
        $notification = new class extends Notification { public function tags(): array { return ['order:notification']; } };
        $event = new NativeTaggedEvent('first');
        $this->assertSame(['order:mail'], app(Tags::class)->for(new SendQueuedMailable($mail)));
        $this->assertSame(['order:notification'], app(Tags::class)->for(new SendQueuedNotifications([], $notification)));
        $this->assertSame(['order:first'], app(Tags::class)->for(new BroadcastEvent($event)));
        $this->assertSame(['listener:first', 'order:first'], app(Tags::class)->for(new CallQueuedListener(NativeTaggedListener::class, 'handle', [$event])));
        $this->assertSame(['listener:second', 'order:second'], app(Tags::class)->for(new CallQueuedListener(NativeTaggedListener::class, 'handle', [new NativeTaggedEvent('second')])));
    }

    public function test_native_payload_and_history_only_retain_approved_discovered_tags(): void
    {
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        config()->set('current.tag_prefixes', ['order:']);
        config()->set('current.native.silenced_tags', ['order:first']);
        $queue = app('queue')->connection('redis');
        $queue->push(new BroadcastEvent(new NativeTaggedEvent('first')), '', 'lens-demo');
        $payload = json_decode(Redis::lindex('queues:lens-demo', 0), true);
        $this->assertSame(['order:first'], $payload['tags']);
        $this->assertTrue($payload['silenced']);
        $this->assertSame(['order:first'], $this->store()->table('tags')->pluck('tag')->all());
        config()->set('current.tag_prefixes', []);
        $queue->push(new BroadcastEvent(new NativeTaggedEvent('second')), '', 'lens-demo');
        $payload = json_decode(Redis::lindex('queues:lens-demo', 1), true);
        $this->assertSame([], $payload['tags']);
        $this->assertFalse($payload['silenced']);
    }

    public function test_tag_failure_does_not_break_dispatch_or_leak_listener_context(): void
    {
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        config()->set('current.tag_prefixes', ['order:', 'listener:']);
        $queue = app('queue')->connection('redis');
        $queue->push(new CallQueuedListener(NativeThrowingTagListener::class, 'handle', [new NativeTaggedEvent('broken')]), '', 'lens-demo');
        $queue->push(new CallQueuedListener(NativeTaggedListener::class, 'handle', [new NativeTaggedEvent('next')]), '', 'lens-demo');
        $this->assertSame(2, (int) Redis::llen('queues:lens-demo'));
        $failed = json_decode(Redis::lindex('queues:lens-demo', 0), true);
        $next = json_decode(Redis::lindex('queues:lens-demo', 1), true);
        $this->assertArrayNotHasKey('silenced', $failed);
        $this->assertSame(['listener:next', 'order:next'], $next['tags']);
    }
}

class NativeTagModel extends Model {}

class NativeTaggedEvent
{
    public function __construct(public string $id) {}
    public function tags(): array { return ['order:'.$this->id]; }
}

class NativeTaggedListener
{
    public function __construct() { throw new \LogicException('Listener constructor must not run for tag discovery'); }
    public function tags(NativeTaggedEvent $event): array { return ['listener:'.$event->id]; }
    public function handle(NativeTaggedEvent $event): void {}
}

class NativeThrowingTagListener extends NativeTaggedListener
{
    public function tags(NativeTaggedEvent $event): array { throw new \RuntimeException('Tag discovery unavailable'); }
}
