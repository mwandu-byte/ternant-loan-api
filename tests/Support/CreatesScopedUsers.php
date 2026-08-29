<?php

namespace Tests\Support;

use App\Models\User;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/**
 * Builds users with the *real* seeded roles (staff/manager/admin), for
 * tests that need Spatie's actual `data.view-all` scope-elevation
 * permission to resolve correctly — unlike the throwaway `test-role-*`
 * roles most feature tests build via their own actingUserToken() helper,
 * which only ever carry the specific permissions that test names.
 *
 * Tests using this trait must run the real seeders (staff/manager/admin
 * need their full real permission sets to pass route middleware, not
 * just the scope check):
 *
 *     $this->seed(PermissionSeeder::class);
 *     $this->seed(RoleSeeder::class);
 */
trait CreatesScopedUsers
{
    private function staffUser(): User
    {
        return $this->roleUser('staff');
    }

    private function managerUser(): User
    {
        return $this->roleUser('manager');
    }

    private function adminUser(): User
    {
        return $this->roleUser('admin');
    }

    private function roleUser(string $roleName): User
    {
        $user = User::factory()->create();
        $user->assignRole($roleName);

        return $user;
    }

    private function tokenFor(User $user): string
    {
        return JWTAuth::fromUser($user);
    }

    /**
     * Call before a second request in the same test authenticates as a
     * different user. Both the AuthManager's resolved guards AND the
     * underlying JWT manager's parsed-token cache persist for the life
     * of the application container (i.e. across every request/response
     * cycle within a single test method), so without this a second
     * bearer token in the same test is silently ignored and the guard
     * keeps resolving whichever user authenticated first.
     */
    private function switchAuthenticatedUser(): void
    {
        app('auth')->forgetGuards();
        app('tymon.jwt')->unsetToken();
    }
}
