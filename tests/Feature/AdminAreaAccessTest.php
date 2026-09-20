<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAreaAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function adminPages(): array
    {
        $pages = [];

        foreach (['admin.facility', 'staff.index', 'staff.create', 'departments.index', 'departments.create', 'analytics.index', 'admin.settings'] as $route) {
            foreach ([UserRole::Receptionist, UserRole::Doctor, UserRole::Nurse] as $role) {
                $pages["{$role->value} on {$route}"] = [$role->value, $route];
            }
        }

        return $pages;
    }

    #[DataProvider('adminPages')]
    public function test_forbids_non_admin_staff_from_admin_pages_with_403(string $role, string $routeName): void
    {
        $this->actingAs(User::factory()->withRole(UserRole::from($role))->create())
            ->get(route($routeName))
            ->assertForbidden();
    }

    public function test_a_receptionist_cannot_create_staff_or_departments_by_posting_directly(): void
    {
        $receptionist = User::factory()->receptionist()->create();
        $department = Department::factory()->for($receptionist->facility)->create();

        $this->actingAs($receptionist)->post(route('staff.store'), [
            'name' => 'Sneaky Admin',
            'email' => 'sneaky@example.test',
            'phone' => '0712 345 678',
            'role' => 'nurse',
            'department_id' => $department->id,
        ])->assertForbidden();

        $this->actingAs($receptionist)->post(route('departments.store'), [
            'name' => 'Vault',
            'type' => 'other',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.test']);
        $this->assertDatabaseMissing('departments', ['name' => 'Vault']);
    }

    public function test_a_receptionist_cannot_change_or_remove_another_staff_member(): void
    {
        $receptionist = User::factory()->receptionist()->create();
        $colleague = User::factory()->for($receptionist->facility)->nurse()->create();

        $this->actingAs($receptionist)->delete(route('staff.destroy', $colleague))->assertForbidden();
        $this->actingAs($receptionist)->patch(route('staff.update', $colleague), ['name' => 'Changed'])->assertForbidden();

        $this->assertNotNull($colleague->fresh());
        $this->assertNotSame('Changed', $colleague->fresh()->name);
    }

    public function test_an_admin_can_open_every_admin_page(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['admin.facility', 'staff.index', 'staff.create', 'departments.index', 'departments.create', 'analytics.index', 'admin.settings'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }
}
