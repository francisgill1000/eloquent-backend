<?php

namespace App\Macros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class SearchMacro
{
    public function __invoke(): void
    {
        Builder::macro('search', function (array $columns = []): Builder {
            $search = strtolower(request('search', ''));

            if (!$search || empty($columns)) {
                return $this;
            }

            return $this->where(function (Builder $query) use ($columns, $search) {
                foreach ($columns as $column) {
                    if (Str::contains($column, '.')) {
                        [$relation, $relColumn] = explode('.', $column);
                        $query->orWhereHas($relation, function (Builder $subQuery) use ($relColumn, $search) {
                            $subQuery->whereRaw("LOWER({$relColumn}) LIKE ?", ["%{$search}%"]);
                        });
                    } else {
                        $query->orWhereRaw("LOWER({$column}) LIKE ?", ["%{$search}%"]);
                    }
                }
            });
        });
    }
}
