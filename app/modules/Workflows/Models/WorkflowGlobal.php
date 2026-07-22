<?php

namespace App\Modules\Workflows\Models;

use App\Models\AbstractModel;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A workflow GLOBAL: a user-created, workspace-scoped, typed LITERAL constant (e.g.
 * `nazwa_marki = "Taskio"`, `budzet = 5000`, `hashtagi = ["#ai", "#automatyzacja"]`).
 *
 * A global is form-independent and becomes a `globals.<key>` reference usable in EVERY workflow,
 * resolved from its stored `value` at run time (a plain whitelisted lookup — NO computed
 * evaluation, NO dependency graph, NO cycles; a global holds a stored constant only). Its TYPE is
 * a `descriptor` from the Phase 1-2 type system (base + nullable + array + options[enum] +
 * fields[object]); its `value` is a literal matching that descriptor.
 */
class WorkflowGlobal extends AbstractModel
{
    use HasCreator, HasFactory, HasUuids, TenantAware;

    protected $table = 'workflow_globals';

    protected $fillable = [
        'name',
        'key',
        'descriptor',
        'value',
        'creator_id',
    ];

    protected $casts = [
        // The descriptor is always an object; the value may be a scalar, a list, an object, or
        // null — the 'array' cast round-trips all of them (json_decode/encode) faithfully.
        'descriptor' => 'array',
        'value' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function newFactory()
    {
        return \Database\Factories\WorkflowGlobalFactory::new();
    }
}
