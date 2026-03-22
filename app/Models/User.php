<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Laravel\Sanctum\HasApiTokens;

/**
 * @method bool isGlobalUser()
 * @method bool isWarehouseUser()
 */
class User extends Authenticatable implements FilamentUser
{
    use HasFactory,
        Notifiable,
        HasRoles,
        HasPanelShield,
        SoftDeletes,
        HasAuditFields,
        LogsActivity,
        HasApiTokens; 

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar_url',
        'is_active',
        'warehouse_id',
        'last_login_at',
        'last_login_ip',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
            'last_login_at'     => 'datetime',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (!$this->is_active) {
            return false;
        }

        return $this->hasAnyRole([
            \BezhanSalleh\FilamentShield\Support\Utils::getSuperAdminName(),
            'admin',
            'encargado',
            'operador',
            'finance',
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'phone', 'is_active', 'warehouse_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Usuario {$eventName}");
    }

    public function recordLogin(string $ip): void
    {
        $this->update([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ]);
    }

    // ─── Bodega ───────────────────────────────────────────────

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * True si no tiene bodega asignada (admin, super_admin, haremar).
     * Estos usuarios ven todos los manifiestos y todas las facturas.
     */
    public function isGlobalUser(): bool
    {
        return is_null($this->warehouse_id);
    }

    /**
     * True si pertenece a una bodega específica.
     * Solo ve facturas de su bodega.
     */
    public function isWarehouseUser(): bool
    {
        return !is_null($this->warehouse_id);
    }

    // ─── Relaciones jerárquicas ───────────────────────────────

    public function createdUsers(): HasMany
    {
        return $this->hasMany(User::class, 'created_by');
    }

    public function getDescendantIds(): array
    {
        $ids = [];
        $directChildren = static::where('created_by', $this->id)->pluck('id')->toArray();

        foreach ($directChildren as $childId) {
            $ids[] = $childId;
            $child = static::find($childId);
            if ($child) {
                $ids = array_merge($ids, $child->getDescendantIds());
            }
        }

        return $ids;
    }

    public function getVisibleUserIds(): array
    {
        return array_merge([$this->id], $this->getDescendantIds());
    }

    // ─── Scopes ───────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function scopeVisibleTo($query, User $user)
    {
        if ($user->hasRole(\BezhanSalleh\FilamentShield\Support\Utils::getSuperAdminName())) {
            return $query;
        } //nada
        return $query->whereIn('id', $user->getVisibleUserIds());
    }
}
