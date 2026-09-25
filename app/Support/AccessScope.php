<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Concerns\BelongsToBusiness;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Single source of truth for agent data-ownership scoping. A user who
 * holds the `data.view-all` permission (granted to manager/admin, never
 * to staff) is unrestricted; everyone else is limited to records they
 * own or acted on. This is a Spatie permission check, never a role-name
 * check, so it stays a scope concern layered on top of — not a
 * replacement for — the existing permission system.
 *
 * On top of ownership sits tenant isolation: every user belongs to at
 * most one Business. A user with no business is a platform user and may
 * cross tenants; every other user is confined to their own business,
 * even when they hold `data.view-all` (which then means "all within my
 * business"). Tenant isolation is always applied before ownership.
 */
final class AccessScope
{
    public const PERMISSION = 'data.view-all';

    /**
     * Roles that carry platform-wide powers (e.g. editing the global role
     * and permission definitions). Only platform users may grant them.
     */
    public const PLATFORM_ROLES = ['admin'];

    public static function isUnrestricted(User $user): bool
    {
        return $user->can(self::PERMISSION);
    }

    public static function isPlatformUser(User $user): bool
    {
        return $user->business_id === null;
    }

    /**
     * Query-level tenant isolation: platform users are unrestricted, all
     * other users only see rows of their own business. A tenant user's
     * business_id is never NULL here, so unassigned rows stay hidden.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function restrictToBusiness(Builder $query, User $user, string $column = 'business_id'): Builder
    {
        return self::isPlatformUser($user) ? $query : $query->where($column, $user->business_id);
    }

    /**
     * Tenant isolation for models that have no business column of their
     * own and are reached through a loan relation.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function restrictToBusinessViaLoan(Builder $query, User $user, string $loanRelation = 'loan'): Builder
    {
        return self::isPlatformUser($user)
            ? $query
            : $query->whereHas($loanRelation, fn (Builder $q) => $q->where('business_id', $user->business_id));
    }

    /**
     * Single-row (Policy / Gate) tenant check.
     */
    public static function canAccessBusiness(User $user, ?int $businessId): bool
    {
        return self::isPlatformUser($user) || ($businessId !== null && $businessId === $user->business_id);
    }

    /**
     * The business a model belongs to: its own business_id, or its loan's
     * for loan-child models (Payment, Repayment, RepaymentSchedule, Penalty).
     * Returns false when the model is not tenant-aware at all.
     */
    public static function businessIdOf(Model $model): int|false|null
    {
        if ($model instanceof Business) {
            return $model->id;
        }

        if ($model instanceof User || in_array(BelongsToBusiness::class, class_uses_recursive($model), true)) {
            return $model->business_id;
        }

        if (method_exists($model, 'loan')) {
            return $model->loan?->business_id;
        }

        return false;
    }

    /**
     * Query-level: restrict $query to rows owned by $user via $column. A
     * plain equality WHERE excludes NULL-owner rows by SQL semantics
     * (NULL = x is never true) — legacy/unassigned records are therefore
     * visible only to unrestricted users. Do not rewrite this with an
     * orWhereNull(), that would silently expose unowned records to staff.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function restrictToOwner(Builder $query, User $user, string $column = 'created_by'): Builder
    {
        $query = self::restrictToBusiness($query, $user);

        return self::isUnrestricted($user) ? $query : $query->where($column, $user->id);
    }

    /**
     * Query-level: restrict via a related loan's created_by, for models
     * with no owner column of their own (RepaymentSchedule, Penalty).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function restrictViaLoan(Builder $query, User $user, string $loanRelation = 'loan'): Builder
    {
        $query = self::restrictToBusinessViaLoan($query, $user, $loanRelation);

        return self::isUnrestricted($user)
            ? $query
            : $query->whereHas($loanRelation, fn (Builder $q) => $q->where('created_by', $user->id));
    }

    /**
     * Query-level union rule for Repayment/Payment/Receipt: visible if the
     * user is the recorder/actor, or owns the related loan.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function restrictToOwnerOrActor(
        Builder $query,
        User $user,
        string $actorColumn,
        string $loanRelation = 'loan',
    ): Builder {
        $query = self::restrictToBusinessViaLoan($query, $user, $loanRelation);

        if (self::isUnrestricted($user)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user, $actorColumn, $loanRelation) {
            $q->where($actorColumn, $user->id)
                ->orWhereHas($loanRelation, fn (Builder $lq) => $lq->where('created_by', $user->id));
        });
    }

    /** Single-row (Policy) equivalent of restrictToOwner()/restrictViaLoan(). */
    public static function ownsOrUnrestricted(User $user, ?int $ownerId): bool
    {
        return self::isUnrestricted($user) || ($ownerId !== null && $ownerId === $user->id);
    }

    /** Single-row (Policy) equivalent of restrictToOwnerOrActor(). */
    public static function ownsActorOrLoanOrUnrestricted(User $user, ?int $actorId, ?int $loanOwnerId): bool
    {
        return self::isUnrestricted($user)
            || ($actorId !== null && $actorId === $user->id)
            || ($loanOwnerId !== null && $loanOwnerId === $user->id);
    }
}
