<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\BrevoMailService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'client_id',
        'avatar_path',
        'active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function createdGoodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class, 'created_by');
    }

    public function confirmedGoodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class, 'confirmed_by');
    }

    public function accessRequests(): HasMany
    {
        return $this->hasMany(AccessRequest::class);
    }

    public function approvedAccessRequests(): HasMany
    {
        return $this->hasMany(AccessRequest::class, 'approved_by');
    }

    public function rejectedAccessRequests(): HasMany
    {
        return $this->hasMany(AccessRequest::class, 'rejected_by');
    }

    public function requestedMerchandiseRequests(): HasMany
    {
        return $this->hasMany(MerchandiseRequest::class, 'requested_by');
    }

    public function createdGoodsDispatches(): HasMany
    {
        return $this->hasMany(GoodsDispatch::class, 'created_by');
    }

    public function stockImports(): HasMany
    {
        return $this->hasMany(StockImport::class, 'uploaded_by');
    }

    public function hasRole(string $slug): bool
    {
        return $this->role?->slug === $slug;
    }

    public function hasRoleLevel(string $slug): bool
    {
        return $this->canAccessRole($slug);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Role::SUPERADMIN);
    }

    public function canAccessRole(string $minimumRole): bool
    {
        if ($this->role === null || ! Role::slugExists($minimumRole)) {
            return false;
        }

        $minimumLevel = Role::query()
            ->where('slug', $minimumRole)
            ->value('level') ?? Role::defaultLevelFor($minimumRole);

        return $minimumLevel !== null && $this->role->level >= $minimumLevel;
    }

    public function sendPasswordResetNotification($token): void
    {
        try {
            app(BrevoMailService::class)->sendPasswordReset(
                $this->email,
                route('password.reset', [
                    'token' => (string) $token,
                    'email' => $this->getEmailForPasswordReset(),
                ])
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->avatar_path !== null
            ? Storage::disk('public')->url($this->avatar_path)
            : null);
    }
}
