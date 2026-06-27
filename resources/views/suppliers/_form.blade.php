@php($isEditing = $supplier->exists)

<nav class="ops-breadcrumb" aria-label="Breadcrumb">
    <a href="{{ route('dashboard') }}">Panel de control</a>
    <span>/</span>
    <span>Gestión</span>
    <span>/</span>
    <a href="{{ route('suppliers.index') }}">Proveedores</a>
    <span>/</span>
    <span>{{ $isEditing ? 'Editar' : 'Crear' }}</span>
</nav>

<div class="surface-card item-form-card entity-form compact-card">
    <div class="app-copy">
        <span class="status-chip small-badge badge-compact">{{ $isEditing ? 'Edicion' : 'Alta' }}</span>
        <h2 class="ops-page-title page-title-compact">{{ $isEditing ? 'Editar proveedor' : 'Nuevo proveedor' }}</h2>
        <p>Configura proveedores globales o vinculados a un cliente para las futuras entradas de mercancía.</p>
    </div>

    <form method="POST" action="{{ $isEditing ? route('suppliers.update', $supplier) : route('suppliers.store') }}" class="item-form">
        @csrf
        @if ($isEditing)
            @method('PUT')
        @endif

        <div class="form-grid">
            <label class="auth-field">
                <span>Cliente</span>
                <select name="client_id" class="auth-input">
                    <option value="">Global MAXIMO</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected((string) old('client_id', $supplier->client_id) === (string) $client->id)>
                            {{ $client->name }}
                        </option>
                    @endforeach
                </select>
                @error('client_id')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field">
                <span>Nombre</span>
                <input type="text" name="name" value="{{ old('name', $supplier->name) }}" class="auth-input" maxlength="255" required>
                @error('name')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field">
                <span>CIF / NIF</span>
                <input type="text" name="tax_id" value="{{ old('tax_id', $supplier->tax_id) }}" class="auth-input" maxlength="100">
                @error('tax_id')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field">
                <span>Contacto</span>
                <input type="text" name="contact_name" value="{{ old('contact_name', $supplier->contact_name) }}" class="auth-input" maxlength="255">
                @error('contact_name')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field">
                <span>Email</span>
                <input type="email" name="email" value="{{ old('email', $supplier->email) }}" class="auth-input" maxlength="255">
                @error('email')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field">
                <span>Telefono</span>
                <input type="text" name="phone" value="{{ old('phone', $supplier->phone) }}" class="auth-input" maxlength="100">
                @error('phone')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>

            <label class="auth-field item-form-field--full">
                <span>Notas</span>
                <textarea name="notes" rows="4" class="auth-input">{{ old('notes', $supplier->notes) }}</textarea>
                @error('notes')
                    <small class="form-error">{{ $message }}</small>
                @enderror
            </label>
        </div>

        <label class="toggle-field">
            <input type="hidden" name="active" value="0">
            <input type="checkbox" name="active" value="1" @checked(old('active', $supplier->active ?? true))>
            <span>Proveedor activo para nuevas entradas y seleccion en formularios</span>
        </label>

        <div class="item-form-actions action-buttons">
            <a href="{{ route('suppliers.index') }}" class="button-secondary compact-button btn-compact">Cancelar</a>
            <button type="submit" class="button-primary compact-button btn-compact">
                {{ $isEditing ? 'Guardar cambios' : 'Crear proveedor' }}
            </button>
        </div>
    </form>
</div>
