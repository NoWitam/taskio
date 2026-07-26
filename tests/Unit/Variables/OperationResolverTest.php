<?php

namespace Tests\Unit\Variables;

use App\Modules\Variables\Enums\Operation;
use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Services\OperationResolver;
use App\Modules\Variables\Support\CustomFunctionOperation;
use Tests\TestCase;

/**
 * The OperationResolver: built-ins ∪ the context's custom functions, prefix-safe. A built-in id resolves
 * to its enum case; a `fn:<uuid>` resolves to the supplied function; a bare id never scans the functions,
 * and the `fn:` prefix guarantees no collision with a built-in op id.
 */
class OperationResolverTest extends TestCase
{
    private function fn(string $uuid): CustomFunctionOperation
    {
        return new CustomFunctionOperation($uuid, VariableType::TEXT, VariableType::TEXT, []);
    }

    public function test_resolves_a_builtin_op_to_its_enum_case(): void
    {
        $this->assertSame(Operation::TEXT_UPPERCASE, (new OperationResolver)->resolve('text_uppercase'));
    }

    public function test_resolves_a_custom_function_by_its_fn_id(): void
    {
        $fn = $this->fn('abc-123');

        $this->assertSame($fn, (new OperationResolver)->resolve('fn:abc-123', [$fn]));
    }

    public function test_returns_null_for_an_unknown_or_absent_op(): void
    {
        $resolver = new OperationResolver;

        $this->assertNull($resolver->resolve('not_an_op'));
        $this->assertNull($resolver->resolve('fn:missing', [$this->fn('present')]));
    }

    public function test_a_bare_id_never_scans_the_functions_and_builtins_win(): void
    {
        $resolver = new OperationResolver;
        $fn = $this->fn('text_uppercase'); // a function whose uuid mimics a builtin id

        // A built-in id resolves to the built-in, never the function (its id is `fn:text_uppercase`).
        $this->assertSame(Operation::TEXT_UPPERCASE, $resolver->resolve('text_uppercase', [$fn]));
        // A non-prefixed unknown id never matches a function.
        $this->assertNull($resolver->resolve('text_uppercase_x', [$fn]));
    }
}
