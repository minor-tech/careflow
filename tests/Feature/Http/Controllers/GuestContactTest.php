<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\ContactMessage;
use App\Notifications\ContactMessageReceived;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GuestContactTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Wanjiru Kamau',
            'contact' => 'wanjiru@upendo.example',
            'facility_name' => 'Upendo Health Centre',
            'message' => 'We would like to try CareFlow at our clinic in Nakuru.',
            ...$overrides,
        ];
    }

    public function test_the_page_has_the_four_fields_and_needs_no_login(): void
    {
        $this->assertGuest();

        $this->get(route('contact'))
            ->assertOk()
            ->assertSee('name="name"', false)
            ->assertSee('name="contact"', false)
            ->assertSee('name="facility_name"', false)
            ->assertSee('name="message"', false)
            ->assertSeeText('Facility name (optional)')
            ->assertSeeText('Email or phone number')
            ->assertSee('name="_token"', false);
    }

    public function test_a_message_is_saved_and_the_sender_is_thanked(): void
    {
        $this->post(route('contact.store'), $this->payload())
            ->assertRedirect(route('contact'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $message = ContactMessage::sole();
        $this->assertSame('Wanjiru Kamau', $message->name);
        $this->assertSame('wanjiru@upendo.example', $message->contact);
        $this->assertSame('Upendo Health Centre', $message->facility_name);
        $this->assertSame('We would like to try CareFlow at our clinic in Nakuru.', $message->message);
        $this->assertNotNull($message->created_at);

        $this->followingRedirects()->get(route('contact'))->assertSeeText("Thanks, we've got your message");
    }

    public function test_the_facility_name_is_optional(): void
    {
        $this->post(route('contact.store'), $this->payload(['facility_name' => '']))->assertSessionHasNoErrors();

        $this->assertNull(ContactMessage::sole()->facility_name);
    }

    public function test_a_phone_number_can_be_given_instead_of_an_email_and_is_kept_in_standard_form(): void
    {
        $this->post(route('contact.store'), $this->payload(['contact' => '0712 345 678']))->assertSessionHasNoErrors();

        $this->assertSame('+254712345678', ContactMessage::sole()->contact);
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function mistakes(): array
    {
        return [
            'no name' => ['name', ['name' => '']],
            'a very long name' => ['name', ['name' => str_repeat('a', 121)]],
            'no way to reply' => ['contact', ['contact' => '']],
            'neither email nor phone' => ['contact', ['contact' => 'call me maybe']],
            'a broken email' => ['contact', ['contact' => 'wanjiru@']],
            'a broken phone' => ['contact', ['contact' => '0712 34']],
            'no message' => ['message', ['message' => '']],
            'a one-word message' => ['message', ['message' => 'Hi']],
            'a huge message' => ['message', ['message' => str_repeat('a', 5001)]],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('mistakes')]
    public function test_a_mistake_keeps_nothing_and_says_what_to_fix(string $field, array $overrides): void
    {
        $this->post(route('contact.store'), $this->payload($overrides))
            ->assertRedirect()
            ->assertSessionHasErrors($field);

        $this->assertSame(0, ContactMessage::count());
    }

    public function test_after_a_mistake_the_form_shows_the_message_and_keeps_what_was_typed(): void
    {
        $this->followingRedirects()
            ->from(route('contact'))
            ->post(route('contact.store'), $this->payload(['contact' => 'nonsense']))
            ->assertSeeText('Enter a valid email address or phone number.')
            ->assertSee('value="Wanjiru Kamau"', false)
            ->assertSeeText('We would like to try CareFlow at our clinic in Nakuru.');
    }

    public function test_a_message_is_shown_as_text_never_as_markup(): void
    {
        $this->followingRedirects()
            ->from(route('contact'))
            ->post(route('contact.store'), $this->payload(['name' => '<script>alert(1)</script>', 'contact' => 'bad']))
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_a_form_filled_in_by_a_bot_looks_like_it_worked_but_keeps_nothing(): void
    {
        Notification::fake();

        $this->post(route('contact.store'), $this->payload(['website' => 'https://spam.example']))
            ->assertRedirect(route('contact'))
            ->assertSessionHas('status');

        $this->assertSame(0, ContactMessage::count());
        Notification::assertNothingSent();
    }

    public function test_the_hidden_bot_trap_is_on_the_page_and_out_of_sight_of_people(): void
    {
        $this->get(route('contact'))
            ->assertSee('name="website"', false)
            ->assertSee('tabindex="-1"', false)
            ->assertSee('aria-hidden="true"', false);
    }

    public function test_when_a_support_address_is_set_the_team_is_emailed_too(): void
    {
        Notification::fake();
        config(['careflow.support_email' => 'support@careflow.test']);

        $this->post(route('contact.store'), $this->payload());

        Notification::assertSentOnDemand(ContactMessageReceived::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'support@careflow.test');
        $this->assertSame(1, ContactMessage::count());
    }

    public function test_the_email_carries_who_wrote_how_to_reach_them_and_what_they_said(): void
    {
        $message = ContactMessage::factory()->create(['name' => 'Wanjiru Kamau', 'contact' => '+254712345678', 'facility_name' => 'Upendo Health Centre', 'message' => 'Please call me.']);

        $mail = (new ContactMessageReceived($message))->toMail(new \stdClass);
        $body = collect($mail->introLines)->implode("\n");

        $this->assertSame('CareFlow contact form: Wanjiru Kamau', $mail->subject);
        foreach (['Wanjiru Kamau', '+254712345678', 'Upendo Health Centre', 'Please call me.'] as $expected) {
            $this->assertStringContainsString($expected, $body);
        }
    }

    public function test_with_no_support_address_nothing_is_emailed_and_the_message_is_still_saved(): void
    {
        Notification::fake();
        config(['careflow.support_email' => null]);

        $this->post(route('contact.store'), $this->payload())->assertSessionHas('status');

        Notification::assertNothingSent();
        $this->assertSame(1, ContactMessage::count());
    }

    public function test_a_mail_failure_never_loses_the_message_or_shows_an_error(): void
    {
        Exceptions::fake();
        config(['careflow.support_email' => 'support@careflow.test']);
        $this->mock(Dispatcher::class)->shouldReceive('send')->andThrow(new RuntimeException('smtp is down'));

        $this->post(route('contact.store'), $this->payload())
            ->assertRedirect(route('contact'))
            ->assertSessionHas('status');

        $this->assertSame(1, ContactMessage::count());
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'smtp is down');
    }

    public function test_the_support_address_is_shown_on_the_page_only_when_there_is_one(): void
    {
        config(['careflow.support_email' => null]);
        $this->get(route('contact'))->assertDontSee('mailto:');

        config(['careflow.support_email' => 'support@careflow.test']);
        $this->get(route('contact'))->assertSee('mailto:support@careflow.test', false);
    }

    public function test_the_form_is_limited_to_a_few_sends_a_minute(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->post(route('contact.store'), $this->payload());
        }

        $this->post(route('contact.store'), $this->payload())->assertTooManyRequests();
        $this->assertSame(5, ContactMessage::count());
    }

    public function test_the_stored_message_records_when_it_arrived_and_is_never_updated(): void
    {
        $message = ContactMessage::factory()->create();

        $this->assertNotNull($message->created_at);
        $this->assertNull($message->updated_at);
    }
}
