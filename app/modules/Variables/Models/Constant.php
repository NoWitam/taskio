<?php

namespace App\Modules\Variables\Models;

use App\Models\AbstractModel;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A CONSTANT: a user-created, workspace-scoped, typed LITERAL constant (e.g.
 * `nazwa_marki = "Taskio"`, `budzet = 5000`, `hashtagi = ["#ai", "#automatyzacja"]`).
 *
 * A constant is form-independent and becomes a `globals.<key>` reference usable in EVERY workflow,
 * resolved from its stored `value` at run time (a plain whitelisted lookup — NO computed
 * evaluation, NO dependency graph, NO cycles; a constant holds a stored literal only). Its TYPE is
 * a `descriptor` from the Variables type system (base + nullable + array + options[enum] +
 * fields[object]); its `value` is a literal matching that descriptor.
 *
 * The table renamed to `consts` and the model to Constant (from the former globals persistence that
 * lived in the Workflows module), but the RUNTIME WIRE stays byte-identical: a reference is still
 * `globals.<key>` and the run-context root is still `globals` (a deliberate decoupling of the wire
 * from the table name so stored workflow configs never break on the rename).
 */
class Constant extends AbstractModel
{
    use HasCreator, HasFactory, HasUuids, TenantAware;

    protected $table = 'consts';

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
        return \Database\Factories\ConstantFactory::new();
    }
}
