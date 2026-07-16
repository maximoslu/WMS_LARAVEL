<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Activity\UserActivityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function __construct(private readonly UserActivityService $activity) {}

    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt([
            ...$credentials,
            'active' => true,
        ], $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'No hemos podido validar tus credenciales. Revisa el email y la contraseña.',
            ]);
        }

        $request->session()->regenerate();

        $user = $request->user();

        if ($user instanceof User) {
            $this->activity->startSession($request, $user);
        }

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->activity->closeSession($request, $user);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
