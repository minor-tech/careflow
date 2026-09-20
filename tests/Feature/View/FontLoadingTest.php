<?php

namespace Tests\Feature\View;

use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FontLoadingTest extends TestCase
{
    use RefreshDatabase;

    private const FONT_URL = 'https://fonts.googleapis.com/css2?family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&display=swap';

    /**
     * One page per layout that a visitor without an account can reach: the public site, the auth pages, the wizard, and the error pages.
     *
     * @return array<string, array{string}>
     */
    public static function publicLayouts(): array
    {
        return [
            'public site' => ['/'],
            'auth pages' => ['/login'],
            'registration wizard' => ['/register-facility/step/1'],
            'error pages' => ['/nothing-lives-here'],
        ];
    }

    #[DataProvider('publicLayouts')]
    public function test_every_public_layout_loads_google_sans_from_google_fonts(string $path): void
    {
        $this->assertLoadsGoogleSans($this->get($path));
    }

    public function test_the_signed_in_dashboard_loads_google_sans_from_google_fonts(): void
    {
        $admin = User::factory()->for(Facility::factory()->create())->create();

        $this->assertLoadsGoogleSans($this->actingAs($admin)->get(route('admin.facility')));
    }

    public function test_the_error_pages_name_google_sans_first_with_system_fonts_behind_it_in_case_it_has_not_arrived(): void
    {
        $this->get('/nothing-lives-here')
            ->assertSee('font-family: "Google Sans", -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;', false);
    }

    private function assertLoadsGoogleSans(TestResponse $response): void
    {
        $response
            ->assertSee('<link rel="preconnect" href="https://fonts.googleapis.com">', false)
            ->assertSee('<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>', false)
            ->assertSee('<link href="'.self::FONT_URL.'" rel="stylesheet">', false);
    }
}
