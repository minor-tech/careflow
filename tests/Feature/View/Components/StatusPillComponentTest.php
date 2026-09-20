<?php

namespace Tests\Feature\View\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class StatusPillComponentTest extends TestCase
{
    public function test_shows_the_label_in_the_given_tone(): void
    {
        $html = Blade::render('<x-status-pill label="Pending review" tone="wait" />');

        $this->assertStringContainsString('cf-status-pill--wait', $html);
        $this->assertStringContainsString('>Pending review</span>', $html);
        $this->assertStringNotContainsString('cf-status-pill--sm', $html);
    }

    public function test_is_the_ok_tone_by_default(): void
    {
        $this->assertStringContainsString('cf-status-pill--ok', Blade::render('<x-status-pill label="Active" />'));
    }

    public function test_small_size_is_for_list_rows(): void
    {
        $html = Blade::render('<x-status-pill label="Suspended" tone="danger" size="sm" />');

        $this->assertStringContainsString('cf-status-pill--sm', $html);
        $this->assertStringContainsString('cf-status-pill--danger', $html);
    }

    public function test_escapes_the_label(): void
    {
        $html = Blade::render('<x-status-pill :label="$label" />', ['label' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $html);
    }
}
