<?php

namespace App\Services\Auth;

use App\Models\Business;
use App\Models\User;
use App\Services\LoanConfiguration\LoanConfigurationProvisioner;
use App\Support\BusinessOwnerRole;
use App\Support\PhoneNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Self-service onboarding: a new customer creates their own business and
 * becomes its owner without a platform administrator. The business is an
 * isolated tenant from the moment it exists, and the owner is a business
 * user (never a platform user).
 */
class BusinessRegistrationService
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly LoanConfigurationProvisioner $loanConfigurationProvisioner,
    ) {
        //
    }

    /**
     * Creates the business, its loan configuration and its owner, then
     * signs the owner in. Everything runs in one transaction: if any step
     * fails, no business, user or token is left behind.
     *
     * @param  array{business: array<string, mixed>, owner: array<string, mixed>}  $data
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, user: User, business: Business}
     */
    public function register(array $data): array
    {
        $businessData = $data['business'];
        $ownerData = $data['owner'];

        $businessPhone = $this->normalizePhone($businessData['phone'], 'business.phone');
        $ownerPhone = $this->normalizePhone($ownerData['phone'], 'owner.phone');

        try {
            return DB::transaction(function () use ($businessData, $ownerData, $businessPhone, $ownerPhone) {
                $business = Business::create([
                    'name' => $businessData['name'],
                    'registration_number' => $businessData['registration_number'] ?? null,
                    'phone' => $businessPhone,
                    'email' => $businessData['email'],
                    'address' => $businessData['address'] ?? null,
                    'status' => 'active',
                    // Onboarding defaults; the owner can change both later
                    // through PUT /business.
                    'requires_application_fee' => false,
                    'requires_guarantor' => false,
                ]);

                $this->loanConfigurationProvisioner->provisionFor($business);

                $owner = User::create([
                    'business_id' => $business->id,
                    'name' => $ownerData['name'],
                    'email' => $ownerData['email'],
                    'phone' => $ownerPhone,
                    'password' => $ownerData['password'],
                    'is_enabled' => true,
                ]);

                $owner->assignRole(BusinessOwnerRole::NAME);

                return [
                    ...$this->authService->issueTokensFor($owner),
                    'business' => $business->refresh(),
                ];
            });
        } catch (QueryException $e) {
            // Validation already rejects duplicates; this only catches a
            // concurrent registration that slipped in between.
            throw $this->duplicateError($e) ?? $e;
        }
    }

    private function normalizePhone(string $raw, string $field): string
    {
        try {
            return PhoneNumber::normalize($raw, config('customer.default_country_code'));
        } catch (ValidationException) {
            $label = $field === 'owner.phone' ? 'owner phone' : 'business phone';

            throw ValidationException::withMessages([$field => ["The {$label} format is invalid."]]);
        }
    }

    private function duplicateError(QueryException $e): ?ValidationException
    {
        $message = $e->getMessage();

        if (stripos($message, 'unique') === false && stripos($message, 'duplicate') === false) {
            return null;
        }

        [$field, $label] = match (true) {
            str_contains($message, 'users') && str_contains($message, 'email') => ['owner.email', 'owner email'],
            str_contains($message, 'businesses') && str_contains($message, 'registration_number') => ['business.registration_number', 'registration number'],
            str_contains($message, 'businesses') && str_contains($message, 'email') => ['business.email', 'business email'],
            default => [null, null],
        };

        return $field === null
            ? null
            : ValidationException::withMessages([$field => ["The {$label} has already been taken."]]);
    }
}
