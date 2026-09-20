<?php

namespace Tests\Feature\View\Components;

use App\Enums\JourneyStepState;
use App\Support\JourneyStep;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class VisitJourneyComponentTest extends TestCase
{
    /**
     * @param  list<JourneyStep>  $steps
     */
    private function render(array $steps, bool $open = true): string
    {
        return Blade::render('<x-visit-journey :steps="$steps" :open="$open" />', ['steps' => $steps, 'open' => $open]);
    }

    public function test_shows_where_the_patient_has_been_and_where_they_are_with_their_number_there(): void
    {
        $html = $this->render([
            new JourneyStep('Reception', JourneyStepState::Done),
            new JourneyStep('Consultation', JourneyStepState::Done),
            new JourneyStep('Laboratory', JourneyStepState::Current, 'L-8'),
        ]);

        $this->assertStringContainsString('Done: </span>Reception', $html);
        $this->assertStringContainsString('Done: </span>Consultation', $html);
        $this->assertStringContainsString('Now: </span>Laboratory', $html);
        $this->assertStringContainsString('Your number:', $html);
        $this->assertStringContainsString('L-8', $html);
        $this->assertSame(1, substr_count($html, 'Your number:'));
    }

    public function test_a_finished_stop_shows_a_check_and_the_current_one_an_amber_dot(): void
    {
        $html = $this->render([
            new JourneyStep('Reception', JourneyStepState::Done),
            new JourneyStep('Consultation', JourneyStepState::Current, 'C-2'),
        ]);

        $this->assertSame(1, substr_count($html, 'text-ok'));
        $this->assertSame(1, substr_count($html, 'bg-wait'));
    }

    public function test_while_the_visit_is_open_it_says_further_steps_are_not_decided_yet(): void
    {
        $html = $this->render([new JourneyStep('Reception', JourneyStepState::Current, '#27')], open: true);

        $this->assertStringContainsString('Further steps appear once your doctor decides them.', $html);
    }

    public function test_once_the_visit_is_over_nothing_more_is_promised(): void
    {
        $html = $this->render([new JourneyStep('Pharmacy', JourneyStepState::Done)], open: false);

        $this->assertStringNotContainsString('Further steps', $html);
    }

    public function test_a_cancelled_stop_is_marked_as_such_for_screen_readers(): void
    {
        $html = $this->render([new JourneyStep('Consultation', JourneyStepState::Cancelled)], open: false);

        $this->assertStringContainsString('Cancelled: </span>Consultation', $html);
        $this->assertStringContainsString('text-danger', $html);
    }

    public function test_department_names_are_escaped(): void
    {
        $html = $this->render([new JourneyStep('<script>alert(1)</script>', JourneyStepState::Current)]);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
