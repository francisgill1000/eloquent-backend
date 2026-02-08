<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // no auth restriction
    }

    public function rules(): array
    {
        return [
            'name'        => 'required|string|max:255',
            'logo'        => 'nullable|string|max:255',
            'hero_image'  => 'nullable|string|max:255',
            'lat'         => 'required|between:-90,90',
            'lon'         => 'required|between:-180,180',
            'location'    => 'required|string|max:255',
            'is_verified' => 'boolean',
            'category_id' => 'required|integer|min:1',
            'status'      => 'required|string|in:active,inactive',
            'logo'      => 'required',

        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_verified' => $this->boolean('is_verified'),
            'status' => $this->status ?? 'inactive',
        ]);
    }
}
