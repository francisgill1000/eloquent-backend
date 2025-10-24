<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Builder::macro('search', function (array $columns = []) {
            $search = request('search');

            if (! $search || empty($columns)) {
                return $this;
            }

            $search = strtolower($search);

            return $this->where(function ($q) use ($columns, $search) {
                foreach ($columns as $column) {
                    if (Str::contains($column, '.')) {
                        [$relation, $relColumn] = explode('.', $column);
                        $q->orWhereHas($relation, function ($subQuery) use ($relColumn, $search) {
                            $subQuery->whereRaw("LOWER($relColumn) LIKE ?", ["%{$search}%"]);
                        });
                    } else {
                        $q->orWhereRaw("LOWER($column) LIKE ?", ["%{$search}%"]);
                    }
                }
            });
        });
    }
}
