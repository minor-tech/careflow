<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The frame of the public marketing pages: navigation, footer, and the meta
 * tags a page someone finds through a search needs.
 */
class SiteLayout extends Component
{
    public function render(): View
    {
        return view('layouts.site');
    }
}
