<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',

            // ✅ Fix: allow updating the same email without failing unique rule
            'email' => [
                'required',
                'string',
                'email',
                Rule::unique('users')->ignore($this->route('id')),
            ],

            'password' => 'nullable|string|min:6|confirmed',
            'password_confirmation' => 'nullable|string|min:6',

            'user_type' => 'nullable|string',

            'phone' => [
                'nullable',
                'regex:/^[0-9]{7,15}$/', // only digits, 7–15 characters long
            ],
            'whatsapp' => [
                'nullable',
                'regex:/^[0-9]{7,15}$/',
            ],

            'country' => 'required|string|max:255',
            'city' => 'required|string|max:255',
        ];
    }
}
