<?php

namespace App\Http\Controllers\Admin;

use App\Actions\CreateStaffMember;
use App\Actions\ResetStaffPassword;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStaffRequest;
use App\Http\Requests\Admin\UpdateStaffRequest;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class StaffController extends Controller
{
    public function index(Request $request): Response
    {
        $staff = $this->facility($request)
            ->users()
            ->with('department')
            ->orderBy('role')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $revealed = $this->revealedPassword($request);

        $response = response()->view('staff.index', ['staff' => $staff, 'revealed' => $revealed]);

        // A page showing a password must not be kept by the browser or a proxy.
        return $revealed === null ? $response : $response->header('Cache-Control', 'no-store, private');
    }

    public function create(Request $request): View
    {
        return view('staff.create', $this->formOptions($this->facility($request)));
    }

    /**
     * Create the account and send the admin back to the list, where the
     * temporary password is shown once, for them to pass on to the new
     * staff member. It is in the page, not stored anywhere readable, and a
     * refresh will not bring it back.
     */
    public function store(StoreStaffRequest $request, CreateStaffMember $createStaffMember): RedirectResponse
    {
        $account = $createStaffMember->handle($this->facility($request), $request->validated());

        return $this->backWithPassword($account->user, $account->temporaryPassword, created: true);
    }

    public function edit(Request $request, User $staff): View
    {
        Gate::authorize('update', $staff);

        return view('staff.edit', [
            'member' => $staff,
            ...$this->formOptions($this->facility($request)),
        ]);
    }

    public function update(UpdateStaffRequest $request, User $staff): RedirectResponse
    {
        $staff->update($request->validated());

        return redirect()->route('staff.index')->with('success', "{$staff->name}'s account was updated.");
    }

    /**
     * Give someone a new temporary password (shown once, like the first).
     */
    public function resetPassword(User $staff, ResetStaffPassword $resetStaffPassword): RedirectResponse
    {
        Gate::authorize('update', $staff);

        return $this->backWithPassword($staff, $resetStaffPassword->handle($staff), created: false);
    }

    /**
     * "Removing" someone suspends them. Their account stays, so the visit log
     * (who called, started and completed what) keeps pointing at a real person.
     * Bring them back by setting the status to active when editing.
     */
    public function destroy(User $staff): RedirectResponse
    {
        Gate::authorize('delete', $staff);

        $staff->update(['status' => UserStatus::Suspended]);

        return redirect()
            ->route('staff.index')
            ->with('success', "{$staff->name} was suspended. They can no longer log in, and their history is kept.");
    }

    /**
     * @return array{roles: array<string, string>, departments: array<int, string>}
     */
    private function formOptions(Facility $facility): array
    {
        return [
            'roles' => UserRole::staffOptions(),
            'departments' => $facility->departments()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    /**
     * Send the admin to the staff list with a password to show them once. It
     * goes in the session for the next request only, and encrypted, so it is
     * never sitting readable in the session store either.
     */
    private function backWithPassword(User $member, string $password, bool $created): RedirectResponse
    {
        return redirect()->route('staff.index')->with('temporary_password', [
            'name' => $member->name,
            'password' => Crypt::encryptString($password),
            'created' => $created,
        ]);
    }

    /**
     * @return array{name: string, password: string, created: bool}|null
     */
    private function revealedPassword(Request $request): ?array
    {
        $flashed = $request->session()->get('temporary_password');

        if (! is_array($flashed)) {
            return null;
        }

        try {
            return [...$flashed, 'password' => Crypt::decryptString($flashed['password'])];
        } catch (DecryptException) {
            return null;
        }
    }
}
