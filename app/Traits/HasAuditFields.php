<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

trait HasAuditFields
{
    /**
     * Boot the trait.
     * Automatically sets created_by, updated_by, and deleted_by fields.
     */
    public static function bootHasAuditFields(): void
    {
        static::creating(function ($model) {
            if (Auth::check() && is_null($model->created_by)) {
                $model->created_by = Auth::id();
            }
        });

        static::updating(function ($model) {
            if (Auth::check()) {
                $model->updated_by = Auth::id();
            }
        });

        // Only register soft delete event if the model uses SoftDeletes
        if (method_exists(static::class, 'bootSoftDeletes')) {
            static::deleting(function ($model) {
                if (Auth::check() && !$model->isForceDeleting()) {
                    $model->deleted_by = Auth::id();
                    $model->saveQuietly();
                }
            });
        }
    }

    /**
     * Get the user who created the record.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the record.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the user who deleted the record.
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}