<?php

namespace App\Models\Concerns;

use App\Models\Business;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned. When no business_id is supplied on
 * creation, it defaults to the authenticated user's business, so a tenant
 * user can never create a record outside their own business.
 */
trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        static::creating(function ($model) {
            $user = auth()->user();

            if ($user === null) {
                return;
            }

            // Tenant users are always pinned to their own business,
            // regardless of anything the caller supplied.
            if ($user->business_id !== null) {
                $model->business_id = $user->business_id;
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
