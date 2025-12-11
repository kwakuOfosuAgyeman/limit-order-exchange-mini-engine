<?php

namespace App\Http\Requests;

use App\Enums\OrderSide;
use App\Models\Symbol;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateOrderRequest extends FormRequest
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
            'symbol' => [
                'required',
                'string',
                Rule::exists('symbols', 'symbol')->where('is_active', true)->where('trading_enabled', true),
            ],
            'side' => ['required', 'string', Rule::enum(OrderSide::class)],
            'price' => ['required', 'numeric', 'gt:0', 'decimal:0,8'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,8'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'symbol.exists' => 'The selected symbol is not available for trading.',
            'price.gt' => 'The price must be greater than zero.',
            'amount.gt' => 'The amount must be greater than zero.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('side')) {
            $this->merge([
                'side' => strtolower($this->side),
            ]);
        }
    }
}
