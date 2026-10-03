<?php

namespace App\Services\Auth;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Guarantor;
use App\Models\RefreshToken;
use App\Models\User;
use App\Support\AccessScope;
use App\Support\AdministrativeCoverageGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Self-service account (and, for owners, business) deletion.
 *
 * Users are referenced by the loans, customers, fees and guarantors they
 * recorded, and lending records must be retained, so nothing financial is
 * removed. Instead the personal data is anonymized in place: the account
 * can never be signed in to again and no longer identifies anyone, while
 * every loan and payment keeps its history intact.
 */
class AccountDeletionService
{
    public function __construct(
        private readonly AuthService $authService,
    ) {
        //
    }

    public function delete(User $user, string $password, bool $includeBusiness = false): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => ['The password is incorrect.']]);
        }

        $business = $includeBusiness ? $this->closableBusinessOf($user) : null;

        // Never let the last user able to fix roles delete themselves.
        if ($user->can('roles.update')) {
            AdministrativeCoverageGuard::ensureRolesUpdateSurvives(excludeUser: $user);
        }

        $photos = DB::transaction(function () use ($user, $business) {
            $photos = $business !== null ? $this->closeBusiness($business) : [];
            $this->anonymizeUser($user);

            return $photos;
        });

        // Files go only once the database change has committed.
        if ($photos !== []) {
            Storage::disk(config('customer.photo_disk'))->delete($photos);
        }

        // Revokes any refresh tokens and blacklists the access token used
        // for this request.
        $this->authService->logout($user);
    }

    private function closableBusinessOf(User $user): Business
    {
        if (AccessScope::isPlatformUser($user) || ! $user->can('business-settings.update')) {
            throw ValidationException::withMessages([
                'include_business' => ['Only the business owner can close the business.'],
            ]);
        }

        return $user->business;
    }

    private function anonymizeUser(User $user): void
    {
        $originalEmail = $user->email;

        $user->syncRoles([]);
        $user->syncPermissions([]);

        $user->forceFill([
            'name' => 'Deleted user',
            'email' => "deleted-user-{$user->id}@deleted.invalid",
            'phone' => null,
            'password' => Hash::make(Str::random(64)),
            'is_enabled' => false,
            'email_verified_at' => null,
            'remember_token' => null,
        ])->save();

        DB::table('password_reset_tokens')->where('email', $originalEmail)->delete();
        RefreshToken::where('user_id', $user->id)->delete();
    }

    /**
     * Suspends the business and anonymizes the people in it, keeping every
     * loan, schedule, payment and penalty. Returns the customer photo paths
     * to delete once the transaction commits.
     *
     * @return array<int, string>
     */
    private function closeBusiness(Business $business): array
    {
        $photos = Customer::where('business_id', $business->id)->whereNotNull('photo')->pluck('photo')->all();

        // Row by row: phone and ID number are unique per business, so each
        // customer needs its own placeholder.
        Customer::where('business_id', $business->id)->lazyById()->each(
            fn (Customer $customer) => $customer->forceFill([
                'full_name' => 'Deleted customer',
                'phone' => "deleted-{$customer->id}",
                'email' => null,
                'identification_type' => 'deleted',
                'identification_number' => "deleted-{$customer->id}",
                'gender' => null,
                'address' => 'Removed',
                'photo' => null,
                'status' => 'inactive',
            ])->saveQuietly()
        );

        // Guarantors that are existing customers were covered above; inline
        // ones carry their own identity.
        Guarantor::where('business_id', $business->id)->whereNull('guarantor_customer_id')->update([
            'full_name' => 'Deleted guarantor',
            'phone' => null,
            'identification_type' => null,
            'identification_number' => null,
            'address' => null,
        ]);

        $business->forceFill([
            'name' => "Closed business #{$business->id}",
            'registration_number' => null,
            'phone' => null,
            'email' => null,
            'address' => null,
            'status' => 'suspended',
        ])->save();

        // Suspension already blocks sign-in and refresh for the rest of the
        // team; revoking their refresh tokens ends existing sessions too.
        RefreshToken::whereIn('user_id', User::where('business_id', $business->id)->select('id'))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        return $photos;
    }
}
