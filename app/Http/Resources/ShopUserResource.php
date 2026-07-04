<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ShopUserResource extends JsonResource
{
    public function toArray($request): array
    {
        $role = $this->relationLoaded('roles') ? $this->roles->first() : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_active' => (bool) $this->is_active,
            'role' => $role ? ['id' => $role->id, 'name' => $role->name] : null,
        ];
    }
}
