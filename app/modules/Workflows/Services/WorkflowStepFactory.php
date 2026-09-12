<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Steps\CreateEventStep;
use App\Modules\Workflows\Steps\CreateFormReportStep;
use App\Modules\Workflows\Steps\CreateTaskStep;
use App\Modules\Workflows\Steps\GenerateContentStep;
use App\Modules\Workflows\Steps\PublishStep;
use App\Modules\Workflows\Steps\WorkflowStep;
use RuntimeException;

/**
 * Single source of truth mapping a WorkflowStepType to its step implementation (mirrors
 * BotTaskToolFactory). Steps are container-resolved so their service dependencies (and any
 * test doubles) are injected. An unknown type is a programming error — the Store/Update
 * request already rejects unknown types, so a run can only reach here with a known one.
 */
class WorkflowStepFactory
{
    public function make(WorkflowStepType $type): WorkflowStep
    {
        return app($this->stepClass($type));
    }

    /**
     * The step CLASS for a type — used to read a step's static output descriptors (for the
     * variable catalog) without constructing the step / resolving its service dependencies.
     *
     * @return class-string<WorkflowStep>
     */
    public function stepClass(WorkflowStepType $type): string
    {
        return match ($type) {
            WorkflowStepType::CREATE_TASK => CreateTaskStep::class,
            WorkflowStepType::CREATE_FORM_REPORT => CreateFormReportStep::class,
            WorkflowStepType::GENERATE_CONTENT => GenerateContentStep::class,
            WorkflowStepType::CREATE_EVENT => CreateEventStep::class,
            WorkflowStepType::PUBLISH => PublishStep::class,
        };
    }

    /** Resolve a step from its raw string type (as stored in the workflow definition). */
    public function makeFromValue(string $type): WorkflowStep
    {
        $enum = WorkflowStepType::tryFrom($type);

        if ($enum === null) {
            throw new RuntimeException("Unknown workflow step type `{$type}`.");
        }

        return $this->make($enum);
    }
}
