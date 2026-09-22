<?php

namespace App\Http\Requests\Api\V1\Guarantor;

/**
 * Shared shape of a guarantor payload: either an existing customer
 * (guarantor_customer_id) or inline identity details. Business-scoping of
 * guarantor_customer_id is enforced in GuarantorService.
 */
final class GuarantorRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = '', bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            $prefix.'guarantor_customer_id' => ['nullable', 'integer'],
            $prefix.'full_name' => ['nullable', 'string', 'max:255'],
            $prefix.'phone' => ['nullable', 'string', 'max:20'],
            $prefix.'identification_type' => ['nullable', 'string', 'max:100'],
            $prefix.'identification_number' => ['nullable', 'string', 'max:100'],
            $prefix.'address' => ['nullable', 'string', 'max:2000'],
            $prefix.'relationship' => [$required, 'string', 'max:100'],
        ];
    }
}
