@extends('layouts.dashboard')

@section('title', 'Mi perfil | MAXIMO WMS')
@section('topbar_title', 'Mi perfil')

@section('content')
    @php($user = auth()->user())
    @php($userInitials = collect(preg_split('/\s+/', trim($user->name)))->filter()->take(2)->map(fn (string $chunk) => strtoupper(substr($chunk, 0, 1)))->implode(''))

    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel de control</a>
        <span>/</span>
        <span>Mi perfil</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Mi perfil</h2>
            <span class="ops-page-meta">Actualiza tus datos personales y avatar</span>
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="item-form-layout profile-layout">
        <article class="surface-card item-form-card compact-card profile-avatar-card">
            <div class="profile-avatar-preview">
                @if ($user->avatar_url !== null)
                    <img src="{{ $user->avatar_url }}" alt="Avatar actual" class="profile-avatar-image">
                @else
                    <span class="profile-avatar-initials">{{ $userInitials }}</span>
                @endif
            </div>

            <div class="app-copy">
                <strong>{{ $user->name }}</strong>
                <p>{{ $user->email }}</p>
                <span class="status-badge status-badge--active">{{ $user->role?->name ?? 'Sin rol' }}</span>
            </div>

            @if ($user->avatar_path !== null)
                <form method="POST" action="{{ route('profile.avatar.destroy') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="button-secondary compact-button btn-compact">Eliminar avatar</button>
                </form>
            @endif
        </article>

        <article class="surface-card item-form-card compact-card">
            <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="item-form">
                @csrf
                @method('PUT')

                <div class="item-form-grid form-grid">
                    <label class="auth-field">
                        <span>Nombre</span>
                        <input type="text" name="name" value="{{ old('name', $user->name) }}" class="auth-input" required>
                        @error('name')
                            <small class="helper-text helper-text--error">{{ $message }}</small>
                        @enderror
                    </label>

                    <label class="auth-field">
                        <span>Email</span>
                        <input type="email" name="email" value="{{ old('email', $user->email) }}" class="auth-input" required>
                        @error('email')
                            <small class="helper-text helper-text--error">{{ $message }}</small>
                        @enderror
                    </label>

                    <label class="auth-field">
                        <span>Avatar</span>
                        <input type="file" name="avatar" class="auth-input" accept=".jpg,.jpeg,.png,.webp">
                        <small class="helper-text">JPG, PNG o WEBP. Maximo 2 MB.</small>
                        @error('avatar')
                            <small class="helper-text helper-text--error">{{ $message }}</small>
                        @enderror
                    </label>
                </div>

                <div class="profile-divider"></div>

                <div class="item-form-grid form-grid">
                    <label class="auth-field">
                        <span>Nueva contrasena</span>
                        <input type="password" name="password" class="auth-input" autocomplete="new-password">
                        @error('password')
                            <small class="helper-text helper-text--error">{{ $message }}</small>
                        @enderror
                    </label>

                    <label class="auth-field">
                        <span>Confirmar contrasena</span>
                        <input type="password" name="password_confirmation" class="auth-input" autocomplete="new-password">
                    </label>
                </div>

                <div class="item-form-actions action-buttons">
                    <button type="submit" class="button-primary compact-button btn-compact">Guardar cambios</button>
                </div>
            </form>
        </article>
    </section>
@endsection
