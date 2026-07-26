<?php

namespace App\Modules\Variables\Services;

use App\Modules\Variables\Contracts\OperationDefinition;
use App\Modules\Variables\Enums\Operation;

/**
 * Resolves a pipeline step's `op` id to an {@see OperationDefinition} — the ONE indirection the engine
 * uses instead of a bare `Operation::tryFrom`, so a step may reference EITHER a built-in op OR a user
 * custom function. Built-ins are checked FIRST; only when the id is not a built-in AND carries the
 * reserved `fn:` prefix is it matched against the supplied functions. The prefix guarantees no
 * collision — a built-in op id never starts with `fn:`, and neither does the `globals` wire root — so
 * a function can never shadow a built-in and vice versa.
 *
 * A `fn:<uuid>` that is not among the supplied functions (a deleted / absent / cross-workspace
 * function) resolves to null — the caller then fails CLOSED (the walk rejects an unknown op; the
 * runtime yields null), never silently mis-resolves.
 */
class OperationResolver
{
    /**
     * @param  iterable<OperationDefinition>  $functions  the custom functions available in this context
     */
    public function resolve(string $id, iterable $functions = []): ?OperationDefinition
    {
        $builtin = Operation::tryFrom($id);

        if ($builtin !== null) {
            return $builtin;
        }

        // Only a reserved-prefix id can be a custom function; anything else is an unknown op.
        if (!str_starts_with($id, 'fn:')) {
            return null;
        }

        foreach ($functions as $function) {
            if ($function instanceof OperationDefinition && $function->id() === $id) {
                return $function;
            }
        }

        return null;
    }
}
