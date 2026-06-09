<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\BrevoMailConfigurationException;
use App\Http\Controllers\Controller;
use App\Services\BrevoMailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
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

        try {
            app(BrevoMailService::class)->assertConfigured();
        } catch (BrevoMailConfigurationException $exception) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors([
                    'email' => 'El sistema de correo no esta configurado correctamente. Contacta con administracion.',
                ]);
        }

        Password::sendResetLink($request->only('email'));

        return back()->with(
            'status',
            'Si el correo pertenece a una cuenta activa, recibiras un enlace para restablecer la contrasena.'
        );
    }
}
