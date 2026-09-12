<?php

namespace App\Modules\Workflows\Enums;

/**
 * A single action a workflow performs when it runs. Steps are ordered and each carries
 * a free-form `config` map plus a distinct `key` used for `{{steps.<key>.*}}` references
 * from later steps. The engine that executes these ships in a later batch.
 */
enum WorkflowStepType: string
{
    case CREATE_TASK = 'create_task';
    case CREATE_FORM_REPORT = 'create_form_report';
    /**
     * Runs a Generator TEMPLATE and waits for the produced content (R2 sub-stage 5). The ONLY
     * SUSPENDING step type: it hands the generation to the Generator's own async worker and parks the
     * run until that settles — see {@see \App\Modules\Workflows\Steps\GenerateContentStep}.
     */
    case GENERATE_CONTENT = 'generate_content';
    /**
     * Puts a CALENDAR EVENT on the workspace's grid (R3 B4) — an annotation on the timeline, and
     * nothing that will ever fire: see {@see \App\Modules\Calendar\Models\CalendarEvent} for the fence
     * around what an event is allowed to mean. The step writes through the Calendar's own service, so
     * a workflow-created event is the same kind of row as a hand-made one.
     */
    case CREATE_EVENT = 'create_event';
    /**
     * Puts a PUBLICATION in the Publishing module's queue and waits for it to reach an outcome (R4 B6).
     * The SECOND suspending step type, and the only one whose settled work is visible to the public: it
     * arms a row and lets the due-sweep publish it — it never calls a platform itself. See
     * {@see \App\Modules\Workflows\Steps\PublishStep}.
     */
    case PUBLISH = 'publish';

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The step type's name in the reader's language.
     *
     * Translated rather than hardcoded, and the three older cases were repointed here rather than
     * left as they were: this label is server prose that ships in the variable catalog
     * (`WorkflowVariableCatalogService::stepOutputVariables`, as "<step> · <output>"), and the app is
     * PL+EN switchable. The Polish values are byte-identical to what the match arms held, so nothing
     * changes for a Polish reader; the English ones stop a fourth case from having to choose between
     * hardcoding Polish and being the odd one out. Nothing matches on this prose — the workflow editor
     * words step types from its own catalogue.
     */
    public function label(): string
    {
        return __('workflows.step_types.' . $this->value);
    }
}
