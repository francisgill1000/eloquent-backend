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
            'working_hours' => 'sometimes|array|max:7',
            'working_hours.*.day_of_week' => 'required|integer|between:0,6|distinct',
            'working_hours.*.start_time' => 'required|date_format:H:i',
            'working_hours.*.end_time' => 'required|date_format:H:i',
            'working_hours.*.slot_duration' => 'nullable|integer|min:5|max:240',
        ];
    }

    public function messages(): array
    {
        return [
            'website.url' => 'The website must be a valid URL.',
        ];
    }
}
