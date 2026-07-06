<?php

use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\AjaxSearchController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DailyOperationController;
use App\Http\Controllers\GoodsDispatchController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\GoogleCalendarOAuthController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MerchandiseRequestController;
use App\Http\Controllers\ModulePlaceholderController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockImportController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\WarehouseController;
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
    Route::get('/ajax/items', [AjaxSearchController::class, 'items'])
        ->name('ajax.items');
    Route::get('/ajax/stock-variants', [AjaxSearchController::class, 'stockVariants'])
        ->name('ajax.stock-variants');
    Route::get('/ajax/clients', [AjaxSearchController::class, 'clients'])
        ->name('ajax.clients');
    Route::get('/ajax/locations', [AjaxSearchController::class, 'locations'])
        ->name('ajax.locations');
    Route::get('/ajax/lots', [AjaxSearchController::class, 'lots'])
        ->name('ajax.lots');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/google-calendar/oauth/redirect', [GoogleCalendarOAuthController::class, 'redirect'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('google-calendar.oauth.redirect');
    Route::get('/google-calendar/oauth/callback', [GoogleCalendarOAuthController::class, 'callback'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('google-calendar.oauth.callback');
    Route::post('/google-calendar/oauth/disconnect', [GoogleCalendarOAuthController::class, 'disconnect'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('google-calendar.oauth.disconnect');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/perfil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/perfil', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/perfil/avatar', [ProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');
    Route::get('/notificaciones', [NotificationController::class, 'index'])
        ->name('notifications.index');
    Route::patch('/notificaciones/{notification}/leer', [NotificationController::class, 'markAsRead'])
        ->name('notifications.read');

    Route::get('/articulos', [ItemController::class, 'index'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('items.index');
    Route::get('/articulos/crear', [ItemController::class, 'create'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('items.create');
    Route::post('/articulos', [ItemController::class, 'store'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('items.store');
    Route::get('/articulos/{item}/editar', [ItemController::class, 'edit'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('items.edit');
    Route::put('/articulos/{item}', [ItemController::class, 'update'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('items.update');
    Route::patch('/articulos/{item}/activar-desactivar', [ItemController::class, 'toggleActive'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('items.toggle-active');

    Route::get('/stock', [StockController::class, 'index'])
        ->middleware('minimum.role:'.Role::CLIENTE)
        ->name('stock.index');
    Route::get('/stock/partidas/{stockPallet}/editar', [StockController::class, 'edit'])
        ->middleware('minimum.role:'.Role::SUPERADMIN)
        ->name('stock.batches.edit');
    Route::put('/stock/partidas/{stockPallet}', [StockController::class, 'update'])
        ->middleware('minimum.role:'.Role::SUPERADMIN)
        ->name('stock.batches.update');
    Route::get('/stock/importar', [StockImportController::class, 'index'])
        ->middleware('minimum.role:'.Role::SUPERADMIN)
        ->name('stock.import');
    Route::post('/stock/importar/previsualizar', [StockImportController::class, 'preview'])
        ->middleware('minimum.role:'.Role::SUPERADMIN)
        ->name('stock.import.preview');
    Route::post('/stock/importar/confirmar', [StockImportController::class, 'confirm'])
        ->middleware('minimum.role:'.Role::SUPERADMIN)
        ->name('stock.import.confirm');
    Route::get('/ubicaciones', [LocationController::class, 'index'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('locations.index');
    Route::get('/ubicaciones/crear', [LocationController::class, 'create'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('locations.create');
    Route::post('/ubicaciones', [LocationController::class, 'store'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('locations.store');
    Route::get('/ubicaciones/{location}/editar', [LocationController::class, 'edit'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('locations.edit');
    Route::put('/ubicaciones/{location}', [LocationController::class, 'update'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('locations.update');
    Route::patch('/ubicaciones/{location}/activar-desactivar', [LocationController::class, 'toggleActive'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('locations.toggle-active');
    Route::get('/palets', ModulePlaceholderController::class)
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->defaults('module', 'pallets')
        ->name('modules.pallets');

    Route::get('/solicitudes-mercancia', [MerchandiseRequestController::class, 'index'])
        ->middleware('minimum.role:'.Role::CLIENTE)
        ->name('merchandise-requests.index');
    Route::get('/solicitudes-mercancia/crear', [MerchandiseRequestController::class, 'create'])
        ->middleware('minimum.role:'.Role::CLIENTE)
        ->name('merchandise-requests.create');
    Route::get('/solicitudes-mercancia/buscar-mercancias', [MerchandiseRequestController::class, 'searchItems'])
        ->middleware('minimum.role:'.Role::CLIENTE)
        ->name('merchandise-requests.items.search');
    Route::post('/solicitudes-mercancia', [MerchandiseRequestController::class, 'store'])
        ->middleware('minimum.role:'.Role::CLIENTE)
        ->name('merchandise-requests.store');
    Route::get('/solicitudes-mercancia/{merchandiseRequest}', [MerchandiseRequestController::class, 'show'])
        ->middleware('minimum.role:'.Role::CLIENTE)
        ->name('merchandise-requests.show');
    Route::patch('/solicitudes-mercancia/{merchandiseRequest}/estado', [MerchandiseRequestController::class, 'updateStatus'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('merchandise-requests.update-status');
    Route::get('/solicitudes-mercancia/{merchandiseRequest}/preparacion-pdf', [MerchandiseRequestController::class, 'preparationPdf'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('merchandise-requests.preparation-pdf');

    Route::get('/entradas', [GoodsReceiptController::class, 'index'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('goods-receipts.index');
    Route::get('/entradas/crear', [GoodsReceiptController::class, 'create'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('goods-receipts.create');
    Route::post('/entradas', [GoodsReceiptController::class, 'store'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('goods-receipts.store');
    Route::get('/entradas/{goodsReceipt}', [GoodsReceiptController::class, 'show'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('goods-receipts.show');
    Route::get('/entradas/{goodsReceipt}/editar', [GoodsReceiptController::class, 'edit'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('goods-receipts.edit');
    Route::put('/entradas/{goodsReceipt}', [GoodsReceiptController::class, 'update'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('goods-receipts.update');
    Route::patch('/entradas/{goodsReceipt}/confirmar', [GoodsReceiptController::class, 'confirm'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('goods-receipts.confirm');
    Route::patch('/entradas/{goodsReceipt}/cancelar', [GoodsReceiptController::class, 'cancel'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('goods-receipts.cancel');
    Route::post('/entradas/{goodsReceipt}/documento', [GoodsReceiptController::class, 'attachDocument'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('goods-receipts.attach-document');
    Route::get('/entradas/{goodsReceipt}/documento', [GoodsReceiptController::class, 'downloadDocument'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('goods-receipts.document');

    Route::get('/operaciones-diarias', [DailyOperationController::class, 'index'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('daily-operations.index');
    Route::post('/operaciones-diarias/dia', [DailyOperationController::class, 'upsertDay'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('daily-operations.day.upsert');
    Route::post('/operaciones-diarias/lineas', [DailyOperationController::class, 'storeLine'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('daily-operations.lines.store');
    Route::post('/operaciones-diarias/recalcular', [DailyOperationController::class, 'recalculate'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('daily-operations.recalculate');
    Route::put('/operaciones-diarias/lineas/{dailyOperationLine}', [DailyOperationController::class, 'updateLine'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('daily-operations.lines.update');
    Route::delete('/operaciones-diarias/lineas/{dailyOperationLine}', [DailyOperationController::class, 'destroyLine'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('daily-operations.lines.destroy');

    Route::get('/bookings', [BookingController::class, 'index'])
        ->middleware('minimum.role:'.Role::CLIENTE)
        ->name('bookings.index');
    Route::get('/bookings/calendario', [BookingController::class, 'calendar'])
        ->middleware('minimum.role:'.Role::CLIENTE)
        ->name('bookings.calendar');
    Route::get('/bookings/crear', [BookingController::class, 'create'])
        ->middleware('minimum.role:'.Role::CLIENTE)
        ->name('bookings.create');
    Route::post('/bookings', [BookingController::class, 'store'])
        ->middleware('minimum.role:'.Role::CLIENTE)
        ->name('bookings.store');
    Route::get('/bookings/{booking}', [BookingController::class, 'show'])
        ->middleware('minimum.role:'.Role::CLIENTE)
        ->name('bookings.show');
    Route::get('/bookings/{booking}/editar', [BookingController::class, 'edit'])
        ->middleware('minimum.role:'.Role::CLIENTE)
        ->name('bookings.edit');
    Route::put('/bookings/{booking}', [BookingController::class, 'update'])
        ->middleware('minimum.role:'.Role::CLIENTE)
        ->name('bookings.update');
    Route::patch('/bookings/{booking}/estado', [BookingController::class, 'updateStatus'])
        ->middleware('minimum.role:'.Role::CLIENTE)
        ->name('bookings.update-status');
    Route::patch('/bookings/{booking}/google-calendar/reintentar', [BookingController::class, 'retryGoogleCalendarSync'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('bookings.google-calendar.retry');
    Route::delete('/bookings/{booking}', [BookingController::class, 'destroy'])
        ->middleware('minimum.role:'.Role::CLIENTE)
        ->name('bookings.destroy');

    Route::get('/salidas', [GoodsDispatchController::class, 'index'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('dispatches.index');
    Route::get('/salidas/crear', [GoodsDispatchController::class, 'create'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('dispatches.create');
    Route::post('/salidas', [GoodsDispatchController::class, 'store'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('dispatches.store');
    Route::get('/salidas/pedidos-pendientes', [GoodsDispatchController::class, 'pendingRequests'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('dispatches.requests.index');
    Route::get('/salidas/pedidos/{merchandiseRequest}', [GoodsDispatchController::class, 'showRequest'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('dispatches.requests.show');
    Route::post('/salidas/pedidos/{merchandiseRequest}/generar', [GoodsDispatchController::class, 'generateFromRequest'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('dispatches.requests.generate');
    Route::get('/salidas/{goodsDispatch}', [GoodsDispatchController::class, 'show'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('dispatches.show');
    Route::patch('/salidas/{goodsDispatch}/confirmar-carga', [GoodsDispatchController::class, 'confirmLoading'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('dispatches.confirm-loading');
    Route::patch('/salidas/{goodsDispatch}/estado', [GoodsDispatchController::class, 'updateStatus'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('dispatches.update-status');
    Route::get('/salidas/{goodsDispatch}/albaran', [GoodsDispatchController::class, 'deliveryNotePdf'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('dispatches.delivery-note');

    Route::get('/clientes', [ClientController::class, 'index'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('clients.index');
    Route::get('/clientes/crear', [ClientController::class, 'create'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('clients.create');
    Route::post('/clientes', [ClientController::class, 'store'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('clients.store');
    Route::get('/clientes/{client}/editar', [ClientController::class, 'edit'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('clients.edit');
    Route::put('/clientes/{client}', [ClientController::class, 'update'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('clients.update');
    Route::patch('/clientes/{client}/activar-desactivar', [ClientController::class, 'toggleActive'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('clients.toggle-active');

    Route::get('/almacenes', [WarehouseController::class, 'index'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('warehouses.index');
    Route::get('/almacenes/crear', [WarehouseController::class, 'create'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('warehouses.create');
    Route::post('/almacenes', [WarehouseController::class, 'store'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('warehouses.store');
    Route::get('/almacenes/{warehouse}/editar', [WarehouseController::class, 'edit'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('warehouses.edit');
    Route::put('/almacenes/{warehouse}', [WarehouseController::class, 'update'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('warehouses.update');
    Route::patch('/almacenes/{warehouse}/activar-desactivar', [WarehouseController::class, 'toggleActive'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('warehouses.toggle-active');

    Route::get('/proveedores', [SupplierController::class, 'index'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('suppliers.index');
    Route::get('/proveedores/crear', [SupplierController::class, 'create'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('suppliers.create');
    Route::post('/proveedores', [SupplierController::class, 'store'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('suppliers.store');
    Route::get('/proveedores/{supplier}/editar', [SupplierController::class, 'edit'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('suppliers.edit');
    Route::put('/proveedores/{supplier}', [SupplierController::class, 'update'])
        ->middleware('minimum.role:'.Role::ALMACEN)
        ->name('suppliers.update');
    Route::patch('/proveedores/{supplier}/activar-desactivar', [SupplierController::class, 'toggleActive'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('suppliers.toggle-active');

    Route::get('/usuarios', [UserManagementController::class, 'index'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('users.index');
    Route::get('/usuarios/{user}/editar', [UserManagementController::class, 'edit'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('users.edit');
    Route::put('/usuarios/{user}', [UserManagementController::class, 'update'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('users.update');
    Route::patch('/usuarios/{user}/activar-desactivar', [UserManagementController::class, 'toggleActive'])
        ->middleware('minimum.role:'.Role::SUPERADMIN)
        ->name('users.toggle-active');

    Route::get('/solicitudes-acceso', [AccessRequestController::class, 'index'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('access-requests.index');
    Route::get('/solicitudes-acceso/{accessRequest}', [AccessRequestController::class, 'show'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('access-requests.show');
    Route::patch('/solicitudes-acceso/{accessRequest}/aprobar', [AccessRequestController::class, 'approve'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('access-requests.approve');
    Route::patch('/solicitudes-acceso/{accessRequest}/rechazar', [AccessRequestController::class, 'reject'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('access-requests.reject');

    Route::get('/auditoria', [AuditController::class, 'index'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('audit.index');
    Route::post('/auditoria/limpieza/previsualizar', [AuditController::class, 'previewCleanup'])
        ->middleware('minimum.role:'.Role::ADMINISTRACION)
        ->name('audit.cleanup.preview');
    Route::post('/auditoria/limpieza/ejecutar', [AuditController::class, 'executeCleanup'])
        ->middleware('minimum.role:'.Role::SUPERADMIN)
        ->name('audit.cleanup.execute');

    Route::get('/backups', ModulePlaceholderController::class)
        ->middleware('minimum.role:'.Role::SUPERADMIN)
        ->defaults('module', 'backups')
        ->name('modules.backups');
});
