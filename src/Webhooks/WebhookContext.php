<?php

namespace Current\Webhooks;

use Current\Support\JobContext;
use Throwable;

final class WebhookContext
{
    public static function capture(mixed $provided): array
    {
        $result = ['event_id' => null, 'event_type' => null];
        foreach (['payload', 'metadata', 'response'] as $field) {
            $result[$field.'_json'] = null;
            $result[$field.'_state'] = 'not_captured';
        }
        if (! config('current.webhooks.capture_context', false)) {
            return $result;
        }
        try {
            $provided = is_callable($provided) ? $provided() : $provided;
            if (! is_array($provided)) {
                return $result;
            }
            $encoder = new JobContext;
            foreach (['event_id', 'event_type'] as $field) {
                if (isset($provided[$field]) && is_string($provided[$field])) {
                    [$json, $state] = $encoder->encode($provided[$field]);
                    $value = $json !== null ? json_decode($json, true) : null;
                    if ($state === 'captured' && is_string($value) && strlen($value) <= 160 && ! str_contains($value, '[REDACTED]')) {
                        $result[$field] = $value;
                    }
                }
            }
            foreach (['payload', 'metadata', 'response'] as $field) {
                if (array_key_exists($field, $provided)) {
                    [$result[$field.'_json'], $result[$field.'_state']] = $encoder->encode($provided[$field]);
                }
            }
        } catch (Throwable) {
            foreach (['payload', 'metadata', 'response'] as $field) {
                $result[$field.'_json'] = null;
                $result[$field.'_state'] = 'failed';
            }
        }

        return $result;
    }
}
