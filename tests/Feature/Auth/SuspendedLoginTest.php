<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuspendedLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_suspended_staff_cannot_log_in_and_are_told_why(): void
    {
        $user = User::factory()->receptionist()->suspended()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Your account has been suspended. Contact your facility admin.']);

        $this->assertGuest();
    }

    public function test_a_wrong_password_does_not_reveal_that_the_account_is_suspended(): void
    {
        $user = User::factory()->receptionist()->suspended()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->assertGuest();
    }
}
