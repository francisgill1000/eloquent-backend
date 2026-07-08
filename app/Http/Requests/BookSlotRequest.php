<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BookSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // allow everyone (device-based booking)
    }

    protected function prepareForValidation(): void
    {
        if (!$this->header('X-Device-Id')) {
            throw new HttpException(
                422,
                'Device ID missing'
            );
        }
    }

    public function rules(): array
    {
        return [
            'date'              => ['required', 'date'],
            'start_time'        => ['required'],
            'charges'           => ['nullable', 'numeric', 'min:0'],
            'services'          => ['required', 'array', 'min:1'],
            'customer_name'     => ['nullable', 'string', 'max:255'],
            'customer_whatsapp' => ['required', 'string', 'max:32'],
            'promo_code'        => ['nullable', 'string', 'max:32'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.required' => 'Booking date is required',
            'start_time.required' => 'Start time is required',
            'services.required' => 'Please select at least one service',
            'services.min' => 'Please select at least one service',
            'services.array' => 'Please select at least one service',
            'customer_whatsapp.required' => 'A customer contact number is required',
        ];
    }
}
