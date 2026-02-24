<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory,
        Notifiable,
        HasRoles,
        HasPanelShield,
        SoftDeletes,
        HasAuditFields,
        LogsActivity;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar_url',
        'is_active',
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

    /**
     * Check if the user can access the Filament panel.
     * Blocks inactive users from logging in.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (!$this->is_active) {
            return false;
        }

        return $this->hasRole(\BezhanSalleh\FilamentShield\Support\Utils::getSuperAdminName())
            || $this->hasRole(\BezhanSalleh\FilamentShield\Support\Utils::getPanelUserRoleName());
    }

    /**
     * Configure activity log options.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'phone', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Usuario {$eventName}");
    }

    /**
     * Record last login information.
     */
    public function recordLogin(string $ip): void
    {
        $this->update([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ]);
    }

    // ─── Relaciones jerárquicas ───────────────────────────────

    /**
     * Usuarios creados directamente por este usuario.
     */
    public function createdUsers(): HasMany
    {
        return $this->hasMany(User::class, 'created_by');
    }

    /**
     * Obtener todos los IDs de la rama descendente (recursivo).
     * Incluye: hijos, nietos, bisnietos, etc.
     *
     * @return array<int>
     */
    public function getDescendantIds(): array
    {
        $ids = [];
        $directChildren = static::where('created_by', $this->id)->pluck('id')->toArray();

        foreach ($directChildren as $childId) {
            $ids[] = $childId;
            /** @var User|null $child */
            $child = static::find($childId);
            if ($child) {
                $ids = array_merge($ids, $child->getDescendantIds());
            }
        }

        return $ids;
    }

    /**
     * Obtener todos los IDs visibles para este usuario.
     * Incluye: su propio ID + toda su rama descendente.
     *
     * @return array<int>
     */
    public function getVisibleUserIds(): array
    {
        return array_merge([$this->id], $this->getDescendantIds());
    }

    // ─── Scopes ───────────────────────────────────────────────

    /**
     * Scope: only active users.
     *
     * @param \Illuminate\Database\Eloquent\Builder<User> $query
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: only inactive users.
     *
     * @param \Illuminate\Database\Eloquent\Builder<User> $query
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /**
     * Scope: only users visible for the given user.
     * Super admin sees all, others see their branch.
     *
     * @param \Illuminate\Database\Eloquent\Builder<User> $query
     * @param User $user
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    public function scopeVisibleTo($query, User $user)
    {
        if ($user->hasRole(\BezhanSalleh\FilamentShield\Support\Utils::getSuperAdminName())) {
            return $query;
        }

        return $query->whereIn('id', $user->getVisibleUserIds());
    }
}