<?php

namespace Current\Native;

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;

/** Horizon-compatible classification. Raw discovered tags remain transient. */
final class Silencing
{
    public function for(object $job, array $tags): bool
    {
        $underlying = match (true) {
            $job instanceof BroadcastEvent => $job->event,
            $job instanceof CallQueuedListener => $job->class,
            $job instanceof SendQueuedMailable => $job->mailable,
            $job instanceof SendQueuedNotifications => $job->notification,
            default => $job,
        };
        $class = is_string($underlying) ? $underlying : get_class($underlying);
        return in_array($class, (array) config('current.native.silenced', []), true)
            || is_a($class, \Current\Contracts\Silenced::class, true)
            || is_a($class, 'Laravel\\Horizon\\Contracts\\Silenced', true)
            || count(array_intersect($tags, (array) config('current.native.silenced_tags', []))) > 0;
    }
}
