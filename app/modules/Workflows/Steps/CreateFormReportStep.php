<?php

namespace App\Modules\Workflows\Steps;

use App\Modules\Forms\DTOs\FormReportDTO;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Services\FormReportService;
use App\Modules\Variables\Enums\VariableType;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WorkflowVariableResolver;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Creates a Form REPORT from the step's resolved config, THROUGH FormReportService::create()
 * — the SAME path the StoreFormReportRequest controller uses. Creating the FormReport fires
 * its `created` model event, which dispatches the CreateFormReport job. That job runs the AI
 * analysis and marks the report completed; it is FIRE-AND-FORGET from the step's view — the
 * step returns as soon as the row is created and NEVER waits for report completion (matching
 * the interactive-manual create-report behavior).
 *
 * Config mirrors the REAL StoreFormReportRequest / FormReportDTO field set:
 *
 *   - form_id             REQUIRED literal uuid. Loaded TENANT-SCOPED (Form::find →
 *                         WorkspaceScope). A missing / foreign / disabled form HARD-FAILS
 *                         the step (RuntimeException → run fails honestly), rather than
 *                         silently creating a report against nothing. FormReportService also
 *                         re-guards enabled-ness; the step surfaces the failure as a step error.
 *   - name                REQUIRED, resolveString (directives + flat tokens + literals);
 *                         blank after resolution HARD-FAILS (a nameless report is meaningless).
 *   - guidelines          optional, resolveString; blank → null.
 *   - sources             optional literal subset of ['task','form']; absent/empty → [].
 *   - submissions_from/to  optional literal|variable DATE unions. Absent from/to DEFAULT the
 *                         same way the request does: from = the form's enabled_at date, to =
 *                         today (so an unspecified window covers the form's whole life).
 *
 * Creator attribution: FormReport uses HasCreator. Because this step runs INSIDE a live
 * WorkflowRunContext (WorkflowStepRunner sets it around the whole step loop), the report is
 * attributed to the RUN — creator_type='workflow_run', creator_id=run->id — whether or not a
 * user is authenticated, so a queue/schedule run no longer stamps a NULL creator. The task
 * created by CreateTaskStep is attributed the same way.
 *
 * Output: report_id, report_name.
 */
class CreateFormReportStep implements WorkflowStep
{
    /** The `form_reports.name` column width — resolved names are clamped to it (see requireString). */
    private const NAME_MAX = 255;

    public function __construct(
        private FormReportService $reports,
        private WorkflowVariableResolver $resolver,
    ) {}

    public function type(): WorkflowStepType
    {
        return WorkflowStepType::CREATE_FORM_REPORT;
    }

    public static function outputDescriptors(): array
    {
        return [
            ['name' => 'report_id', 'type' => VariableType::TEXT],
            ['name' => 'report_name', 'type' => VariableType::TEXT],
            // The report's FORM — lets the run detail deep-link to the report's view
            // (reports are form-scoped: /forms/{form_id}/reports).
            ['name' => 'form_id', 'type' => VariableType::TEXT],
        ];
    }

    public function run(array $config, WorkflowRun $run, array $context): array
    {
        $form = $this->requireForm($config);
        $name = $this->requireString($config, 'name', self::NAME_MAX);

        $report = $this->reports->create(new FormReportDTO(
            form_id: $form->id,
            name: $name,
            guidelines: $this->resolveGuidelines($config),
            sources: $this->resolveSources($config),
            submissions_from: $this->resolveWindow($config, $context, 'submissions_from', $form->enabled_at?->format('Y-m-d') ?? now()->format('Y-m-d')),
            submissions_to: $this->resolveWindow($config, $context, 'submissions_to', now()->format('Y-m-d')),
        ));

        return [
            'report_id' => $report->id,
            'report_name' => $report->name,
            'form_id' => $form->id,
        ];
    }

    /**
     * The tenant-scoped target form. A missing / foreign / non-uuid form_id, or a form the
     * active workspace cannot see, hard-fails the step (an honest run failure).
     */
    private function requireForm(array $config): Form
    {
        $formId = $config['form_id'] ?? null;

        if (!is_string($formId) || $formId === '') {
            throw new RuntimeException('create_form_report step requires a `form_id`.');
        }

        // Form is TenantAware → Form::find applies WorkspaceScope, so a foreign-workspace id
        // resolves to null and fails here (no cross-tenant report creation).
        $form = Form::find($formId);

        if ($form === null) {
            throw new RuntimeException("create_form_report step could not find form `{$formId}` in this workspace.");
        }

        return $form;
    }

    /**
     * A required string config value (already reference-resolved by the runner). Blank after
     * resolution is a hard failure — the runner records the step failed and stops the run.
     */
    private function requireString(array $config, string $key, ?int $max = null): string
    {
        $value = $config[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException("create_form_report step requires a non-empty `{$key}`.");
        }

        // Clamp to the destination column (see CreateTaskStep) so a long resolved name can never
        // overflow the DB and fail the run with a raw SQL error.
        return $max !== null ? mb_substr($value, 0, $max) : $value;
    }

    /** The resolved guidelines string, or null when absent/blank. */
    private function resolveGuidelines(array $config): ?string
    {
        $value = $config['guidelines'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The literal source subset (['task','form']). Non-array config or non-string entries
     * yield an empty list (no source filter — every source is analysed).
     *
     * @return array<int, string>
     */
    private function resolveSources(array $config): array
    {
        $sources = $config['sources'] ?? [];

        if (!is_array($sources)) {
            return [];
        }

        return array_values(array_filter($sources, 'is_string'));
    }

    /**
     * One submissions window bound via the literal|variable DATE union, coerced to a Y-m-d
     * string. An absent/unresolvable value falls back to $default (the request's defaults:
     * from = form enabled_at, to = today) so the DTO's non-nullable window is always filled.
     */
    private function resolveWindow(array $config, array $context, string $key, string $default): string
    {
        if (!array_key_exists($key, $config)) {
            return $default;
        }

        $resolved = $this->resolver->resolveValueOrVariable($config[$key], $context, VariableType::DATE);

        if (!is_string($resolved) || $resolved === '') {
            return $default;
        }

        return Carbon::parse($resolved)->format('Y-m-d');
    }
}
