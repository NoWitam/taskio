<?php

namespace Tests\Feature;

use App\Validation\WorkspaceScopedReference;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Architecture guard: no FormRequest may reference a workspace-scoped table through a
 * RAW `exists:` rule. The stock `exists:` validator bypasses Eloquent global scopes, so
 * a raw `exists:users` / `exists:forms` / `exists:approval_pipelines` (etc.) lets a
 * request resolve rows from ANOTHER workspace — the exact write-side leak Batch 2 closes.
 *
 * The set of forbidden tables is discovered (not hardcoded) by
 * {@see WorkspaceScopedReference::tables()}, so a new TenantAware / ScopedToWorkspaceMembers
 * model is protected automatically. The fix for any violation is always the same: replace
 * the raw rule with {@see \App\Rules\ScopedExists}.
 */
class WorkspaceScopedExistsArchTest extends TestCase
{
    /**
     * FormRequests that legitimately need a RAW `exists:` on a registered table.
     *
     * Membership management intentionally references not-yet-members, so adding/inviting
     * a user must NOT be scoped to current membership. That logic lives in
     * WorkspacesController (a controller, not a FormRequest), so it is out of this test's
     * scope already; this allowlist exists for any FormRequest that ever needs the same.
     *
     * @var array<string, array<int, string>> fully-qualified request class => allowed tables
     */
    private const ALLOWLIST = [
        // \App\Modules\Workspaces\Http\Requests\SomeRequest::class => ['users'],
    ];

    public function test_no_form_request_uses_raw_exists_on_a_workspace_scoped_table(): void
    {
        $tables = WorkspaceScopedReference::tables();
        $this->assertNotEmpty($tables, 'The workspace-scoped table registry resolved empty — discovery is broken.');

        $violations = [];

        foreach ($this->formRequestFiles() as $file => $contents) {
            foreach ($this->rawExistsTables($contents) as $field => $table) {
                if (!in_array($table, $tables, true)) {
                    continue;
                }

                if (in_array($table, self::ALLOWLIST[$this->classOf($contents)] ?? [], true)) {
                    continue;
                }

                $violations[] = sprintf(
                    "%s: field `%s` uses raw exists:%s — replace with new ScopedExists(...) so the model's workspace global scope applies.",
                    basename($file),
                    $field,
                    $table,
                );
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Raw exists: on workspace-scoped tables found in FormRequests:\n" . implode("\n", $violations),
        );
    }

    /**
     * The detector itself must catch a planted violation — guards the test against
     * silently passing because its parsing stopped matching.
     */
    public function test_detector_flags_a_planted_raw_exists(): void
    {
        $planted = <<<'PHP'
        public function rules(): array
        {
            return [
                'assigned_id' => ['required', 'uuid', 'exists:users,id'],
                'form_id' => ['nullable', Rule::exists('forms', 'id')],
            ];
        }
        PHP;

        $found = $this->rawExistsTables($planted);

        $this->assertContains('users', $found);
        $this->assertContains('forms', $found);
    }

    /**
     * The real codebase must be clean for every registered table.
     */
    public function test_reference_form_requests_no_longer_leak(): void
    {
        $tables = WorkspaceScopedReference::tables();
        $offenders = [];

        foreach ($this->formRequestFiles() as $file => $contents) {
            foreach ($this->rawExistsTables($contents) as $table) {
                if (in_array($table, $tables, true)) {
                    $offenders[] = basename($file) . ' -> ' . $table;
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * @return array<string, string> absolute path => file contents
     */
    private function formRequestFiles(): array
    {
        $finder = (new Finder)
            ->files()
            ->in(app_path('modules'))
            ->path('Http/Requests')
            ->name('*.php');

        $files = [];

        foreach ($finder as $file) {
            $files[$file->getRealPath()] = $file->getContents();
        }

        return $files;
    }

    /**
     * Extract every table referenced by a raw `exists:` or `Rule::exists(...)` in the
     * given PHP source, keyed by the field where it appears (best effort).
     *
     * Matches both the string form `exists:<table>[,column]` and the fluent
     * `Rule::exists('<table>', ...)`.
     *
     * @return array<string, string> field => table (field is the table when it can't be located)
     */
    private function rawExistsTables(string $contents): array
    {
        $tables = [];

        // String form: 'exists:users,id' or "exists:forms"
        if (preg_match_all('/[\'"]exists:([a-zA-Z0-9_]+)/', $contents, $m)) {
            foreach ($m[1] as $table) {
                $tables[$table] = $table;
            }
        }

        // Fluent form: Rule::exists('users', ...) / Rule::exists("forms")
        if (preg_match_all('/Rule::exists\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $contents, $m)) {
            foreach ($m[1] as $table) {
                $tables[$table] = $table;
            }
        }

        return $tables;
    }

    private function classOf(string $contents): string
    {
        preg_match('/^namespace\s+(.+?);/m', $contents, $ns);
        preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $contents, $cls);

        return ($ns[1] ?? '') . '\\' . ($cls[1] ?? '');
    }
}
