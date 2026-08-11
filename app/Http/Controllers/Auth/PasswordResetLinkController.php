<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = Str::lower(trim((string) $request->input('email')));
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('active', true)
            ->first();

        if ($user !== null) {
            $token = Password::broker()->createToken($user);
            $user->sendPasswordResetNotification($token);
        }

        return back()->with(
            'status',
            'Si el correo pertenece a una cuenta activa, recibiras un enlace para restablecer la contrasena.'
        );
    }
}
