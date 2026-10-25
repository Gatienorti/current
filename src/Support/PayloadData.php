<?php

namespace HorizonLens\Support;

/** Reads PHP's data format only. Never instantiates classes or resolves references. */
final class PayloadData
{
    private int $offset = 0;

    private int $nodes = 0;

    public function __construct(private string $input) {}

    public function read(): mixed
    {
        if (strlen($this->input) > 262144) {
            throw new \LengthException('Payload limit');
        }
        $value = $this->value(0);
        if ($this->offset !== strlen($this->input)) {
            throw new \UnexpectedValueException('Trailing data');
        }

        return $value;
    }

    private function take(string $literal): void
    {
        if (substr($this->input, $this->offset, strlen($literal)) !== $literal) {
            throw new \UnexpectedValueException('Invalid data');
        }
        $this->offset += strlen($literal);
    }

    private function number(string $end): int
    {
        $stop = strpos($this->input, $end, $this->offset);
        $text = $stop === false ? '' : substr($this->input, $this->offset, $stop - $this->offset);
        if (! preg_match('/^\d{1,9}$/D', $text)) {
            throw new \UnexpectedValueException('Invalid length');
        }
        $this->offset = $stop + strlen($end);

        return (int) $text;
    }

    private function quoted(): string
    {
        $length = $this->number(':');
        $this->take('"');
        if ($length > strlen($this->input) - $this->offset) {
            throw new \UnexpectedValueException('Invalid string');
        }
        $value = substr($this->input, $this->offset, $length);
        $this->offset += $length;
        $this->take('"');

        return $value;
    }

    private function value(int $depth): mixed
    {
        if (++$this->nodes > 4000 || $depth > 20) {
            throw new \LengthException('Structure limit');
        }
        $type = $this->input[$this->offset++] ?? '';
        if ($type === 'N') {
            $this->take(';');

            return null;
        }
        $this->take(':');
        if ($type === 's') {
            $value = $this->quoted();
            $this->take(';');

            return $value;
        }
        if (in_array($type, ['b', 'i', 'd', 'r', 'R'], true)) {
            $end = strpos($this->input, ';', $this->offset);
            $text = $end === false ? '' : substr($this->input, $this->offset, $end - $this->offset);
            if (! preg_match('/^-?(?:\d+(?:\.\d+)?(?:E[+-]?\d+)?|INF|NAN)$/iD', $text)) {
                throw new \UnexpectedValueException('Invalid scalar');
            }
            $this->offset = $end + 1;

            return match ($type) {
                'r', 'R' => '[REFERENCE OMITTED]',
                'b' => $text === '1',
                'i' => (int) $text,
                default => is_numeric($text) ? (float) $text : '[NON-FINITE NUMBER]',
            };
        }
        if (! in_array($type, ['a', 'O'], true)) {
            throw new \UnexpectedValueException('Unsupported serialized type');
        }
        $class = null;
        if ($type === 'O') {
            $class = $this->quoted();
            if (str_contains($class, 'SerializableClosure') || str_contains($class, 'CallQueuedClosure')) {
                throw new \UnexpectedValueException('Closure omitted');
            }
            $this->take(':');
        }
        $count = $this->number(':');
        if ($count > 2000) {
            throw new \LengthException('Item limit');
        }
        $this->take('{');
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $key = $this->value($depth + 1);
            if (! is_int($key) && ! is_string($key)) {
                throw new \UnexpectedValueException('Invalid key');
            }
            if ($type === 'O' && is_string($key) && str_contains($key, "\0")) {
                $key = substr($key, strrpos($key, "\0") + 1);
            }
            if (array_key_exists($key, $result)) {
                throw new \UnexpectedValueException('Ambiguous property');
            }
            $result[$key] = $this->value($depth + 1);
        }
        $this->take('}');

        // Retain the stored type for nested model identifiers, without loading models.
        return $class !== null && $depth > 0 ? ['__class' => $class, 'properties' => $result] : $result;
    }
}
