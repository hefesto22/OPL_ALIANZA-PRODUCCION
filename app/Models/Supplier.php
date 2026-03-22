<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Supplier extends Model
{
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'name', 'rtn', 'email', 'phone',
        'address', 'api_url', 'api_key', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'api_key'   => 'encrypted',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // ─── Relaciones ───────────────────────────────────────────

    public function manifests(): HasMany
    {
        return $this->hasMany(Manifest::class);
    }
}