<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // no auth restriction
    }

    protected function prepareForValidation(): void
    {
        // Older mobile clients send day_of_week 1..7 (Mon..Sun).
        // Normalise to backend convention 0..6 (Sun..Sat) before validation.
        $workingHours = $this->input('working_hours');
        if (is_array($workingHours)) {
            $normalised = array_map(function ($entry) {
                if (is_array($entry) && isset($entry['day_of_week'])) {
                    $day = (int) $entry['day_of_week'];
                    if ($day === 7) {
                        $day = 0;
                    }
                    $entry['day_of_week'] = $day;
                }
                return $entry;
            }, $workingHours);

            $this->merge(['working_hours' => $normalised]);
        }
    }

    public function rules(): array
    {
        return [
            'name'          => 'nullable|string|max:255',
            'logo'          => 'nullable|string',
            'pin'           => 'nullable|string',
            'lat'           => 'nullable|between:-90,90',
            'lon'           => 'nullable|between:-180,180',
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
