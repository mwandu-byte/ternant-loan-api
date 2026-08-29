<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for agent data-ownership scoping. A user who
 * holds the `data.view-all` permission (granted to manager/admin, never
 * to staff) is unrestricted; everyone else is limited to records they
 * own or acted on. This is a Spatie permission check, never a role-name
 * check, so it stays a scope concern layered on top of — not a
 * replacement for — the existing permission system.
 */
final class AccessScope
{
    public const PERMISSION = 'data.view-all';

    public static function isUnrestricted(User $user): bool
    {
        return $user->can(self::PERMISSION);
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
