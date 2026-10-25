<?php

namespace HorizonLens;

final class Lens
{
    /** Labels must be fixed developer-defined strings, never payload or customer data. */
    public static function step(string $label, callable $callback): mixed
    {
        try {
            $recorder = app(Recorder::class);
        } catch (\Throwable) {
            return $callback();
        }

        return $recorder->step($label, $callback);
    }
}
