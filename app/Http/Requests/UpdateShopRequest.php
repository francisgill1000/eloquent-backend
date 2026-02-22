<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // no auth restriction
    }

    public function rules(): array
    {
        return [
            'name'          => 'nullable|string|max:255',
            'logo'          => 'nullable|string',
            'pin'           => 'nullable|string',
            'location'      => 'nullable|string|max:255',
            'hero_image'    => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'website.url' => 'The website must be a valid URL.',
        ];
    }
}
