<?php

namespace Tests\Feature\View\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class AvatarComponentTest extends TestCase
{
    public function test_shows_a_gray_person_silhouette_when_there_is_no_photo(): void
    {
        $html = Blade::render('<x-avatar />');

        $this->assertStringContainsString('cf-person-avatar', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_shows_the_photo_when_there_is_one(): void
    {
        $html = Blade::render('<x-avatar src="/storage/staff/amina.jpg" />');

        $this->assertStringContainsString('<img src="/storage/staff/amina.jpg"', $html);
        $this->assertStringNotContainsString('<svg', $html);
    }
}
