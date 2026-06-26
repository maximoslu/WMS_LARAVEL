<?php

namespace App\Http\Controllers;

use App\Models\AccessRequest;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Services\BrevoMailService;
use App\Support\WmsNavigation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class AccessRequestController extends Controller
{
    public function create(): View
    {
        return view('access-requests.create');
    }

    public function index(Request $request): View
    {
        $this->authorizeManagement($request);

        $status = (string) $request->string('status', AccessRequest::STATUS_PENDING);
        $search = trim((string) $request->string('search'));

        $accessRequests = AccessRequest::query()
            ->with(['client', 'user', 'approvedBy', 'rejectedBy'])
            ->when(in_array($status, [
                AccessRequest::STATUS_PENDING,
                AccessRequest::STATUS_APPROVED,
                AccessRequest::STATUS_REJECTED,
            ], true), fn (Builder $query) => $query->where('status', $status))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('company', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('access-requests.index', [
            'accessRequests' => $accessRequests,
            'filters' => [
                'status' => $status,
                'search' => $search,
            ],
            'pendingCount' => AccessRequest::query()->pending()->count(),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function show(Request $request, AccessRequest $accessRequest): View
    {
        $this->authorizeManagement($request);

        return view('access-requests.show', [
            'accessRequest' => $accessRequest->load(['client', 'user', 'approvedBy', 'rejectedBy']),
            'clients' => Client::query()->where('active', true)->orderBy('name')->get(),
            'pendingCount' => AccessRequest::query()->pending()->count(),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $payload['status'] = AccessRequest::STATUS_PENDING;

        $accessRequest = AccessRequest::query()->create($payload);

        try {
            app(BrevoMailService::class)->sendAccessRequestNotification($accessRequest);
        } catch (Throwable $exception) {
            report($exception);
        }

        return redirect()
            ->route('access-requests.create')
            ->with('status', 'Solicitud recibida. Revisaremos el alta y te avisaremos por correo.');
    }

    public function approve(Request $request, AccessRequest $accessRequest): RedirectResponse
    {
        $this->authorizeManagement($request);
        $this->ensurePending($accessRequest);

        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
        ]);

        $clienteRole = Role::query()->where('slug', Role::CLIENTE)->firstOrFail();
        $actor = $request->user();

        $accessRequest = DB::transaction(function () use ($accessRequest, $validated, $clienteRole, $actor): AccessRequest {
            $user = User::query()->where('email', $accessRequest->email)->first();

            if ($user === null) {
                $user = User::query()->create([
                    'name' => $accessRequest->name,
                    'email' => $accessRequest->email,
                    'password' => Str::password(24),
                    'role_id' => $clienteRole->id,
                    'client_id' => $validated['client_id'],
                    'active' => true,
                ]);
            } else {
                $payload = [
                    'name' => $accessRequest->name,
                    'client_id' => $validated['client_id'],
                    'active' => true,
                ];

                if ($user->role === null || ! $user->canAccessRole(Role::ALMACEN)) {
                    $payload['role_id'] = $clienteRole->id;
                }

                $user->update($payload);
            }

            $accessRequest->update([
                'status' => AccessRequest::STATUS_APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
                'user_id' => $user->id,
                'client_id' => $validated['client_id'],
            ]);

            return $accessRequest->fresh(['client', 'user']);
        });

        try {
            app(BrevoMailService::class)->sendAccessRequestApproved($accessRequest);
        } catch (Throwable $exception) {
            report($exception);
        }

        return redirect()
            ->route('access-requests.show', $accessRequest)
            ->with('status', 'Solicitud aprobada y usuario preparado para acceso.');
    }

    public function reject(Request $request, AccessRequest $accessRequest): RedirectResponse
    {
        $this->authorizeManagement($request);
        $this->ensurePending($accessRequest);

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        $accessRequest->update([
            'status' => AccessRequest::STATUS_REJECTED,
            'approved_by' => null,
            'approved_at' => null,
            'rejected_by' => $request->user()->id,
            'rejected_at' => now(),
            'rejection_reason' => $validated['rejection_reason'],
        ]);

        try {
            app(BrevoMailService::class)->sendAccessRequestRejected($accessRequest->fresh());
        } catch (Throwable $exception) {
            report($exception);
        }

        return redirect()
            ->route('access-requests.show', $accessRequest)
            ->with('status', 'Solicitud rechazada correctamente.');
    }

    private function authorizeManagement(Request $request): void
    {
        abort_unless($request->user()?->canAccessRole(Role::ADMINISTRACION), 403);
    }

    private function ensurePending(AccessRequest $accessRequest): void
    {
        if ($accessRequest->status !== AccessRequest::STATUS_PENDING) {
            throw (new ModelNotFoundException)->setModel(AccessRequest::class, [$accessRequest->id]);
        }
    }
}
