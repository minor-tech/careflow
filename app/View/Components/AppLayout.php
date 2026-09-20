<?php

namespace App\View\Components;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    /**
     * The sidebar for the signed-in user's role, in labelled groups. Groups with nothing in them for the role are left out.
     *
     * @var list<array{label: string, items: list<array{label: string, route: string, icon: string, active: bool}>}>
     */
    public array $navGroups = [];

    /**
     * The small line above the page title: the sidebar group the page belongs to, unless the page names its own.
     */
    public string $crumbLabel = 'CareFlow';

    public function __construct(public ?string $title = null, ?string $crumb = null)
    {
        $user = auth()->user();

        if ($user !== null) {
            $this->navGroups = $this->navigationFor($user);
        }

        $activeGroup = collect($this->navGroups)
            ->first(fn (array $group): bool => collect($group['items'])->contains('active', true));

        $this->crumbLabel = $crumb ?? $activeGroup['label'] ?? 'CareFlow';
    }

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.app');
    }

    /**
     * @return list<array{label: string, items: list<array{label: string, route: string, icon: string, active: bool}>}>
     */
    private function navigationFor(User $user): array
    {
        $groups = match ($user->role) {
            UserRole::Admin => [
                'Overview' => [
                    ['label' => 'Facility', 'route' => 'admin.facility', 'icon' => 'facility', 'match' => 'admin.facility'],
                    ['label' => 'Analytics', 'route' => 'analytics.index', 'icon' => 'analytics', 'match' => 'analytics.*'],
                ],
                'Patient flow' => [
                    ['label' => 'Queue', 'route' => 'queue.index', 'icon' => 'queue', 'match' => 'queue.*'],
                    ['label' => 'Register patient', 'route' => 'patients.register', 'icon' => 'register', 'match' => 'patients.*'],
                    ['label' => 'Notifications', 'route' => 'notifications.index', 'icon' => 'bell', 'match' => 'notifications.*'],
                ],
                'Staff & structure' => [
                    ['label' => 'Staff', 'route' => 'staff.index', 'icon' => 'staff', 'match' => 'staff.*'],
                    ['label' => 'Departments', 'route' => 'departments.index', 'icon' => 'grid', 'match' => 'departments.*'],
                    ['label' => 'Settings', 'route' => 'admin.settings', 'icon' => 'settings', 'match' => 'admin.settings'],
                ],
            ],
            UserRole::Receptionist => [
                'Patient flow' => [
                    ['label' => 'Queue', 'route' => 'queue.index', 'icon' => 'queue', 'match' => 'queue.*'],
                    ['label' => 'Register patient', 'route' => 'patients.register', 'icon' => 'register', 'match' => 'patients.*'],
                ],
            ],
            UserRole::Doctor, UserRole::Nurse => [
                'Patient flow' => [
                    ['label' => 'Queue', 'route' => 'queue.index', 'icon' => 'queue', 'match' => 'queue.*'],
                ],
            ],
            UserRole::SystemAdmin => [
                'Platform' => [
                    ['label' => 'Pending facilities', 'route' => 'system.facilities.pending', 'icon' => 'facility', 'match' => 'system.*'],
                ],
            ],
        };

        $navGroups = [];

        foreach ($groups as $label => $items) {
            $navGroups[] = [
                'label' => $label,
                'items' => array_map(fn (array $item): array => [
                    'label' => $item['label'],
                    'route' => $item['route'],
                    'icon' => $item['icon'],
                    'active' => request()->routeIs($item['match']),
                ], $items),
            ];
        }

        return $navGroups;
    }
}
