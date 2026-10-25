<?php

namespace HorizonLens\Support;

use Throwable;

final class JobContext
{
    public function capture(object $job, array $payload = []): array
    {
        $result = ['arguments_json' => null, 'metadata_json' => null, 'arguments_state' => 'not_captured', 'metadata_state' => 'not_captured'];
        $provided = [];
        if ($resolver = config('horizon-lens.job_context')) {
            try {
                $provided = $resolver($job);
                if (! is_array($provided)) {
                    throw new \UnexpectedValueException;
                }
            } catch (Throwable) {
                return array_replace($result, ['arguments_state' => 'failed', 'metadata_state' => 'failed']);
            }
        }
        foreach (['arguments' => 'lensArguments', 'metadata' => 'lensMetadata'] as $field => $legacyMethod) {
            $method = $field === 'arguments' ? 'currentArguments' : 'currentMetadata';
            if (! is_callable([$job, $method])) {
                $method = $legacyMethod;
            }
            if (! array_key_exists($field, $provided) && ! is_callable([$job, $method])) {
                if (! config('horizon-lens.capture_payload', true) || $payload === []) {
                    continue;
                }
                try {
                    if ($field === 'arguments') {
                        $command = $payload['data']['command'] ?? null;
                        if (! is_string($command) || ! str_starts_with($command, 'O:')) {
                            $result['arguments_state'] = 'unavailable';

                            continue;
                        }
                        $value = (new PayloadData($command))->read();
                    } else {
                        $value = array_intersect_key($payload, array_flip(['uuid', 'displayName', 'maxTries', 'maxExceptions', 'failOnTimeout', 'backoff', 'timeout', 'retryUntil', 'createdAt', 'retry_of', 'silenced']));
                    }
                    [$result[$field.'_json'], $result[$field.'_state']] = $this->encode($value);
                } catch (\LengthException) {
                    $result[$field.'_state'] = 'size_limit';
                } catch (Throwable) {
                    $result[$field.'_state'] = 'unavailable';
                }

                continue;
            }
            try {
                $value = array_key_exists($field, $provided) ? $provided[$field] : $job->$method();
                [$json, $state] = $this->encode($value);
                $result[$field.'_json'] = $json;
                $result[$field.'_state'] = $state;
            } catch (Throwable) {
                // User callbacks and invalid values cannot interfere with dispatch.
                $result[$field.'_state'] = 'failed';
            }
        }

        return $result;
    }

    private function encode(mixed $value): array
    {
        $remaining = max(1, min(1000, (int) config('horizon-lens.context_max_items', 100)));
        $limited = false;
        $clean = $this->clean($value, 0, $remaining, $limited);
        $json = json_encode($clean, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $bytes = max(256, min(65536, (int) config('horizon-lens.context_max_bytes', 16384)));
        if (strlen($json) > $bytes) {
            return [null, 'size_limit'];
        }

        return [$json, $limited ? 'limited' : 'captured'];
    }

    private function clean(mixed $value, int $depth, int &$remaining, bool &$limited): mixed
    {
        if ($depth > max(1, min(10, (int) config('horizon-lens.context_max_depth', 5)))) {
            $limited = true;

            return '[DEPTH LIMIT]';
        }
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                if (--$remaining < 0) {
                    $limited = true;
                    $clean['__lens_truncated'] = 'Item limit reached';
                    break;
                }
                $name = preg_replace('/[^a-z0-9]/', '', strtolower((string) $key));
                $key = is_string($key) ? mb_substr($key, 0, 120) : $key;
                if (preg_match('/password|passwd|passphrase|secret|token|authorization|cookie|credentials|apikey|privatekey/', $name)) {
                    $clean[$key] = '[REDACTED]';
                } else {
                    $clean[$key] = $this->clean($item, $depth + 1, $remaining, $limited);
                }
            }

            return $clean;
        }
        if (is_string($value)) {
            $length = max(32, min(8192, (int) config('horizon-lens.context_max_string', 2048)));
            $limited = $limited || mb_strlen($value) > $length;
            // Redact before truncating so an assignment cannot retain a partial credential.
            $value = preg_replace('/\b(password|passwd|token|secret|api[_-]?key|authorization)\s*[:=]\s*(?:Bearer\s+)?[^\s,;]+/i', '$1=[REDACTED]', $value);

            return mb_substr($value, 0, $length);
        }
        if (is_object($value) || is_resource($value) || (is_float($value) && ! is_finite($value))) {
            $limited = true;

            return '[UNSUPPORTED VALUE OMITTED]';
        }

        return $value;
    }
}
