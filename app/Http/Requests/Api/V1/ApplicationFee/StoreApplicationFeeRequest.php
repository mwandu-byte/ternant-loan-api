<?php

namespace App\Http\Requests\Api\V1\ApplicationFee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApplicationFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'status' => ['sometimes', 'string', Rule::in(['pending', 'paid'])],
            'paid_at' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'string', Rule::in(config('payment.methods'))],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'receipt_no' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
