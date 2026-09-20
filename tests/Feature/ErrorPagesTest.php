<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    public function test_an_address_that_does_not_exist_shows_the_calm_404_page(): void
    {
        $this->get('/nothing-lives-here')
            ->assertNotFound()
            ->assertSeeText('404')
            ->assertSeeText("We couldn't find that page.")
            ->assertSeeText('Back to Home')
            ->assertSee('href="'.url('/').'"', false);
    }

    public function test_the_404_page_is_used_across_the_whole_app_not_only_the_public_site(): void
    {
        $this->get('/t/short')->assertNotFound()->assertSeeText("We couldn't find that page.");
    }

    public function test_the_500_page_says_it_is_our_side_and_offers_the_way_home(): void
    {
        config(['app.debug' => false]);
        Route::get('/_test/boom', fn () => throw new RuntimeException('boom'));

        $this->get('/_test/boom')
            ->assertStatus(500)
            ->assertSeeText('500')
            ->assertSeeText('Something went wrong on our side.')
            ->assertSeeText('Back to Home')
            ->assertSee('href="'.url('/').'"', false)
            ->assertDontSeeText('boom');
    }

    public function test_both_pages_use_the_careflow_teal_and_calm_background_not_a_template_look(): void
    {
        config(['app.debug' => false]);
        Route::get('/_test/boom', fn () => throw new RuntimeException('boom'));

        foreach (['/nothing-lives-here', '/_test/boom'] as $path) {
            $this->get($path)
                ->assertSee('--primary: #1E6B5C', false)
                ->assertSee('--bg: #FFFFFF', false)
                ->assertSee('--text: #0B2E29', false)
                ->assertSee('#CFE0D9', false);
        }
    }

    public function test_the_error_pages_need_no_build_assets_database_or_session(): void
    {
        config(['app.debug' => false]);
        Route::get('/_test/boom', fn () => throw new RuntimeException('boom'));

        foreach (['/nothing-lives-here', '/_test/boom'] as $path) {
            $content = $this->get($path)->getContent();

            $this->assertStringNotContainsString('/build/assets', $content);
            $this->assertStringNotContainsString('csrf-token', $content);
            $this->assertStringContainsString('<style>', $content);
        }
    }

    public function test_they_are_kept_out_of_search_results(): void
    {
        $this->get('/nothing-lives-here')->assertSee('<meta name="robots" content="noindex">', false);
    }

    public function test_a_program_asking_for_json_still_gets_json(): void
    {
        $this->getJson('/nothing-lives-here')->assertNotFound()->assertJsonStructure(['message']);
    }
}
