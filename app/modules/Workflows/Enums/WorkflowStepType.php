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

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::CREATE_TASK => 'Utwórz zadanie',
            self::CREATE_FORM_REPORT => 'Utwórz raport formularza',
            self::GENERATE_CONTENT => 'Wygeneruj treść',
        };
    }
}
