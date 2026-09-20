<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Facility;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state: an admin of a fresh, active facility.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'phone' => '07'.fake()->numerify('########'),
            'title' => null,
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
            'two_factor_enabled' => true,
            'facility_id' => Facility::factory(),
            'department_id' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function withRole(UserRole $role): static
    {
        return $this->state(fn (array $attributes) => ['role' => $role]);
    }

    public function receptionist(): static
    {
        return $this->withRole(UserRole::Receptionist);
    }

    public function doctor(): static
    {
        return $this->withRole(UserRole::Doctor);
    }

    /**
     * A doctor who has switched themself on duty, so patients can be assigned to them.
     */
    public function onDuty(): static
    {
        return $this->state(fn (array $attributes) => ['is_on_duty' => true]);
    }

    public function specializingIn(Service $service): static
    {
        return $this->state(fn (array $attributes) => ['service_id' => $service->id, 'department_id' => $service->department_id]);
    }

    public function nurse(): static
    {
        return $this->withRole(UserRole::Nurse);
    }

    /**
     * A platform-level system admin, who belongs to no facility.
     */
    public function systemAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::SystemAdmin,
            'facility_id' => null,
            'department_id' => null,
        ]);
    }

    /**
     * An account still on the temporary password an admin gave them.
     */
    public function mustChangePassword(): static
    {
        return $this->state(fn (array $attributes) => ['must_change_password' => true]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => ['status' => UserStatus::Suspended]);
    }
}
