<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GuestSiteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function pages(): array
    {
        return ['home' => ['home'], 'about' => ['about'], 'contact' => ['contact']];
    }

    /**
     * Just the navigation bar at the top of the page.
     */
    private function header(string $page): string
    {
        preg_match('/<header.*?<\/header>/s', $this->get(route($page))->getContent(), $found);

        return $found[0];
    }

    public function test_the_home_page_is_the_root_address(): void
    {
        $this->assertSame(url('/'), route('home'));
        $this->get('/')->assertOk();
    }

    #[DataProvider('pages')]
    public function test_every_page_is_public_and_can_be_found_by_a_search(string $page): void
    {
        $this->assertGuest();

        $this->get(route($page))
            ->assertOk()
            ->assertSee('<meta name="description"', false)
            ->assertSee('name="viewport"', false)
            ->assertDontSee('noindex', false);
    }

    public function test_the_hero_says_what_careflow_does_and_offers_the_two_next_steps(): void
    {
        $this->get(route('home'))
            ->assertSeeText('Know where every patient is.')
            ->assertSeeText('live view of every patient')
            ->assertSeeText('niko number gani?')
            ->assertSee('href="#how-it-works"', false)
            ->assertSee('id="how-it-works"', false);
    }

    public function test_the_landing_page_has_every_section_in_order(): void
    {
        $this->get(route('home'))->assertSeeTextInOrder([
            'Know where every patient is.',
            'Three things patients complain about most',
            'Not knowing how long they\'ll wait',
            'CareFlow fixes all three with one link.',
            'From the front desk to the patient\'s phone',
            '01', 'Patient registers at reception, gets a queue number',
            '02', 'They get a private link',
            '03', 'Staff move them through departments',
            '04', 'They get texted at the moments that matter',
            'What your staff get',
            'Live queue dashboard',
            'Every patient, located',
            'Automatic SMS',
            'Real bottleneck data',
            'Built for Kenyan healthcare facilities',
            'Common questions',
            'Ready to stop the guessing game in your waiting room?',
        ]);
    }

    public function test_the_faq_is_an_accordion_with_the_four_real_questions(): void
    {
        $response = $this->get(route('home'))
            ->assertSeeText('Does this replace our existing hospital system?')
            ->assertSeeText('Do patients need to install an app?')
            ->assertSeeText('Is our patients\' data secure?')
            ->assertSeeText('How do we get started?')
            ->assertSeeText('it isn\'t a full medical records system')
            ->assertSeeText('usually within 24 hours');

        $content = $response->getContent();
        $this->assertSame(4, substr_count($content, 'aria-controls="faq-panel-'));
        $this->assertSame(4, substr_count($content, 'role="region"'));
        $this->assertSame(4, preg_match_all('/id="faq-button-\d"/', $content));
    }

    public function test_the_navigation_is_home_about_contact_then_register_and_a_plain_staff_login(): void
    {
        $header = $this->header('home');

        $this->assertMatchesRegularExpression('/Home.*About.*Contact.*Register Your Facility.*Staff Login/s', $header);
        $this->assertStringContainsString('href="'.route('facility.register').'"', $header);
        $this->assertStringContainsString('href="'.route('login').'"', $header);
    }

    public function test_register_is_the_filled_button_and_staff_login_is_only_a_text_link(): void
    {
        $header = $this->header('home');

        $this->assertMatchesRegularExpression('/<a href="[^"]*register-facility" class="btn-primary[^"]*">Register Your Facility<\/a>/', $header);
        $this->assertMatchesRegularExpression('/<a href="[^"]*login" class="site-nav-link">Staff Login<\/a>/', $header);
    }

    #[DataProvider('pages')]
    public function test_there_is_no_admin_or_directory_menu_in_the_public_navigation(string $page): void
    {
        $header = $this->header($page);

        $this->assertStringNotContainsString('Admin', $header);
        $this->assertStringNotContainsString('Doctors', $header);
        $this->assertStringNotContainsString('Patients', $header);
        $this->assertStringNotContainsString(route('admin.facility'), $header);
        $this->assertStringNotContainsString(route('staff.index'), $header);
    }

    public function test_the_page_you_are_on_is_marked_in_the_navigation(): void
    {
        foreach (['home', 'about', 'contact'] as $page) {
            $this->assertStringContainsString('aria-current="page"', $this->header($page));
            $this->assertSame(2, substr_count($this->get(route($page))->getContent(), 'aria-current="page"'), 'Once in the bar and once in the phone menu.');
        }
    }

    public function test_register_your_facility_leads_straight_into_the_registration_wizard(): void
    {
        $content = $this->get(route('home'))->getContent();

        // The bar, the phone menu, the hero, the closing call to action and the footer.
        $this->assertGreaterThanOrEqual(5, substr_count($content, 'href="'.route('facility.register').'"'));

        $this->get(route('facility.register'))->assertRedirect(route('facility.register.step', 1));
        $this->get(route('facility.register.step', 1))->assertOk();
    }

    public function test_someone_already_signed_in_is_offered_their_dashboard_not_registration_or_login(): void
    {
        $header = $this->actingAs(User::factory()->create())->get(route('home'))->assertOk()->getContent();
        preg_match('/<header.*?<\/header>/s', $header, $found);

        $this->assertStringContainsString('Open dashboard', $found[0]);
        $this->assertStringNotContainsString('Register Your Facility', $found[0]);
        $this->assertStringNotContainsString('Staff Login', $found[0]);
    }

    #[DataProvider('pages')]
    public function test_the_site_uses_no_photographs_or_borrowed_imagery(string $page): void
    {
        $content = $this->get(route($page))->getContent();

        $this->assertStringNotContainsString('<img', $content);
        $this->assertStringNotContainsString('background-image', $content);
        $this->assertDoesNotMatchRegularExpression('/(unsplash|pexels|shutterstock|istock|doccure)/i', $content);
    }

    public function test_the_hero_picture_is_a_labelled_illustration_of_the_real_product(): void
    {
        $this->get(route('home'))
            ->assertSeeText('Illustration of what a patient sees on their phone')
            ->assertSeeText('6')
            ->assertSeeText('patients ahead of you')
            ->assertSeeText('#27');
    }

    #[DataProvider('pages')]
    public function test_nothing_on_the_site_is_a_made_up_statistic_rating_or_testimonial(string $page): void
    {
        $text = strip_tags($this->get(route($page))->getContent());

        $this->assertDoesNotMatchRegularExpression('/\d[\d,.]*\s?[KkM]?\+/', $text, 'A "500+" style figure.');
        $this->assertDoesNotMatchRegularExpression('/[★☆⭐]/u', $text);
        $this->assertDoesNotMatchRegularExpression('/(testimonial|customers say|trusted by|rated \d|\d+ ?\/ ?5)/i', $text);
    }

    public function test_the_numbers_shown_are_plain_facts_about_the_product(): void
    {
        $this->get(route('home'))
            ->assertSeeTextInOrder(['0', 'apps for patients to install', '1', 'private link for every visit', '5', 'moments a visit can text the patient']);
    }

    public function test_the_trust_section_states_only_what_the_software_does(): void
    {
        $this->get(route('home'))
            ->assertSeeText('Only what a visit needs')
            ->assertSeeText('Private patient links')
            ->assertSeeText('Access by role')
            ->assertSeeText('A record of every step')
            ->assertSeeText('in mind')
            ->assertDontSeeText('fully compliant')
            ->assertDontSeeText('certified');
    }

    public function test_the_about_page_is_plain_and_honest_about_being_new(): void
    {
        $this->get(route('about'))
            ->assertSeeText('Know where every patient is')
            ->assertSeeText('Reduce unnecessary waiting')
            ->assertSeeText('Keep patients informed')
            ->assertSeeText('not a medical records system')
            ->assertSeeText('CareFlow is new.')
            ->assertSeeText('we won\'t invent them');
    }

    public function test_the_footer_links_only_to_pages_that_exist(): void
    {
        $content = $this->get(route('home'))->getContent();
        preg_match('/<footer.*?<\/footer>/s', $content, $footer);
        preg_match_all('/href="([^"]+)"/', $footer[0], $links);

        foreach (array_unique($links[1]) as $href) {
            $path = parse_url($href, PHP_URL_PATH) ?: '/';
            $this->assertContains($this->get($path)->getStatusCode(), [200, 302], "The footer links to {$href}, which doesn't work.");
        }
    }
}
