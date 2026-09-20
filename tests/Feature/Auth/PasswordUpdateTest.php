<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('updatePassword', 'current_password')
            ->assertRedirect('/profile');
    }

    public function test_choosing_a_new_password_ends_the_temporary_password_state(): void
    {
        $user = User::factory()->mustChangePassword()->create(['password' => 'Temp-Pass-123']);

        $this->actingAs($user)->put('/password', [
            'current_password' => 'Temp-Pass-123',
            'password' => 'My-own-secret-9',
            'password_confirmation' => 'My-own-secret-9',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($user->refresh()->must_change_password);
    }

    public function test_changing_the_temporary_password_to_itself_does_not_count(): void
    {
        $user = User::factory()->mustChangePassword()->create(['password' => 'Temp-Pass-123']);

        $this->actingAs($user)->put('/password', [
            'current_password' => 'Temp-Pass-123',
            'password' => 'Temp-Pass-123',
            'password_confirmation' => 'Temp-Pass-123',
        ]);

        $this->assertTrue($user->refresh()->must_change_password);
    }

    public function test_changing_a_password_of_your_own_leaves_things_as_they_were(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/password', [
            'current_password' => 'password',
            'password' => 'another-password-1',
            'password_confirmation' => 'another-password-1',
        ]);

        $this->assertFalse($user->refresh()->must_change_password);
    }
}
