<?php

namespace App\Http\Requests\Api\V1\Loan;

use App\Http\Requests\Api\V1\Guarantor\GuarantorRules;
use App\Models\RepaymentFrequency;
use App\Models\RepaymentTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoanRequest extends FormRequest
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
            'customer_id' => [
                'required',
                'integer',
                // A customer of another business is indistinguishable from
                // a missing one.
                Rule::exists('customers', 'id')->where(function ($query) {
                    $businessId = $this->user()?->business_id;

                    if ($businessId !== null) {
                        $query->where('business_id', $businessId);
                    }
                }),
            ],
            'principal_amount' => ['required', 'numeric', 'min:0.01'],
            'repayment_frequency' => ['required', 'string', Rule::in(RepaymentFrequency::query()->where('status', 'active')->pluck('code'))],
            'repayment_term' => ['required', 'integer', Rule::in(RepaymentTerm::query()->where('status', 'active')->pluck('value'))],
            'start_date' => ['required', 'date'],
            'status' => ['nullable', 'string', Rule::in(['pending', 'active'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'has_discount' => ['nullable', 'boolean'],
            'discount_rate' => ['nullable', 'numeric', 'min:0', 'required_if:has_discount,true', 'prohibited_unless:has_discount,true'],
            'collateral_ids' => ['nullable', 'array'],
            'collateral_ids.*' => ['integer', 'exists:collaterals,id'],
            /**
             * Guarantors for this loan. Required (at least one) when the
             * customer's business has `requires_guarantor` enabled. Each entry is
             * either an existing customer (`guarantor_customer_id`) or inline details.
             */
            'guarantors' => ['nullable', 'array'],
            ...GuarantorRules::rules('guarantors.*.'),
            'payment_method' => ['nullable', 'string', Rule::in(config('payment.methods'))],
            'payment_reference_no' => ['nullable', 'string', 'max:255'],
        ];
    }
}
