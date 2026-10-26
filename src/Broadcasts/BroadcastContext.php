<?php

namespace HorizonLens\Broadcasts;

use HorizonLens\Support\JobContext;
use Throwable;

final class BroadcastContext
{
    public static function capture(string $event, array $channels, array $payload): array
    {
        $result = [];
        foreach (['payload', 'metadata', 'exact_channels'] as $field) {
            $result[$field.'_json'] = null;
            $result[$field.'_state'] = 'not_captured';
        }
        $encoder = new JobContext;
        if (config('horizon-lens.broadcasts.capture_channels', false)) {
            try {
                [$result['exact_channels_json'], $result['exact_channels_state']] = $encoder->encode($channels);
            } catch (Throwable) {
                $result['exact_channels_state'] = 'failed';
            }
        }
        if ($resolver = config('horizon-lens.broadcasts.context')) {
            try {
                $provided = $resolver($event, $channels, $payload);
                if (! is_array($provided)) {
                    throw new \UnexpectedValueException;
                }
                foreach (['payload', 'metadata'] as $field) {
                    if (array_key_exists($field, $provided)) {
                        [$result[$field.'_json'], $result[$field.'_state']] = $encoder->encode($provided[$field]);
                    }
                }
            } catch (Throwable) {
                foreach (['payload', 'metadata'] as $field) {
                    $result[$field.'_json'] = null;
                    $result[$field.'_state'] = 'failed';
                }
            }
        }

        return $result;
    }
}
