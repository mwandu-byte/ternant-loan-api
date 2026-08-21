<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = Role::where('guard_name', 'api')
            ->whereIn('name', ['admin', 'manager', 'staff'])
            ->get()
            ->keyBy('name');

        $usersToCreate = [
            [
                'name' => 'system admin',
                'password' => Hash::make('123456'),
                'email' => 'info@caresmart.co.tz',
                'phone' => null,
                'is_enabled' => true,
                'role' => 'admin',
            ],
        ];

        foreach ($usersToCreate as $userData) {
            $userExists = User::where('email', $userData['email'])->exists();

            if ($userExists) {
                $this->command->line("User {$userData['name']} already exists - skipped");

                continue;
            }

            $user = User::create([
                'name' => $userData['name'],
                'email' => $userData['email'],
                'password' => $userData['password'],
                'phone' => $userData['phone'],
                'is_enabled' => $userData['is_enabled'],
            ]);

            $roleName = $userData['role'];

            if (isset($roles[$roleName])) {
                $user->assignRole($roles[$roleName]);
                $this->command->info("Created user: {$userData['name']} with role: {$roleName}");
            } else {
                $this->command->error("Role {$roleName} not found for user {$userData['name']}");
            }
        }
    }
}
