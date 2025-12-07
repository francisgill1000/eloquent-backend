<?php

namespace App\Macros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class FilterByKeyMacro
{
    public function __invoke(): void
    {
        Builder::macro('filterByKey', function (string $key = 'id'): Builder {
            
            $userType = request('user_type', 'guest');

            return $this->when($userType !== 'admin' && Auth::check(), function (Builder $query) use ($key) {
                $query->where($key, Auth::id());
            });
        });
    }
}
