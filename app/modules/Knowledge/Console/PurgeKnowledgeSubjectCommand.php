<?php

namespace App\Modules\Knowledge\Console;

use App\Modules\Knowledge\DTOs\SubjectPurgeReport;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Services\KnowledgeSubjectPurgeService;
use App\Modules\Knowledge\Support\SubjectPhrases;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * "Erase everything about person X" — the operator's tool for honouring a data-subject erasure
 * request against the KNOWLEDGE MODULE.
 *
 * ================================ SCOPE — READ THIS FIRST ================================
 * This command covers the KNOWLEDGE MODULE AND NOTHING ELSE: knowledge entries, their append-only
 * revision history, their embedded passages (vectors), and the graph edges that name them. It does
 * NOT touch tasks, forms and their submissions, approvals, files on the disk, bot or generation
 * output, workflow runs, comments, or any other module — each of which may hold the same person's
 * data, and none of which this command is entitled to speak for.
 *
 * A COMPLETE right-to-be-forgotten across every module is a separate, larger piece of work: it needs
 * a cross-module inventory of where personal data can legitimately come to rest, a policy per module
 * for what "erased" means there (a form submission is evidence of a business transaction, not a note
 * about a person), and one coordinated report. Nothing here should be mistaken for that. An operator
 * archiving this report as proof of compliance is archiving proof about the knowledge base only.
 *
 * The reason this module goes first is that it is the module where personal data is most likely to
 * sit as free prose that nothing else will ever clean up, and where the append-only revision history
 * means the ordinary "edit it out" gesture provably does NOT erase anything.
 * =========================================================================================
 *
 * SAFETY, in the order it applies:
 *   1. DRY RUN IS THE DEFAULT. Without --apply nothing is deleted, and the full report is printed.
 *      The destructive mode is the one you have to ask for.
 *   2. BREADTH CAP. More than `knowledge.purge.max_entries` matched RECORDS — every category this
 *      command deletes, not entries alone — is treated as a phrase mistake rather than a large
 *      request, and refused without --force. It counted entries only for a while, which let a phrase
 *      matching no entry and thousands of draft sessions past a guard that never looked at them.
 *   3. TYPED CONFIRMATION. --apply asks the operator to retype the number of matches, so an apply
 *      cannot be reached without having read the report. --force skips it for scripted use, and a
 *      non-interactive terminal without --force is refused rather than auto-confirmed.
 *
 * =========================== SCANNING IS WIDER THAN DELETING ===========================
 * One category is REPORT-ONLY: a knowledge base whose CHARTER names the subject is named in the
 * report and never touched. The two decisions are separate and both are deliberate.
 *
 * It is scanned because the charter is the most active copy of the text in the system — it is
 * prepended to the compiled knowledge block on EVERY generation against that base, so the name goes
 * to the AI provider continuously. Omitting it let an operator read "0 records" and certify an
 * erasure that had not happened.
 *
 * It is not deleted because the charter is the BASE's editorial policy: deleting the base would
 * destroy every entry in it, and blanking the charter would silently change how every future
 * generation behaves. Neither is this command's decision to make. If you are here to "finish" this by
 * making it delete something — that is the bug, not the omission.
 *
 * Charters are therefore excluded from `totalMatches()`, so a report-only finding can never refuse an
 * apply (whose only escape is --force, which also skips the typed confirmation — see
 * {@see SubjectPurgeReport::totalMatches()}).
 * =======================================================================================
 *
 * The phrases are matched LITERALLY and case-insensitively; the operator supplies inflected variants
 * themselves (`"Kowalska" "Kowalskiej"`). There is deliberately no AI and no stemming here — see
 * {@see SubjectPhrases}.
 *
 * The phrase NEVER reaches the application log. It appears in the operator's report, which is the
 * artefact archived against the request, and nowhere else.
 */
class PurgeKnowledgeSubjectCommand extends Command
{
    protected $signature = 'knowledge:purge-subject
        {workspace : The workspace id to purge within}
        {phrase* : One or more literal phrases; supply inflected variants yourself}
        {--base= : Restrict to a single knowledge base id}
        {--apply : Actually delete. Without this the command only reports.}
        {--force : Skip the typed confirmation and the breadth cap}
        {--json : Emit the machine-readable report and nothing else}';

    protected $description = 'Erase every trace of a subject (person) from the knowledge module: entries, revision history, embeddings and ghost links. Dry run unless --apply.';

