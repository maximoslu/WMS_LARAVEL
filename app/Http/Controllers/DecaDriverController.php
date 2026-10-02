<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDriverDecaRequest;
use App\Models\DecaDocument;
use App\Models\User;
use App\Services\Deca\DecaIssuanceService;
use App\Services\Deca\DecaPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DecaDriverController extends Controller
{
    public function login()
    {
        return view('deca.driver-login');
    }

    public function authenticate(Request $request)
    {
        $data = $request->validate(['password' => ['required', 'string', 'regex:/\A[0-9]{4}\z/']], ['password.regex' => 'Introduce un PIN de cuatro dígitos.']);
        $access = DB::table('deca_driver_access')->find(1);
        if (! $access || ! Hash::check($data['password'], $access->password_hash)) {
            throw ValidationException::withMessages(['password' => 'PIN incorrecto o acceso todavía no activado.']);
        }
        $request->session()->regenerate();
        $request->session()->put('deca_driver', [
            'version' => $access->version, 'expires' => time() + 43200,
            'token' => bin2hex(random_bytes(32)),
        ]);

        return to_route('driver.quick');
    }

    public function logout(Request $request)
    {
        $request->session()->forget('deca_driver');
        $request->session()->regenerate();

        return to_route('driver.login');
    }

    public function index()
    {
        return view('deca.quick', ['driverPortal' => true, 'templates' => config('deca_quick.templates')]);
    }

    public function create(string $template)
    {
        $preset = config('deca_quick.templates')[$template] ?? null;
        abort_unless($preset, 404);

        return view('deca.quick-create', [
            'driverPortal' => true, 'templateKey' => $template, 'preset' => $preset,
            'plates' => config('deca_quick.plates'), 'carriers' => config('deca.carriers'),
            'submissionKey' => (string) Str::uuid(),
        ]);
    }

    public function store(StoreDriverDecaRequest $request, string $template, DecaIssuanceService $service)
    {
        $access = $request->attributes->get('deca_driver_access');
        $owner = User::findOrFail($access->owner_id);
        $document = $service->issue($owner, [...$request->validated(),
            'access_channel' => 'shared_driver_portal',
            'driver_session' => hash('sha256', $request->session()->get('deca_driver.token')),
        ]);
        abort_unless(($document->snapshot['driver_session'] ?? '') === hash('sha256', $request->session()->get('deca_driver.token')), 403);

        return to_route('driver.show', $document);
    }

    public function show(Request $request, DecaDocument $decaDocument, DecaPdfService $pdf)
    {
        abort_unless(hash_equals((string) ($decaDocument->snapshot['driver_session'] ?? ''), hash('sha256', $request->session()->get('deca_driver.token'))), 404);

        return view('deca.show', [
            'driverPortal' => true, 'document' => $decaDocument,
            'qr' => 'data:image/svg+xml;base64,'.base64_encode($pdf->qr($decaDocument->public_url)),
        ]);
    }

    public function settings()
    {
        return view('deca.driver-settings');
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate(['password' => ['required', 'string', 'regex:/\A[0-9]{4}\z/', 'confirmed']], ['password.regex' => 'Introduce un PIN de cuatro dígitos.', 'password.confirmed' => 'Los PIN no coinciden.']);
        DB::table('deca_driver_access')->updateOrInsert(['id' => 1], [
            'password_hash' => Hash::make($data['password']), 'version' => (string) Str::uuid(),
            'owner_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('status', 'PIN de chóferes guardado. Las sesiones anteriores han quedado cerradas.');
    }
}
