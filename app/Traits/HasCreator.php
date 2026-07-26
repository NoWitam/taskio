<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Attributes a record to whoever created it. The creator is POLYMORPHIC — a human User, an
 * engine WorkflowRun, or (structurally) a Bot — stored as an id column plus a morph-type
 * discriminator (`creator_id`/`creator_type`, or `uploader_id`/`uploader_type` on the Disk
 * File). The type column is NULLABLE and every read treats a NULL type as the 'user' alias
 * (pre-polymorphic rows), so legacy rows keep resolving to their human creator.
 *
 * Stamping precedence on save (fill-only-when-empty, so an explicit value is never rewritten):
 *   1. an explicit id already on the model  -> keep it; default the type to 'user' if unset;
 *   2. an active WorkflowRun on this worker  -> stamp the run (a system record: nobody owns it);
 *   3. an authenticated user                 -> stamp that user;
 *   4. otherwise                             -> leave both null.
 *
 * The run lookup is LAZY and guarded (app()->bound(...)) so this global trait never hard-
 * depends on the Workflows module being booted.
 */
trait HasCreator
{
    private const CREATOR_ID_DEFAULT_COLUMN = 'creator_id';

    /**
     * The Workflows run-context binding, referenced as a compile-time ::class string (no `use`
     * import) so the trait carries no runtime dependency on the module and never autoloads it.
     */
    private const WORKFLOW_RUN_CONTEXT = \App\Modules\Workflows\Services\WorkflowRunContext::class;

    public static function bootHasCreator()
    {
        static::saving(function (Model $model) {
            $idColumn = self::getColumName();
            $typeColumn = self::getCreatorTypeColumn();

            // 1. Explicit id from the caller wins. Only default the type when it is unset, so an
            //    explicitly-stamped pair (e.g. a workflow run stamping 'user') is left intact.
            if (isset($model->{$idColumn})) {
                if (!isset($model->{$typeColumn})) {
                    $model->{$typeColumn} = self::userMorphAlias();
                }

                return;
            }

            // 2. A workflow run is executing on this worker (queue/console path, usually no auth):
            //    attribute the record to the run as a system record owned by nobody.
            $run = app()->bound(self::WORKFLOW_RUN_CONTEXT)
                ? app(self::WORKFLOW_RUN_CONTEXT)->current()
                : null;

            if ($run !== null) {
                $model->{$idColumn} = $run->getKey();
                $model->{$typeColumn} = $run->getMorphClass();

                return;
            }

            // 3. An authenticated user authored it.
            if (auth()->id() !== null) {
                $model->{$idColumn} = auth()->id();
                $model->{$typeColumn} = self::userMorphAlias();

                return;
            }

            // 4. No caller value, no run, no user: leave both null. A NOT NULL id column will
            //    surface the missing creator loudly rather than silently mis-attributing it.
        });
    }

    /**
     * The polymorphic creator (User | WorkflowRun | Bot | null). The User branch bypasses the
     * WorkspaceMemberScope on load — exactly as Task::assignee / Comment::author do — so a
     * creator who has since left the workspace still resolves.
     */
    public function creator(): MorphTo
    {
        return $this->morphTo('creator', self::getCreatorTypeColumn(), self::getColumName())
            ->constrain([
                User::class => fn ($query) => $query->withoutWorkspaceMemberScope(),
            ]);
    }

    /**
     * The human creator, or null when the record is a system record (workflow_run / bot) that
     * nobody owns. A NULL/legacy type resolves through the morphTo to null id -> null, and a
     * 'user' type resolves to the User; any non-user morph is fail-closed to null.
     */
    public function creatorUser(): ?User
    {
        return $this->creator instanceof User ? $this->creator : null;
    }

    /**
     * Whether $user is the human owner of this record. False for a system record (run/bot
     * creator) and for a null user — ownership is fail-closed.
     */
    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->ownerUserId() === $user->id;
    }

    /**
     * The owner's user id WITHOUT loading the User — the hot-path variant for policy checks.
     * Returns creator_id only when the creator is human (type 'user' or legacy NULL); a
     * workflow_run / bot creator yields null (owned by nobody).
     */
    public function ownerUserId(): ?string
    {
        $type = $this->{self::getCreatorTypeColumn()};

        if ($type !== null && $type !== self::userMorphAlias()) {
            return null;
        }

        return $this->{self::getColumName()};
    }

    private static function getColumName(): string
    {
        return defined('static::CREATOR_ID_COLUMN') ? static::CREATOR_ID_COLUMN : self::CREATOR_ID_DEFAULT_COLUMN;
    }

    /**
     * The morph-type column paired with the id column: an explicit CREATOR_TYPE_COLUMN const
     * (the Disk File overrides to 'uploader_type'), else the id column with `_id` -> `_type`.
     */
    private static function getCreatorTypeColumn(): string
    {
        if (defined('static::CREATOR_TYPE_COLUMN')) {
            return static::CREATOR_TYPE_COLUMN;
        }

        return preg_replace('/_id$/', '_type', self::getColumName());
    }

    /** The registered morph alias for a human creator (kept in sync with the morph map). */
    private static function userMorphAlias(): string
    {
        return (new User)->getMorphClass();
    }
}
