<?php

namespace Current\Tests;

use Current\Events\QueueWaitExceeded;
use Current\Native\WaitMonitor;
use Illuminate\Support\Facades\Redis;

final class NativeWaitMonitorTest extends TestCase
{
    public function test_threshold_events_are_explicit_deduplicated_and_never_inferred_from_unknown_workload(): void
    {
        $events = []; app('events')->listen(QueueWaitExceeded::class, function ($event) use (&$events) { $events[] = $event; });
        $workload = ['available' => true, 'partial' => false, 'rows' => [['connection' => 'redis', 'queues' => ['one'], 'wait' => 90.0]]];
        $monitor = new WaitMonitor;
        $this->assertSame(0, $monitor->inspect($workload));
        config()->set('current.native.waits', ['redis:one' => 60]);
        $this->assertSame(1, $monitor->inspect($workload));
        $this->assertSame(0, (new WaitMonitor)->inspect($workload));
        $this->assertCount(1, $events);
        $this->assertSame(90.0, $events[0]->estimatedSeconds);
        $this->assertSame(['one'], $events[0]->queues);
        $key = 'current:native-wait:'.hash('sha256', 'redis:one');
        $this->assertGreaterThan(0, Redis::ttl($key));
        Redis::del($key);
        $workload['partial'] = true;
        $this->assertSame(0, $monitor->inspect($workload));
        $workload['partial'] = false; $workload['rows'][0]['wait'] = null;
        $this->assertSame(0, $monitor->inspect($workload));
        $workload['rows'][0]['wait'] = 60.0;
        $this->assertSame(0, $monitor->inspect($workload));
        $workload['rows'][0]['wait'] = 90.0;
        app('events')->listen(QueueWaitExceeded::class, fn () => throw new \RuntimeException('Notification transport failure'));
        $this->assertSame(0, $monitor->inspect($workload));
        $this->assertSame(0, $monitor->inspect($workload));
        $this->assertCount(2, $events); // Failed notification is not sent again automatically.
    }
}
