<?php

namespace Tests\Feature\View\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class StatCardComponentTest extends TestCase
{
    public function test_renders_the_number_before_its_label_with_the_tone_and_icon(): void
    {
        $html = Blade::render('<x-stat-card number="12" label="Waiting" icon="clock" tone="gold" />');

        $this->assertStringContainsString('cf-stat-card--gold', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('<div class="cf-stat-card__number">12</div>', $html);
        $this->assertStringContainsString('<div class="cf-stat-card__label">Waiting</div>', $html);
        $this->assertLessThan(strpos($html, 'Waiting'), strpos($html, '>12<'));
    }

    public function test_is_a_plain_block_without_a_link_and_the_primary_tone_by_default(): void
    {
        $html = Blade::render('<x-stat-card number="3" label="Staff accounts" icon="staff" />');

        $this->assertStringStartsWith('<div', trim($html));
        $this->assertStringNotContainsString('href=', $html);
        $this->assertStringContainsString('cf-stat-card--primary', $html);
    }

    public function test_becomes_a_link_when_given_an_href(): void
    {
        $html = Blade::render('<x-stat-card number="3" label="Departments" icon="grid" tone="sage" href="/departments" />');

        $this->assertStringStartsWith('<a href="/departments"', trim($html));
        $this->assertStringEndsWith('</a>', trim($html));
    }
}
