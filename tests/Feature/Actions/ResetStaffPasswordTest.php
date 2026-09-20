<?php

namespace Tests\Feature\Actions;

use App\Actions\ResetStaffPassword;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResetStaffPasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->for(Facility::factory())->doctor()->create([
            'password' => 'Old-password-1',
            'remember_token' => 'old-remember-token',
        ]);
    }

    private function signedInSession(User $user): void
    {
        DB::table('sessions')->insert([
            'id' => 'session-of-'.$user->id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'x',
            'last_activity' => time(),
        ]);
    }

    public function test_gives_back_a_new_temporary_password_that_is_the_one_now_in_force(): void
    {
        $new = app(ResetStaffPassword::class)->handle($this->staff);

        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Za-km-z2-9]{12}$/', $new);
        $this->assertTrue(Hash::check($new, $this->staff->fresh()->password));
        $this->assertFalse(Hash::check('Old-password-1', $this->staff->fresh()->password));
    }

    public function test_the_person_has_to_choose_their_own_password_again(): void
    {
        app(ResetStaffPassword::class)->handle($this->staff);

        $this->assertTrue($this->staff->fresh()->must_change_password);
    }

    public function test_the_remember_me_cookie_stops_working(): void
    {
        app(ResetStaffPassword::class)->handle($this->staff);

        $this->assertNotSame('old-remember-token', $this->staff->fresh()->remember_token);
    }

    public function test_anything_still_signed_in_as_them_is_signed_out_and_nobody_elses_is(): void
    {
        config(['session.driver' => 'database']);
        $colleague = User::factory()->for($this->staff->facility)->nurse()->create();
        $this->signedInSession($this->staff);
        $this->signedInSession($colleague);

        app(ResetStaffPassword::class)->handle($this->staff);

        $this->assertDatabaseMissing('sessions', ['user_id' => $this->staff->id]);
        $this->assertDatabaseHas('sessions', ['user_id' => $colleague->id]);
    }

    public function test_it_works_when_sessions_are_not_kept_in_the_database(): void
    {
        config(['session.driver' => 'array']);
        $this->signedInSession($this->staff);

        $new = app(ResetStaffPassword::class)->handle($this->staff);

        $this->assertNotEmpty($new);
        $this->assertDatabaseHas('sessions', ['user_id' => $this->staff->id]);
    }

    public function test_each_reset_makes_a_different_password(): void
    {
        $action = app(ResetStaffPassword::class);

        $this->assertNotSame($action->handle($this->staff), $action->handle($this->staff));
    }
}
