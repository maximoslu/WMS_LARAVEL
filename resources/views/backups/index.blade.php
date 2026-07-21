@extends('layouts.dashboard')

@section('title', 'Backups | MAXIMO WMS')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Sistema'],
            ['label' => 'Backups'],
        ];
    @endphp

    <x-breadcrumbs :items="$breadcrumbs" />

    @if (session('status'))
        <div class="alert-success">{{ session('status') }}</div>
    @endif

    @if (session('warning'))
        <div class="alert-warning">{{ session('warning') }}</div>
    @endif

    <section class="backups-header surface-card compact-card">
        <div class="app-copy">
            <span class="module-tag small-badge">Infraestructura</span>
            <h2 class="ops-page-title">Backups</h2>
            <p>Copias de seguridad del sistema, datos operativos y stock.</p>
        </div>

        <div class="backups-header-facts">
            <span>Privado: {{ $disk }}:/{{ $path }}</span>
            <span>Retencion stock: {{ $retentionDays }} dias</span>
            <span>Solo superadmin</span>
        </div>
    </section>

    <section class="backups-grid">
        <article class="surface-card compact-card backup-panel">
            <div class="backup-panel-header">
                <div>
                    <h3>Crear copia manual</h3>
                    <p>Elige el bloque que necesitas y descarga el archivo generado.</p>
                </div>
            </div>

            <form method="POST" action="{{ route('backups.store') }}" class="backup-form" data-backup-form>
                @csrf

                <label class="form-field">
                    <span>Tipo de copia</span>
                    <select name="type" data-backup-type required>
                        @foreach ($backupTypes as $type => $label)
                            <option value="{{ $type }}" @selected(old('type') === $type)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('type')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>

                <label class="form-field" data-backup-client-field>
                    <span>Cliente</span>
                    <select name="client_id">
                        <option value="">Seleccionar cliente</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}" @selected((string) old('client_id') === (string) $client->id)>
                                {{ $client->name }} ({{ $client->code }})
                            </option>
                        @endforeach
                    </select>
                    @error('client_id')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>

                <div class="backup-safety">
                    <span>Los backups no incluyen .env ni secretos.</span>
                    <span>Los archivos se guardan fuera de public.</span>
                </div>

                <button type="submit" class="button-primary compact-button">Generar backup</button>
            </form>
        </article>

        <article class="surface-card compact-card backup-panel">
            <div class="backup-panel-header">
                <div>
                    <h3>Snapshots diarios de stock</h3>
                    <p>Historial consultable por cliente y fecha, con un archivo por cliente y dia.</p>
                </div>
            </div>

            <div class="backup-summary-grid">
                <div>
                    <span>Estado</span>
                    <strong>{{ $snapshotSummary['active'] ? 'Activo' : 'Inactivo' }}</strong>
                </div>
                <div>
                    <span>Clientes incluidos</span>
                    <strong>{{ $snapshotSummary['clients'] }}</strong>
                </div>
                <div>
                    <span>Ultima ejecucion</span>
                    <strong>{{ $snapshotSummary['latest']?->format('d/m/Y H:i') ?? 'Sin ejecuciones' }}</strong>
                </div>
                <div>
                    <span>Retencion</span>
                    <strong>{{ $retentionDays }} dias</strong>
                </div>
            </div>

            <div class="backup-scheduler-note">
                <strong>Scheduler</strong>
                <span>Forge debe ejecutar <code>php artisan schedule:run</code> para activar las copias diarias.</span>
            </div>
        </article>
    </section>

    <section class="surface-card compact-card backup-panel">
        <div class="backup-panel-header">
            <div>
                <h3>Backups recientes</h3>
                <p>Registro de generaciones manuales y snapshots automaticos.</p>
            </div>
            <span class="ops-status badge-compact">{{ $backups->count() }} registros</span>
        </div>

        <div class="table-scroll">
            <table class="data-table backups-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Tipo</th>
                        <th>Cliente</th>
                        <th>Estado</th>
                        <th>Tamano</th>
                        <th>Creado por</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($backups as $backup)
                        <tr>
                            <td>{{ $backup->created_at?->format('d/m/Y H:i') }}</td>
                            <td>{{ $backup->typeLabel() }}</td>
                            <td>{{ $backup->client?->name ?? '-' }}</td>
                            <td>
                                <span class="ops-status badge-compact backup-status backup-status--{{ $backup->status }}">
                                    {{ $backup->statusLabel() }}
                                </span>
                            </td>
                            <td>{{ $backup->formattedSize() }}</td>
                            <td>{{ $backup->creator?->name ?? 'Sistema' }}</td>
                            <td>
                                <div class="action-buttons backup-actions">
                                    @if ($backup->isCompleted())
                                        <a href="{{ route('backups.download', $backup) }}" class="button-secondary compact-button">Descargar</a>
                                    @endif
                                    <form method="POST" action="{{ route('backups.destroy', $backup) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="button-secondary compact-button">Eliminar</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="table-empty">Todavia no hay backups generados.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.querySelector('[data-backup-form]');
            const type = form?.querySelector('[data-backup-type]');
            const clientField = form?.querySelector('[data-backup-client-field]');

            const syncClientField = () => {
                if (!type || !clientField) {
                    return;
                }

                clientField.hidden = type.value !== 'stock-client';
            };

            type?.addEventListener('change', syncClientField);
            syncClientField();
        });
    </script>
@endsection
