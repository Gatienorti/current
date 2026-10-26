<?php

namespace HorizonLens\Broadcasts;

final class SubmissionComparison
{
    public function compare(object $left, object $right): array
    {
        $rows = [];
        $limited = false;
        foreach (['event', 'connection', 'outcome', 'exception_class', 'exception_message'] as $field) {
            $this->diff($left->$field, $right->$field, '/'.$field, $rows, $limited);
        }
        foreach (['channels_json', 'channel_types_json'] as $field) {
            $this->diff(json_decode($left->$field ?? 'null'), json_decode($right->$field ?? 'null'), '/'.$field, $rows, $limited);
        }
        $coverage = [];
        foreach (['payload', 'metadata', 'exact_channels'] as $field) {
            $a = $left->{$field.'_state'} ?? 'not_captured';
            $b = $right->{$field.'_state'} ?? 'not_captured';
            $coverage[$field] = ['left' => $a, 'right' => $b, 'compared' => false];
            if ($a !== 'captured' || $b !== 'captured') {
                continue;
            }
            try {
                $one = json_decode($left->{$field.'_json'}, false, 64, JSON_THROW_ON_ERROR);
                $two = json_decode($right->{$field.'_json'}, false, 64, JSON_THROW_ON_ERROR);
                $coverage[$field]['compared'] = true;
                $this->diff($one, $two, '/'.$field, $rows, $limited);
            } catch (\Throwable) {
                $coverage[$field]['note'] = 'Stored snapshot unavailable or invalid';
            }
        }

        return compact('rows', 'coverage', 'limited');
    }

    private function diff(mixed $left, mixed $right, string $path, array &$rows, bool &$limited, int $depth = 0): void
    {
        if (count($rows) >= 200 || $depth > 12) {
            $limited = true;

            return;
        }
        $sameContainer = (is_array($left) && is_array($right)) || ($left instanceof \stdClass && $right instanceof \stdClass);
        if ($sameContainer) {
            $a = (array) $left;
            $b = (array) $right;
            foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $key) {
                $pointer = $path.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key);
                if (! array_key_exists($key, $a) || ! array_key_exists($key, $b)) {
                    if (count($rows) >= 200) {
                        $limited = true;

                        return;
                    }
                    $rows[] = ['path' => $pointer, 'kind' => array_key_exists($key, $a) ? 'Removed' : 'Added',
                        'left' => array_key_exists($key, $a) ? $this->display($a[$key]) : 'Missing from captured snapshot',
                        'right' => array_key_exists($key, $b) ? $this->display($b[$key]) : 'Missing from captured snapshot'];
                } else {
                    $this->diff($a[$key], $b[$key], $pointer, $rows, $limited, $depth + 1);
                }
            }
        } elseif ($left !== $right) {
            $rows[] = ['path' => $path, 'kind' => 'Changed', 'left' => $this->display($left), 'right' => $this->display($right)];
        }
    }

    private function display(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return strlen($json) > 2000 ? mb_strcut($json, 0, 2000, 'UTF-8').' [value truncated]' : $json;
    }
}
