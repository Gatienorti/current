<?php

namespace Current\Native;

use Illuminate\Database\Query\Builder;
use Illuminate\Queue\Failed\DatabaseFailedJobProvider;

/** The numeric provider's protected table contract, isolated for Laravel 11/12/13. */
final class NumericFailedPayloads extends DatabaseFailedJobProvider
{
    public static function table(object $provider): Builder
    {
        return $provider->getTable();
    }
}
