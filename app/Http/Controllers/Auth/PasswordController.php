<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user = $request->user();

        // Choosing a password of your own ends the "temporary password" state,
        // but "changing" it to the very same one doesn't count.
        $isNewPassword = ! Hash::check($validated['password'], $user->password);

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => $user->must_change_password && ! $isNewPassword,
        ])->save();

        return back()->with('status', 'password-updated');
    }
}
