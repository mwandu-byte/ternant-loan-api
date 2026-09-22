<?php

namespace App\Http\Requests\Api\V1\Guarantor;

use Illuminate\Foundation\Http\FormRequest;

class StoreGuarantorRequest extends FormRequest
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
        return GuarantorRules::rules();
    }
}
