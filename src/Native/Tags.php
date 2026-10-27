<?php

namespace Current\Native;

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use ReflectionClass;
use stdClass;

/**
 * Bounded adaptation of Horizon's tag discovery (MIT; see LICENSE-LARAVEL.md).
 * Discover from the dispatch object only, never unserialize retained payloads.
 */
final class Tags
{
    public function for(object $job): array
    {
        if ($job instanceof CallQueuedListener) {
            $event = isset($job->data[0]) && is_object($job->data[0]) ? $job->data[0] : new stdClass;
            $listener = (new ReflectionClass($job->class))->newInstanceWithoutConstructor();
            // Event context stays local, including when application tags() throws.
            return $this->bounded(array_merge($this->target($listener, $event), $this->target($event, $event)));
        }

        $target = match (true) {
            $job instanceof BroadcastEvent => $job->event,
            $job instanceof SendQueuedMailable => $job->mailable,
            $job instanceof SendQueuedNotifications => $job->notification,
            default => $job,
        };

        return $this->target($target);
    }

    private function target(object $target, ?object $event = null): array
    {
        if (is_callable([$target, 'tags'])) {
            $tags = $target->tags($event);
            if (is_array($tags) && $tags) { return $this->bounded($tags); }
        }

        $tags = [];
        $examined = 0;
        foreach (array_slice((new ReflectionClass($target))->getProperties(), 0, 100) as $property) {
            if ($property->isStatic() || ! $property->isInitialized($target)) { continue; }
            $value = $property->getValue($target);
            $models = $value instanceof Model ? [$value] : ($value instanceof Collection ? $value->take(100)->all() : []);
            foreach ($models as $model) {
                if (++$examined > 100) { return $this->bounded($tags); }
                if (! $model instanceof Model) { continue; }
                $key = $model->getKey();
                if (is_string($key) || is_int($key)) { $tags[] = $model::class.':'.$key; }
                if (count($tags) >= 100) { return $this->bounded($tags); }
            }
        }

        return $this->bounded($tags);
    }

    private function bounded(array $tags): array
    {
        return array_values(array_unique(array_filter(array_slice($tags, 0, 100), 'is_string')));
    }
}
