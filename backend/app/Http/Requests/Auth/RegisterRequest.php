<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email', 'max:255'],
            'password' => [
                'required',
                'string',
                'min:12',
                'confirmed',
                'regex:/[a-z]/',      // At least one lowercase letter
                'regex:/[A-Z]/',      // At least one uppercase letter
                'regex:/[0-9]/',      // At least one digit
                'regex:/[@$!%*#?&]/', // At least one special character
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'password.min' => 'Password must be at least 12 characters.',
            'password.regex' => 'Password must contain at least one lowercase letter, one uppercase letter, one digit, and one special character (@$!%*#?&).',
        ];
    }
}
