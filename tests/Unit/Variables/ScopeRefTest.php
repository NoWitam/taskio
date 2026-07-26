<?php

namespace Tests\Unit\Variables;

use App\Modules\Variables\Support\ScopeRef;
use Tests\TestCase;

/**
 * The generalized ScopeRef root set (Phase 3a). The DEFAULT roots stay `element`/`index` so every array
 * element pipeline is byte-identical; a CALLER may supply a different root set (a function body's
 * `input` + arg names) to expose its own scope.
 */
class ScopeRefTest extends TestCase
{
    // ---- default roots (unchanged: element / index) --------------------------

    public function test_default_roots_detect_element_and_index_and_subfields(): void
    {
        $this->assertSame('element', ScopeRef::leaf(['source' => 'scope', 'path' => 'element']));
        $this->assertSame('index', ScopeRef::leaf(['source' => 'scope', 'path' => 'index']));
        $this->assertSame('element.price', ScopeRef::leaf(['source' => 'scope', 'path' => 'element.price']));
    }

    public function test_default_roots_reject_index_subfields_and_real_sources_and_foreign_roots(): void
    {
        // `index` is a leaf scalar — a `.sub` tail is not a scope ref.
        $this->assertNull(ScopeRef::leaf(['source' => 'scope', 'path' => 'index.foo']));

        // A real (non-scope) source is never the scope even when the path root looks like one.
        $this->assertNull(ScopeRef::leaf(['source' => 'globals', 'path' => 'element']));

        // `input` is NOT a default (element-pipeline) scope root.
        $this->assertNull(ScopeRef::leaf(['source' => 'scope', 'path' => 'input']));
    }

    // ---- caller-supplied roots (a function body's input + args) --------------

    public function test_caller_supplied_roots_expose_a_function_body_scope(): void
    {
        $roots = ['input', 'suffix'];

        $this->assertSame('input', ScopeRef::leaf(['source' => 'scope', 'path' => 'input'], $roots));
        $this->assertSame('suffix', ScopeRef::leaf(['source' => 'scope', 'path' => 'suffix'], $roots));

        // element/index are NOT roots of a plain function body.
        $this->assertNull(ScopeRef::leaf(['source' => 'scope', 'path' => 'element'], $roots));
        $this->assertNull(ScopeRef::leaf(['source' => 'scope', 'path' => 'index'], $roots));
    }

    public function test_is_scope_union_threads_the_root_set(): void
    {
        $union = ['kind' => 'variable', 'ref' => ['source' => 'scope', 'path' => 'input', 'type' => 'text']];

        $this->assertTrue(ScopeRef::isScopeUnion($union, ['input']));
        // With the DEFAULT roots (element/index) an `input` union is NOT a scope union.
        $this->assertFalse(ScopeRef::isScopeUnion($union));
        // A literal is never a scope union.
        $this->assertFalse(ScopeRef::isScopeUnion(['kind' => 'literal', 'value' => 'x'], ['input']));
    }
}
