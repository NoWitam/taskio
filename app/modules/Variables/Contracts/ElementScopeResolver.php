<?php

namespace App\Modules\Variables\Contracts;

use App\Modules\Variables\Enums\VariableType;

/**
 * Resolves the ELEMENT-SCOPE subfield index for an array<object>/array<file> descriptor — the
 * synthetic `element.<subfield> => VariableType` map a higher-order array op's element pipeline
 * exposes (array-ops wave 3). It is the ONE thing PipelineValidator (Variables) needs from the
 * catalog's descriptor-descent logic (WorkflowVariableCatalogService, Workflows), inverted as an
 * interface so the dependency stays one-way: the validator depends on this Variables-owned
 * contract, and the Workflows catalog IMPLEMENTS it (bound in WorkflowsModuleServiceProvider).
 *
 * The catalog owns the descent because the SAME `{key,label,descriptor}` recursion also derives
 * form-field / object-global subfields, so keeping ONE implementation avoids drift — hence the
 * inversion rather than a copy in Variables.
 */
interface ElementScopeResolver
{
    /**
     * The `element.<subfield> => VariableType` index for an array<object>/array<file> descriptor;
     * empty for a scalar/enum element array (which exposes no subfields).
     *
     * @param  array<string, mixed>  $arrayDescriptor  the ARRAY variable's descriptor ({base, array:true, fields|elementDescriptor})
     * @return array<string, VariableType>
     */
    public function elementScopeSubfields(array $arrayDescriptor): array;
}
