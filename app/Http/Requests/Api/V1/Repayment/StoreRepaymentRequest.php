<?php

namespace App\Http\Requests\Api\V1\Repayment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRepaymentRequest extends FormRequest
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
            'loan_id' => ['required', 'integer', 'exists:loans,id'],
            'repayment_schedule_id' => ['required', 'integer', 'exists:repayment_schedules,id'],
            'amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'repayment_date' => ['required', 'date', 'before_or_equal:today'],
            'payment_method' => ['required', 'string', Rule::in(config('payment.methods'))],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
