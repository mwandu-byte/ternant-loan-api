<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class DeleteAccountRequest extends FormRequest
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
            /** The account's current password, to confirm the deletion. */
            'password' => ['required', 'string'],
            /**
             * Business owners only: also close the business. It is suspended
             * (no one can sign in to it any more), its contact details are
             * removed, and its customers' and guarantors' personal details are
             * anonymized. Loan and payment records are kept.
             */
            'include_business' => ['sometimes', 'boolean'],
        ];
    }
}
