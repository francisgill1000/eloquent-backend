<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class StoreRequent extends FormRequest
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
            'user_code' => [
                'required_if:user_type,agent',
                'string',
                'min:5',
                'max:10',
                'unique:users,user_code',
            ],
            'email' => 'required|string|email|unique:users',
            'password' => 'nullable|string|min:6|confirmed',
            'password_confirmation' => 'nullable|string|min:6',
            'user_type' => 'nullable',

            'phone' => [
                'nullable',
                'regex:/^[0-9]{7,15}$/', // only digits, 7–15 characters long
            ],
            'whatsapp' => [
                'nullable',
                'regex:/^[0-9]{7,15}$/', // only digits, 7–15 characters long
            ],

            'country' => 'required',
            'city' => 'required',
        ];
    }
}
