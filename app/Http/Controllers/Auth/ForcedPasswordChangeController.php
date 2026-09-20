<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ForcedPasswordChangeController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return redirect()->route('dashboard');
        }

        return view('auth.force-password-change');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->must_change_password) {
            return redirect()->route('dashboard');
        }

        $request->validate([
            'password' => [
                'required',
                'confirmed',
                Password::min(8)->letters()->numbers(),
                function (string $attribute, mixed $value, Closure $fail) use ($user) {
                    if (Hash::check($value, $user->password)) {
                        $fail('Choose a password different from the temporary one.');
                    }
                },
            ],
        ]);

        $user->forceFill([
            'password' => $request->string('password')->toString(),
            'must_change_password' => false,
        ])->save();

        // The credential changed, so the session gets a new id.
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
