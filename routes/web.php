<?php

use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\ModulePlaceholderController;
use App\Http\Controllers\StockController;
use App\Models\Role;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(Auth::check() ? 'dashboard' : 'login');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');

    Route::get('/solicitar-acceso', [AccessRequestController::class, 'create'])->name('access-requests.create');
    Route::post('/solicitar-acceso', [AccessRequestController::class, 'store'])->name('access-requests.store');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/articulos', [ItemController::class, 'index'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('items.index');
    Route::get('/articulos/crear', [ItemController::class, 'create'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('items.create');
    Route::post('/articulos', [ItemController::class, 'store'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('items.store');
    Route::get('/articulos/{item}/editar', [ItemController::class, 'edit'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('items.edit');
    Route::put('/articulos/{item}', [ItemController::class, 'update'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('items.update');
    Route::patch('/articulos/{item}/activar-desactivar', [ItemController::class, 'toggleActive'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('items.toggle-active');

    Route::get('/stock', [StockController::class, 'index'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('stock.index');
    Route::get('/palets', ModulePlaceholderController::class)
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->defaults('module', 'pallets')
        ->name('modules.pallets');
    Route::get('/ubicaciones', ModulePlaceholderController::class)
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->defaults('module', 'locations')
        ->name('modules.locations');

    Route::get('/solicitudes', ModulePlaceholderController::class)
        ->middleware('minimum.role:'.Role::CLIENTE)
        ->defaults('module', 'solicitudes')
        ->name('modules.requests');

    Route::get('/entradas', ModulePlaceholderController::class)
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->defaults('module', 'entradas')
        ->name('modules.inbound');

    Route::get('/salidas', ModulePlaceholderController::class)
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->defaults('module', 'salidas')
        ->name('modules.outbound');

    Route::get('/clientes', ModulePlaceholderController::class)
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->defaults('module', 'clientes')
        ->name('modules.clients');

    Route::get('/almacenes', ModulePlaceholderController::class)
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->defaults('module', 'almacenes')
        ->name('modules.warehouses');

    Route::get('/usuarios', ModulePlaceholderController::class)
        ->middleware('minimum.role:'.Role::SUPERADMIN)
        ->defaults('module', 'usuarios')
        ->name('modules.users');

    Route::get('/auditoria', ModulePlaceholderController::class)
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->defaults('module', 'auditoria')
        ->name('modules.audit');

    Route::get('/backups', ModulePlaceholderController::class)
        ->middleware('minimum.role:'.Role::SUPERADMIN)
        ->defaults('module', 'backups')
        ->name('modules.backups');
});