    public function handle(TenantContext $context, TenantManager $tenants, KnowledgeSubjectPurgeService $purge): int
    {
        $workspaceId = (string) $this->argument('workspace');

        // Checked before the lookup: every id in this schema is a uuid, and handing a malformed one to
        // Postgres raises a driver-level type error instead of the answer the operator needs.
        $workspace = Str::isUuid($workspaceId) ? Workspace::find($workspaceId) : null;

        if ($workspace === null) {
            return $this->refuse('workspace_not_found', 'Workspace not found.');
        }

        $phrases = SubjectPhrases::fromInput((array) $this->argument('phrase'));

        if ($phrases->isEmpty()) {
            return $this->refuse('no_usable_phrase', 'No usable phrase given. A blank phrase would match everything.');
        }

        // Tenancy for the whole run: shared-mode rows are scoped by workspace_id, own-database rows
        // live on the tenant connection. Restored in the finally so a failure cannot leave a console
        // process (or the rest of a test suite) pointed at someone else's database.
        $context->set($workspace);
        $workspace->db_mode === WorkspaceDbMode::Own
            ? $tenants->configure($workspace)
            : $tenants->forget();

        try {
            $baseId = $this->option('base');

            if (is_string($baseId) && $baseId !== '' && !$this->baseExists($baseId)) {
                // Refused rather than scanned: an unknown base narrows the scan to nothing, and
                // "0 matches" is the one answer an erasure request must never get by accident.
                return $this->refuse('base_not_found', 'Knowledge base not found in this workspace.');
            }

            $report = $purge->scan((string) $workspace->id, $phrases, $baseId ?: null);

            return $this->decide($report, $phrases, $purge);
        } catch (QueryException $exception) {
            // THE PHRASE MUST NOT REACH THE LOG, and a QueryException is the one path that would carry
            // it there. Laravel interpolates the BINDINGS into the exception message, so a failure
            // anywhere in the scan produces a message containing `%Anna Kowalska%` — which the console
            // kernel then hands to `report()`, writing the erased name into a shared, long-lived,
            // widely-readable file. The command that exists to remove a name would have published it.
            //
            // Rethrown as a plain runtime error naming the SQLSTATE and nothing else. The operator
            // still learns the query failed and gets a non-zero exit; the diagnosis is available from
            // the database's own logs, which are not the surface this rule is about. Deliberately NOT
            // caught wider than QueryException: an ordinary bug should still produce its real trace.
            throw new RuntimeException(
                'The knowledge purge scan failed at the database level (SQLSTATE '
                . (string) ($exception->errorInfo[0] ?? 'unknown')
                . '). The message is withheld because it would contain the erasure phrase.',
            );
        } finally {
            $context->clear();
            $tenants->forget();
        }
    }

