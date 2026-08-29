<?php

namespace Tests\Feature\Penalty;

use App\Models\Penalty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PenaltyListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function actingUserToken(array $permissions): string
    {
        $permissions[] = 'data.view-all';

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        $role = Role::create(['name' => 'test-role-'.uniqid(), 'guard_name' => 'api']);
        $role->givePermissionTo($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);

        return JWTAuth::fromUser($user);
    }

    public function test_authorized_user_can_list_penalties(): void
    {
        Penalty::factory()->count(3)->create();
        $token = $this->actingUserToken(['penalties.view']);

        $response = $this->getJson('/api/v1/penalties', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertCount(3, $response->json('data.penalties'));
    }

    public function test_list_can_be_filtered_by_loan_id(): void
    {
        $matching = Penalty::factory()->create();
        Penalty::factory()->create();
        $token = $this->actingUserToken(['penalties.view']);

        $response = $this->getJson(
            "/api/v1/penalties?loan_id={$matching->loan_id}",
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.penalties'));
        $this->assertSame($matching->id, $response->json('data.penalties.0.id'));
    }

    public function test_list_can_be_filtered_by_status(): void
    {
        Penalty::factory()->create(['status' => 'applied']);
        Penalty::factory()->create(['status' => 'waived']);
        $token = $this->actingUserToken(['penalties.view']);

        $response = $this->getJson(
            '/api/v1/penalties?status=waived',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.penalties'));
        $this->assertSame('waived', $response->json('data.penalties.0.status'));
    }

    public function test_unauthenticated_request_cannot_list_penalties(): void
    {
        $response = $this->getJson('/api/v1/penalties');

        $response->assertStatus(401);
    }

    public function test_user_without_view_permission_cannot_list_penalties(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson('/api/v1/penalties', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_no_write_endpoints_exist_for_penalties(): void
    {
        $token = $this->actingUserToken(['penalties.view', 'penalties.create', 'penalties.update']);

        $this->postJson('/api/v1/penalties', [], ['Authorization' => "Bearer {$token}"])->assertStatus(405);
        $this->putJson('/api/v1/penalties/1', [], ['Authorization' => "Bearer {$token}"])->assertStatus(405);
        $this->deleteJson('/api/v1/penalties/1', [], ['Authorization' => "Bearer {$token}"])->assertStatus(405);
    }
}
