<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                // Always read fresh: the balance changes in the background as generations settle.
                'balanceNanos' => $user ? (int) $user->newQuery()->whereKey($user->id)->value('balance_nanos') : null,
                'isAdmin' => $user?->isAdmin() ?? false,
            ],
            // Each flashed message gets a fresh id, so the page can tell a new message from an old one that is
            // still sitting in the props (partial reloads keep the previous value).
            'flash' => function () use ($request) {
                $success = $request->session()->get('success');

                return ['success' => $success, 'id' => $success === null ? null : (string) Str::uuid()];
            },
        ];
    }
}
