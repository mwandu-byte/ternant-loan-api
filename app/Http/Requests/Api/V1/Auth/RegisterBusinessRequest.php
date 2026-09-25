<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterBusinessRequest extends FormRequest
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
            'business' => ['required', 'array'],
            /** Trading name of the business. */
            'business.name' => ['required', 'string', 'max:255'],
            /** Official registration number, where the business has one. Must be unique across all businesses. */
            'business.registration_number' => ['nullable', 'string', 'max:100', Rule::unique('businesses', 'registration_number')],
            /** Business contact phone number. */
            'business.phone' => ['required', 'string', 'max:20'],
            /** Business contact email. Must be unique across all businesses. */
            'business.email' => ['required', 'email', 'max:255', Rule::unique('businesses', 'email')],
            /** Physical address of the business. */
            'business.address' => ['nullable', 'string', 'max:2000'],

            'owner' => ['required', 'array'],
            /** Full name of the business owner. */
            'owner.name' => ['required', 'string', 'max:255'],
            /** Owner's login email. Must not belong to any existing user. */
            'owner.email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            /** Owner's phone number, e.g. +255712345678 or 0712345678. */
            'owner.phone' => ['required', 'string', 'max:20'],
            /** At least 10 characters, with upper- and lower-case letters, a number and a symbol. */
            'owner.password' => ['required', 'string', 'confirmed', Password::defaults()],
            /** Must match `owner.password`. */
            'owner.password_confirmation' => ['required', 'string'],
        ];
    }

    /**
     * Readable names for error messages, e.g. "The business email has
     * already been taken." instead of "The business.email ...".
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'business.name' => 'business name',
            'business.registration_number' => 'registration number',
            'business.phone' => 'business phone',
            'business.email' => 'business email',
            'business.address' => 'business address',
            'owner.name' => 'owner name',
            'owner.email' => 'owner email',
            'owner.phone' => 'owner phone',
            'owner.password' => 'password',
            'owner.password_confirmation' => 'password confirmation',
        ];
    }
}
