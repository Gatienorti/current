<?php

namespace Current;

use HorizonLens\Lens;

final class Current
{
    /** Use fixed labels, never customer data or credentials. */
    public static function step(string $label, callable $callback): mixed
    {
        return Lens::step($label, $callback);
    }
}
