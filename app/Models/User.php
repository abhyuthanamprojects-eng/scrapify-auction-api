<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /** The eight roles from the BRD. */
    public const ROLES = [
        'super_admin',
        'admin',
        'buyer',
        'seller',
        'procurement_manager',
        'finance_manager',
        'technical_evaluator',
        'auditor',
    ];

    protected $fillable = [
        'uuid', 'name', 'email', 'phone', 'password', 'role',
        'organization_id', 'vendor_id', 'status', 'avatar_path',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $user) {
            $user->uuid ??= (string) Str::uuid();
        });

        static::created(function (self $user) {
            $user->wallet()->firstOrCreate([], ['balance' => 0, 'locked' => 0]);
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function watchlist(): HasMany
    {
        return $this->hasMany(Watchlist::class);
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(Notification::class)->latest('id');
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin', 'super_admin');
    }

    /**
     * Permission check used by the `can.permission` middleware. Kept in one
     * place so the API is the authority, not the frontend's hidden buttons.
     */
    public function hasPermission(string $permission): bool
    {
        $granted = config('roles.permissions')[$this->role] ?? [];

        return in_array('*', $granted, true) || in_array($permission, $granted, true);
    }

    public function displayRole(): string
    {
        return Str::headline($this->role);
    }
}