    /** The decision ladder once the scan is in hand. */
    private function decide(SubjectPurgeReport $report, SubjectPhrases $phrases, KnowledgeSubjectPurgeService $purge): int
    {
        $max = max(1, (int) config('knowledge.purge.max_entries'));

        // COUNTED OVER EVERYTHING THE PHRASE REACHED, not over entries alone.
        //
        // The breadth detector used to look at `entries` only, so a phrase matching NO entry and five
        // thousand draft sessions sailed straight through — and abandoning a session destroys its
        // drafts, which means the loosest possible phrase could delete the most work with no guard at
        // all. Every category in `totalMatches()` is something this command DESTROYS, so the detector
        // has to see the same list the operator is being asked to certify.
        $matched = $report->totalMatches();

        if ($matched > $max && !$this->option('force')) {
            // The report is still printed: the operator needs to SEE what the phrase dragged in to
            // narrow it. The non-zero exit is what stops a script chaining this into an --apply.
            $this->render($report->refusedAs('too_broad'));

            if (!$this->option('json')) {
                $this->newLine();
                $this->error("Phrase too broad — narrow it. It matches {$matched} records and the limit is {$max} (knowledge.purge.max_entries). Pass --force if this is genuinely intended.");
            }

            return self::FAILURE;
        }

        if (!$this->option('apply')) {
            $this->render($report);

            if (!$this->option('json')) {
                $this->newLine();
                // "Nothing matched" is only true when nothing was FLAGGED either. A charter outstanding
                // with an all-clear printed under it is the false certification this category exists to
                // prevent, so the wording branches on both.
                $this->line(match (true) {
                    !$report->isEmpty() => 'Dry run — nothing was deleted. Re-run with --apply to erase the above.',
                    $report->hasCharters() => 'Nothing to erase automatically — but the base charters above still name the subject and need editing by hand.',
                    default => 'Nothing matched. Nothing was deleted (this was a dry run).',
                });
            }

            return self::SUCCESS;
        }

        if ($report->isEmpty()) {
            $this->render($report);

            if (!$this->option('json')) {
                $this->newLine();
                $this->line($report->hasCharters()
                    ? 'Nothing to erase automatically — but the base charters above still name the subject and need editing by hand.'
                    : 'Nothing matched. Nothing to erase.');
            }

            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirmed($report)) {
            $this->render($report->refusedAs('not_confirmed'));

            if (!$this->option('json')) {
                $this->newLine();
                $this->error('Confirmation did not match. Nothing was deleted.');
            }

            return self::FAILURE;
        }

        $this->render($purge->apply($report, $phrases));

        if (!$this->option('json')) {
            $this->newLine();
            $this->info('Erased. Archive the report above against the erasure request.');

            // The apply does not close the request while a charter still names the subject, and the
            // operator hears that at the end, where they are deciding whether they are finished.
            if ($report->hasCharters()) {
                $this->warn('NOT FINISHED: the base charters listed above still name the subject. Edit them by hand, then re-run to confirm.');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Make the operator retype the match count.
     *
     * A yes/no prompt is muscle memory; a number that only exists in the report they were just shown
     * is not. Refused outright when there is nobody to ask — auto-confirming an irreversible delete
     * because stdin happened to be a pipe is exactly the accident --force exists to make explicit.
     *
     * --json counts as "nobody to ask" for two reasons: its caller is a machine that cannot answer,
     * and a prompt written to stdout would break the guarantee that the JSON report is the only thing
     * on it.
     */
    private function confirmed(SubjectPurgeReport $report): bool
    {
        if ($this->option('json') || !$this->input->isInteractive()) {
            if (!$this->option('json')) {
                $this->error('Refusing to apply without confirmation on a non-interactive terminal. Pass --force if this is intended.');
            }

            return false;
        }

        $expected = (string) $report->totalMatches();

        return trim((string) $this->ask("Type {$expected} to confirm erasing {$expected} match(es). This cannot be undone")) === $expected;
    }

    /** Scoped by the active workspace, so a base id from another tenant reads as "not found". */
    private function baseExists(string $baseId): bool
    {
        return Str::isUuid($baseId) && KnowledgeBase::withTrashed()->whereKey($baseId)->exists();
    }

    // ---- rendering ------------------------------------------------------------

    private function render(SubjectPurgeReport $report): void
    {
        if ($this->option('json')) {
            // With --json stdout carries EXACTLY one JSON document and no prose, so the report can be
            // piped straight into the operator's compliance archive.
            $this->output->writeln($this->json($report->toArray()));

            return;
        }

        $this->text($report);
    }

    private function text(SubjectPurgeReport $report): void
    {
        $this->newLine();
        $this->line('Knowledge subject purge — ' . ($report->applied ? 'APPLIED (irreversible)' : 'DRY RUN (nothing deleted)'));
        $this->line('Scope:     the knowledge module only — other modules are NOT covered.');
        $this->line('Workspace: ' . $report->workspaceId);
        $this->line('Base:      ' . ($report->baseId ?? 'all bases in this workspace'));
        $this->line('Phrases:   ' . implode(' | ', $report->phrases));
        $this->newLine();

        $this->line('Entries matching CURRENT text (whole entry purged): ' . count($report->entries));

        foreach ($report->entries as $entry) {
            $this->line(sprintf(
                '  - %s "%s"%s [%s] — %d revision(s), %d chunk(s), %d backlink(s) — %s',
                $entry->slug,
                $entry->title,
                $entry->trashed ? ' (in trash)' : '',
                implode(', ', $entry->matchedIn),
                $entry->revisions,
                $entry->chunks,
                $entry->incomingLinks,
                $entry->id,
            ));
        }

        $this->line('Revisions matching HISTORY ONLY (revision deleted, entry kept): ' . count($report->revisions));

        foreach ($report->revisions as $revision) {
            $this->line(sprintf(
                '  - %s @ %s [%s] — %s',
                $revision->entrySlug,
                $revision->createdAt ?? 'unknown date',
                implode(', ', $revision->matchedIn),
                $revision->id,
            ));
        }

        $this->line('Ghost links whose target slug matches (deleted): ' . count($report->ghostLinks));

        foreach ($report->ghostLinks as $link) {
            $this->line(sprintf('  - [[%s]] from entry %s (%s) — %s', $link->targetSlug, $link->fromEntryId, $link->source, $link->id));
        }

        // Relations whose OWN text names the subject. Usually zero — purging the two entries takes
        // their relations with them — and non-zero in exactly the case nothing else here would reach:
        // a name written on the EDGE, in a description or a property, that neither entry mentions. The
        // matched text is never printed, for the same reason the session's is not.
        $this->line('Relations whose description or properties match (deleted): ' . count($report->relations));

        foreach ($report->relations as $relation) {
            $this->line(sprintf(
                '  - %s between %s and %s [%s] — %s',
                $relation->relationType,
                $relation->fromEntryId,
                $relation->toEntryId,
                implode(', ', $relation->matchedIn),
                $relation->id,
            ));
        }

        // AUDIT LINES whose snapshots name the subject while their relation does not — the residue of
        // the responsible remedy: somebody edited the name out of a description, and the `before`
        // snapshot of that very edit kept it. Deleted line by line, so the relation keeps the rest of
        // its history. The snapshots themselves are never printed, for the same reason as above.
        $this->line('Relation audit lines matching HISTORY ONLY (line deleted, relation kept): ' . count($report->relationEvents));

        foreach ($report->relationEvents as $event) {
            $this->line(sprintf(
                '  - %s on relation %s [%s] — %s',
                $event->operation,
                $event->relationId,
                implode(', ', $event->matchedIn),
                $event->id,
            ));
        }

        // Sessions count towards the confirmation number the operator retypes, so they have to be
        // VISIBLE in the report that number is read from — otherwise the typed confirmation certifies
        // deletions nobody was shown, which is the one thing that ritual exists to prevent.
        //
        // The matched TEXT is never printed. `source_text` is the raw material somebody pasted: it is
        // the personal data the request is about, and a report is archived. `matched_in` says which
        // field carried the phrase, which is what an operator actually needs to judge the hit.
        $this->line('Drafting sessions whose pasted material matches (session abandoned, drafts purged): ' . count($report->sessions));

        foreach ($report->sessions as $session) {
            $this->line(sprintf(
                '  - session %s in base %s (%s) [%s] — %d draft(s)',
                $session->id,
                $session->baseId,
                $session->status,
                implode(', ', $session->matchedIn),
                $session->drafts,
            ));
        }

        $this->newLine();
        // Placeholder with a purpose — see SubjectPurgeReport::toArray().
        // THE ONE REPORT-ONLY SECTION. Printed even on an --apply, because after the erasure has run
        // these are still outstanding — the archived report has to say so.
        if ($report->hasCharters()) {
            $this->newLine();
            $this->line('BASE CHARTERS naming the subject — NOT DELETED, MANUAL EDIT REQUIRED: ' . count($report->charters));
            $this->line('  A charter is prepended to the knowledge block on EVERY generation against its base,');
            $this->line('  so this text is still being sent to the AI provider. It is not deleted here: the charter');
            $this->line('  belongs to the base, and removing one sentence while keeping the policy coherent needs a');
            $this->line('  person. Edit each base below by hand, then re-run this command to confirm it is clear.');

            foreach ($report->charters as $charter) {
                $this->line(sprintf('  - base %s (%s) — edit its charter', $charter->baseName, $charter->baseId));
            }

            $this->newLine();
        }

        $this->line('Generation session snapshots: 0 (knowledge snapshots do not yet carry entry text verbatim; this section starts reporting when they do)');
        $this->newLine();
        $this->line(sprintf(
            'Total matches: %d (cascade: %d further revision(s), %d chunk(s), %d session draft(s))',
            $report->totalMatches(),
            $report->cascadedRevisions(),
            $report->cascadedChunks(),
            $report->cascadedSessionDrafts(),
        ));
    }

    /** Emit a machine-readable refusal under --json, a human line otherwise. Always non-zero. */
    private function refuse(string $code, string $message): int
    {
        if ($this->option('json')) {
            $this->output->writeln($this->json([
                'command' => 'knowledge:purge-subject',
                'scope' => 'knowledge',
                'error' => $code,
            ]));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }

    /** @param  array<string, mixed>  $payload */
    private function json(array $payload): string
    {
        return (string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
