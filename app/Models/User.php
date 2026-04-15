<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

/**
 * @method bool isGlobalUser()
 * @method bool isWarehouseUser()
 */
class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens,
        HasAuditFields,
        HasFactory,
        HasPanelShield,
        HasRoles,
        LogsActivity,
        Notifiable,
        SoftDeletes;

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
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
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
        return ! is_null($this->warehouse_id);
    }

    // ─── Relaciones jerárquicas ───────────────────────────────

    public function createdUsers(): HasMany
    {
        return $this->hasMany(User::class, 'created_by');
    }

    /**
     * Obtiene los IDs de todos los usuarios descendientes (hijos, nietos, etc.)
     * usando un CTE recursivo de PostgreSQL — O(1) queries sin importar
     * la profundidad de la jerarquía.
     *
     * Antes: recursión PHP con N+1 queries por nivel (O(n) queries).
     * Ahora: una sola query independientemente de niveles o cantidad de usuarios.
     *
     * @return array<int>
     */
    public function getDescendantIds(): array
    {
        $rows = \Illuminate\Support\Facades\DB::select('
            WITH RECURSIVE descendants AS (
                SELECT id FROM users WHERE created_by = ?
                UNION ALL
                SELECT u.id FROM users u
                INNER JOIN descendants d ON u.created_by = d.id
            )
            SELECT id FROM descendants
        ', [$this->id]);

        return array_map(fn ($row) => (int) $row->id, $rows);
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
        } // nada

        return $query->whereIn('id', $user->getVisibleUserIds());
    }
}
