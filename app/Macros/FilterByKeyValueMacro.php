<?php

namespace App\Macros;

use Illuminate\Database\Eloquent\Builder;

class FilterByKeyValueMacro
{
    public function __invoke(): void
    {

        return;

        // Builder::macro('filterByKeyValue', function (string $key = 'name', $value): Builder {
        //     return (new Builder)->where($key, $value);
        // });
    }
}
