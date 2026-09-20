<?php

namespace App\Support;

use Illuminate\Support\Facades\Hash;

/**
 * Session-backed progress of the facility registration wizard, so an
 * interrupted registration (a receptionist on a phone) can be resumed.
 */
class RegistrationDraft
{
    private const KEY = 'facility_registration';

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return session(self::KEY.'.data', []);
    }

    /**
     * @return list<int>
     */
    public function completedSteps(): array
    {
        return session(self::KEY.'.completed', []);
    }

    /**
     * Store a screen's validated input and mark the screen completed.
     *
     * @param  array<string, mixed>  $validated
     */
    public function save(int $step, array $validated): void
    {
        if ($step === 5) {
            $validated = $this->withHashedPassword($validated);
        }

        session([
            self::KEY.'.data' => array_merge($this->data(), $validated),
            self::KEY.'.completed' => array_values(array_unique([...$this->completedSteps(), $step])),
        ]);
    }

    /**
     * The first screen not yet completed. A screen can only be opened once
     * every screen before it is done.
     */
    public function resumeStep(): int
    {
        for ($step = 1; $step < FacilityRegistrationSteps::LAST; $step++) {
            if (! in_array($step, $this->completedSteps(), true)) {
                return $step;
            }
        }

        return FacilityRegistrationSteps::LAST;
    }

    public function canOpen(int $step): bool
    {
        return $step <= $this->resumeStep();
    }

    public function hasPassword(): bool
    {
        return filled($this->data()['admin_password_hash'] ?? null);
    }

    public function clear(): void
    {
        session()->forget(self::KEY);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function withHashedPassword(array $validated): array
    {
        $password = $validated['admin_password'] ?? null;

        unset($validated['admin_password'], $validated['admin_password_confirmation']);

        if (filled($password)) {
            $validated['admin_password_hash'] = Hash::make($password);
        }

        return $validated;
    }
}
