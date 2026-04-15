<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReturnReason extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'jaremar_id', 'code', 'category', 'description', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // ─── Scopes ───────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    // ─── Relaciones ───────────────────────────────────────────

    public function returns(): HasMany
    {
        return $this->hasMany(InvoiceReturn::class);
    }

    // ─── Helpers ──────────────────────────────────────────────

    public function getFullLabelAttribute(): string
    {
        return "[{$this->code}] {$this->description}";
    }
}
