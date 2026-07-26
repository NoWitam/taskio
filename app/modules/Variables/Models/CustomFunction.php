<?php

namespace App\Modules\Variables\Models;

use App\Models\AbstractModel;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A user CUSTOM FUNCTION: a reusable, workspace-scoped variable transform — ONE input type, a list of
 * typed named ARGS, ONE return type, and a saved BODY pipeline over {input + args} terminating in the
 * return type. A function may reference OTHER functions in its body (nesting); the write-validator
 * (FunctionDefinitionValidator) keeps the reference graph acyclic.
 *
 * IDENTITY is the DB uuid: the pipeline wire op id is `fn:<uuid>` (a reserved prefix), so a stored
 * reference survives a rename — `name` is a user-facing label only (NOT unique). `args` and `body` ride
 * the json 'array' cast unchanged. Like a Constant it is always user-created (no engine authors one), so
 * the HasCreator system-record fallback never applies.
 *
 * Phase 3a is DEFINE + VALIDATE only: functions are not yet surfaced in the workflow operation catalog
 * nor executed (Phase 3b).
 */
class CustomFunction extends AbstractModel
{
    use HasCreator, HasFactory, HasUuids, TenantAware;

    protected $table = 'custom_functions';

    protected $fillable = [
        'name',
        'description',
        'input_type',
        'args',
        'return_type',
        'body',
        'creator_id',
    ];

    protected $casts = [
        // The args ({name, description?, type} list) and body (pipeline steps) are always JSON arrays.
        'args' => 'array',
        'body' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function newFactory()
    {
        return \Database\Factories\CustomFunctionFactory::new();
    }
}
