<?php

namespace App\Http\Requests;

use App\Models\Shop;
use App\Rules\UniqueLoginEmail;
use Illuminate\Foundation\Http\FormRequest;

class StoreShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof Shop && $user->is_master;
    }

    public function rules(): array
    {
        return [
            'name'        => 'required|string|max:255|unique:shops,name',
            'email'       => ['required', 'email', 'max:255', new UniqueLoginEmail()],
            'password'    => 'required|string|min:8',
            'phone'       => 'nullable|string|max:32',
            'logo'        => 'nullable',
            'hero_image'  => 'nullable',
            'lat'         => 'nullable|between:-90,90',
            'lon'         => 'nullable|between:-180,180',
            'location'    => 'nullable|string|max:255',
            'is_verified' => 'boolean',
            // OTHER_ID (0) means the owner chose "Other" and typed a custom_category.
            'category_id' => 'required|integer|in:' . implode(',', array_merge(
                [\App\Support\ServiceCategories::OTHER_ID],
                \App\Support\ServiceCategories::ids(),
            )),
            'custom_category' => 'nullable|string|max:255|required_if:category_id,' . \App\Support\ServiceCategories::OTHER_ID,
            'status'      => 'required|string|in:active,inactive',

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
