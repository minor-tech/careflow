<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The signed-in user's facility. Platform admins belong to none, and have
     * no facility to manage here.
     */
    protected function facility(Request $request): Facility
    {
        return $request->user()->facility ?? abort(404);
    }
}
