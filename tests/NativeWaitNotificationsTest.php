<?php

namespace Current\Tests;

use Current\Events\QueueWaitExceeded;
use Current\Native\WaitMonitor;
use Current\Native\WaitNotifications;
use Current\Notifications\QueueWaitExceeded as WaitNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;

final class NativeWaitNotificationsTest extends TestCase
{
    public function test_delivery_requires_explicit_opt_in_and_the_enabled_native_backend(): void
    {
        Notification::fake();
        config()->set('current.execution_backend', 'native');
        config()->set('current.native.notifications.mail', 'operator@example.test');
        $listener = new WaitNotifications;
        $listener->handle($this->observation());
        config()->set('current.native.notifications.enabled', true);
        config()->set('current.execution_backend', 'horizon');
        $listener->handle($this->observation());
        config()->set('current.execution_backend', 'native');
        config()->set('current.enabled', false);
        $listener->handle($this->observation());
        Notification::assertNothingSent();

        config()->set('current.enabled', true);
        $listener->handle($this->observation());
        Notification::assertSentOnDemand(WaitNotification::class, function ($notification, $channels, $notifiable) {
            return $channels === ['mail'] && $notifiable->routes === ['mail' => 'operator@example.test'];
        });
    }

    public function test_on_demand_routes_use_separate_immediate_channel_sends(): void
    {
        $this->configureRoutes();
        $state = $this->fakeChannels();
        (new WaitNotifications)->handle($this->observation());
        $this->assertSame(['mail', 'slack', 'vonage'], $state->attempted);
        $this->assertSame([
            ['mail' => ['one@example.test', 'two@example.test']],
            ['slack' => 'https://hooks.slack.com/services/synthetic/secret'],
            ['vonage' => '+15555550123'],
        ], array_column($state->sent, 'routes'));
        foreach ($state->sent as $send) {
            $this->assertSame([$send['channel']], $send['notification']->via(new AnonymousNotifiable));
            $this->assertNotInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $send['notification']);
            $this->assertSame(91.5, $send['notification']->observation->estimatedSeconds);
        }
    }

    public function test_failed_delivery_preserves_other_routes_event_listeners_and_shared_cooldown(): void
    {
        $this->configureRoutes();
        $state = $this->fakeChannels(['mail']);
        $log = $this->captureWarnings();
        config()->set('current.native.waits', ['redis:one' => 60]);
        $events = [];
        // The provider's built-in listener runs before this application listener.
        app('events')->listen(QueueWaitExceeded::class, function ($event) use (&$events) { $events[] = $event; });
        $workload = ['available' => true, 'partial' => false, 'rows' => [
            ['connection' => 'redis', 'queues' => ['one'], 'wait' => 91.5],
        ]];
        $this->assertSame(1, (new WaitMonitor)->inspect($workload));
        $this->assertSame(0, (new WaitMonitor)->inspect($workload));
        $this->assertCount(1, $events);
        $this->assertSame(['mail', 'slack', 'vonage'], $state->attempted);
        $this->assertSame(['slack', 'vonage'], array_column($state->sent, 'channel'));
        $this->assertCount(1, $log->warnings);
        $serialized = json_encode($log->warnings);
        $this->assertStringNotContainsString('synthetic/secret', $serialized);
        $this->assertStringNotContainsString('one@example.test', $serialized);
        $this->assertStringNotContainsString('transport-secret', $serialized);
        $key = 'current:native-wait:'.hash('sha256', 'redis:one');
        $this->assertGreaterThan(0, Redis::ttl($key));
        Redis::del($key);
        $this->assertSame(1, (new WaitMonitor)->inspect($workload));
        $this->assertCount(2, $events);
        $this->assertCount(6, $state->attempted);
    }

    public function test_invalid_routes_are_rejected_independently_without_disclosing_destinations(): void
    {
        $this->configureRoutes();
        $state = $this->fakeChannels();
        $log = $this->captureWarnings();
        config()->set('current.native.notifications.slack', 'http://example.test/private-secret');
        (new WaitNotifications)->handle($this->observation());
        $this->assertSame(['mail', 'vonage'], $state->attempted);
        $this->assertCount(1, $log->warnings);

        config()->set('current.native.notifications.mail', array_fill(0, 11, 'operator@example.test'));
        config()->set('current.native.notifications.sms', ['not-a-phone-secret']);
        (new WaitNotifications)->handle($this->observation());
        $this->assertCount(2, $state->attempted);
        $this->assertCount(4, $log->warnings);
        $this->assertStringNotContainsString('private-secret', json_encode($log->warnings));
        $this->assertStringNotContainsString('not-a-phone-secret', json_encode($log->warnings));
    }

    public function test_broken_diagnostics_and_unavailable_channel_do_not_interrupt_delivery(): void
    {
        $this->configureRoutes();
        config()->set('current.native.notifications.slack', 'invalid-secret');
        app()->instance('log', new class {
            public function warning(...$arguments): void { throw new \RuntimeException('broken logger'); }
        });
        $state = (object) ['attempted' => [], 'sent' => []];
        app(ChannelManager::class)->extend('mail', fn () => new NativeWaitTestChannel('mail', $state));
        // Resolve the SMS adapter to a failing local channel even if an optional package is installed.
        app(ChannelManager::class)->extend('vonage', fn () => throw new \RuntimeException('adapter unavailable'));
        (new WaitNotifications)->handle($this->observation());
        $this->assertSame(['mail'], $state->attempted);
        $this->assertCount(1, $state->sent);
    }

    public function test_invalid_slack_channel_cannot_disable_other_routes(): void
    {
        $this->configureRoutes();
        config()->set('current.native.notifications.slack_channel', ['invalid-channel']);
        $state = $this->fakeChannels();
        $this->captureWarnings();
        (new WaitNotifications)->handle($this->observation());
        $this->assertSame(['mail', 'vonage'], $state->attempted);
    }

    public function test_missing_optional_message_classes_fail_without_exposing_destinations(): void
    {
        if (class_exists(\Illuminate\Notifications\Messages\VonageMessage::class)
            || class_exists(\Illuminate\Notifications\Messages\SlackMessage::class)) {
            $this->markTestSkipped('This consumer includes optional channel message classes.');
        }
        $event = $this->observation();
        foreach (['slack', 'vonage'] as $channel) {
            try {
                $notification = new WaitNotification($event, $channel);
                $notifiable = (new AnonymousNotifiable)->route('slack', 'https://hooks.slack.com/services/synthetic/secret');
                $channel === 'slack' ? $notification->toSlack($notifiable) : $notification->toVonage($notifiable);
                $this->fail('Missing optional message class must be reported.');
            } catch (\RuntimeException $exception) {
                $this->assertStringNotContainsString('synthetic/secret', $exception->getMessage());
                $this->assertStringContainsString('Install the Laravel', $exception->getMessage());
            }
        }
    }

    public function test_mail_uses_laravels_real_array_transport_and_describes_estimates(): void
    {
        // The minimal package fixture has no application composer.json for Blade component discovery.
        (new \ReflectionProperty($this->app, 'namespace'))->setValue($this->app, 'Current\\Prototype\\');
        $this->configureRoutes();
        config()->set('current.native.notifications.mail', 'operator@example.test');
        config()->set('current.native.notifications.slack', null);
        config()->set('current.native.notifications.sms', null);
        config()->set('app.name', 'Synthetic app');
        config()->set('mail.default', 'array');
        config()->set('mail.mailers.array', ['transport' => 'array']);
        config()->set('mail.from', ['address' => 'current@example.test', 'name' => 'Current']);
        (new WaitNotifications)->handle($this->observation());
        $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $message = $messages[0]->getOriginalMessage();
        $this->assertSame('operator@example.test', $message->getTo()[0]->getAddress());
        $this->assertSame('Synthetic app: Queue clearance estimate exceeded', $message->getSubject());
        $this->assertStringContainsString('estimated clearance time of 91.5 seconds', $message->getTextBody());
        $this->assertStringContainsString('threshold: 60 seconds', $message->getTextBody());
        $this->assertStringContainsString('not measured job latency', $message->getTextBody());
    }

    public function test_optional_slack_and_vonage_message_contracts(): void
    {
        if (! class_exists(\Illuminate\Notifications\Messages\SlackMessage::class)
            || ! class_exists(\Illuminate\Notifications\Slack\SlackMessage::class)
            || ! class_exists(\Illuminate\Notifications\Messages\VonageMessage::class)) {
            $this->markTestSkipped('Optional Laravel Slack/Vonage packages are not installed in this consumer.');
        }
        config()->set('app.name', 'Synthetic <app>');
        $event = $this->observation();
        $notification = new WaitNotification($event, 'slack', '#operations');
        $webhook = $notification->toSlack((new AnonymousNotifiable)->route('slack', 'https://hooks.slack.com/services/synthetic'));
        $this->assertInstanceOf(\Illuminate\Notifications\Messages\SlackMessage::class, $webhook);
        $this->assertSame('#operations', $webhook->channel);
        $this->assertStringContainsString('Synthetic &lt;app&gt;', $webhook->content);
        $channel = $notification->toSlack((new AnonymousNotifiable)->route('slack', 'C123456789'));
        $this->assertInstanceOf(\Illuminate\Notifications\Slack\SlackMessage::class, $channel);
        $this->assertStringContainsString('estimated clearance time of 91.5 seconds', $channel->toArray()['text']);
        $this->assertFalse($channel->toArray()['mrkdwn']);
        $sms = (new WaitNotification($event, 'vonage'))->toVonage(new AnonymousNotifiable);
        $this->assertInstanceOf(\Illuminate\Notifications\Messages\VonageMessage::class, $sms);
        $this->assertStringContainsString('estimated clearance time of 91.5 seconds', $sms->content);
    }

    public function test_optional_real_slack_routes_use_only_fake_http_transports(): void
    {
        if (! class_exists(\Illuminate\Notifications\SlackNotificationRouterChannel::class)) {
            $this->markTestSkipped('Optional Laravel Slack channel package is not installed in this consumer.');
        }
        $this->configureRoutes();
        config()->set('current.native.notifications.mail', null);
        config()->set('current.native.notifications.sms', null);
        config()->set('services.slack.notifications.bot_user_oauth_token', 'synthetic-token');
        $history = [];
        $handler = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(200)]));
        $handler->push(\GuzzleHttp\Middleware::history($history));
        app()->instance(\Illuminate\Notifications\Channels\SlackWebhookChannel::class,
            new \Illuminate\Notifications\Channels\SlackWebhookChannel(new \GuzzleHttp\Client(['handler' => $handler])));
        app(ChannelManager::class)->extend('slack', fn () => new \Illuminate\Notifications\SlackNotificationRouterChannel(app()));
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake(['https://slack.com/api/chat.postMessage' => \Illuminate\Support\Facades\Http::response(['ok' => true])]);

        (new WaitNotifications)->handle($this->observation());
        $this->assertCount(1, $history);
        $this->assertSame('https://hooks.slack.com/services/synthetic/secret', (string) $history[0]['request']->getUri());
        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('#operations', $payload['channel']);
        $this->assertStringContainsString('estimated clearance time of 91.5 seconds', $payload['text']);

        config()->set('current.native.notifications.slack', 'C123456789');
        (new WaitNotifications)->handle($this->observation());
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
            && $request['channel'] === 'C123456789' && $request->hasHeader('Authorization', 'Bearer synthetic-token')
            && str_contains($request['text'], 'estimated clearance time of 91.5 seconds'));
        $this->assertCount(1, $history); // The channel-ID route did not reuse the webhook.
    }

    public function test_optional_real_vonage_channel_uses_a_fake_sdk_transport(): void
    {
        if (! class_exists(\Illuminate\Notifications\Channels\VonageSmsChannel::class)) {
            $this->markTestSkipped('Optional Laravel Vonage channel package is not installed in this consumer.');
        }
        $this->configureRoutes();
        config()->set('current.native.notifications.mail', null);
        config()->set('current.native.notifications.slack', null);
        $sms = $this->createMock(\Vonage\SMS\Client::class);
        $sms->expects($this->once())->method('send')->with($this->callback(fn ($message) =>
            $message->getTo() === '+15555550123' && $message->getFrom() === 'Synthetic'
            && str_contains($message->getMessage(), 'estimated clearance time of 91.5 seconds')
        ))->willReturn($this->createStub(\Vonage\SMS\Collection::class));
        $client = $this->createMock(\Vonage\Client::class);
        $client->expects($this->once())->method('__call')->with('sms', [])->willReturn($sms);
        app(ChannelManager::class)->extend('vonage', fn () => new \Illuminate\Notifications\Channels\VonageSmsChannel($client, 'Synthetic'));
        (new WaitNotifications)->handle($this->observation());
    }

    private function configureRoutes(): void
    {
        config()->set('current.execution_backend', 'native');
        config()->set('current.native.notifications', [
            'enabled' => true, 'mail' => ['one@example.test', 'two@example.test'],
            'slack' => 'https://hooks.slack.com/services/synthetic/secret', 'slack_channel' => '#operations',
            'sms' => '+15555550123',
        ]);
    }

    private function fakeChannels(array $fail = []): object
    {
        $state = (object) ['attempted' => [], 'sent' => []];
        foreach (['mail', 'slack', 'vonage'] as $channel) {
            app(ChannelManager::class)->extend($channel, fn () => new NativeWaitTestChannel($channel, $state, in_array($channel, $fail, true)));
        }
        return $state;
    }

    private function captureWarnings(): object
    {
        $log = new class {
            public array $warnings = [];
            public function warning(string $message, array $context): void { $this->warnings[] = [$message, $context]; }
        };
        app()->instance('log', $log);
        return $log;
    }

    private function observation(): QueueWaitExceeded
    {
        return new QueueWaitExceeded('redis', ['one'], 91.5, 60, time());
    }
}

final class NativeWaitTestChannel
{
    public function __construct(private string $channel, private object $state, private bool $fail = false) {}

    public function send($notifiable, $notification): void
    {
        $this->state->attempted[] = $this->channel;
        if ($this->fail) { throw new \RuntimeException('transport-secret'); }
        $this->state->sent[] = ['channel' => $this->channel, 'routes' => $notifiable->routes, 'notification' => $notification];
    }
}
