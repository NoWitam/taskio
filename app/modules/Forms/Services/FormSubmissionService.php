<?php

namespace App\Modules\Forms\Services;

use App\Modules\Disk\Services\FileService;
use App\Modules\Forms\DTOs\FormSubmissionDTO;
use App\Modules\Forms\Enums\FormElementType;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Workflows\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FormSubmissionService
{
    public function __construct(private readonly FileService $files) {}

    public function create(FormSubmissionDTO $dto): FormSubmission
    {
        // Validate that the form exists and is enabled
        $form = Form::findOrFail($dto->form_id);

        if (!$form->canBeFilled()) {
            throw ValidationException::withMessages([
                'form_id' => ['Formularz musi być włączony przed dodaniem uzupełnień.'],
            ]);
        }

        // Manual submissions (submittable = Form) are approved immediately
        $approvedAt = null;
        if ($dto->submittable_type === $form->getMorphClass()) {
            $approvedAt = now();
        }

        $submission = FormSubmission::create([
            'form_id' => $dto->form_id,
            'submittable_type' => $dto->submittable_type,
            'submittable_id' => $dto->submittable_id,
            'data' => $dto->data,
            'form_content_version_id' => $form->latestContentVersion()?->id,
            'approved_at' => $approvedAt,
        ]);

        // Promote any uploaded file answers from temp to owned-by-this-submission, so they
        // survive the temp sweep and surface under the disk's read-only "Zasoby" tree. The
        // uuids were already validated (scoped, owned, live) in StoreFormSubmissionRequest.
        $fileIds = collect(FormElementType::collectFileAnswers($form->content ?? [], $dto->data))
            ->pluck('value')
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->values()
            ->all();

        $this->files->bindTempTo($submission, $fileIds);

        return $submission;
    }

    public function update(FormSubmission $submission, array $data): FormSubmission
    {
        // Cannot edit approved submissions
        if ($submission->isApproved()) {
            throw ValidationException::withMessages([
                'submission' => ['Nie można edytować zatwierdzonego uzupełnienia formularza.'],
            ]);
        }

        $submission->update([
            'data' => $data,
        ]);

        return $submission;
    }

    public function indexByForm(Request $request, string $formId)
    {
        return FormSubmission::query()
            // creator is polymorphic (User|WorkflowRun|Bot); load a run's workflow so
            // CreatorResource renders the automation name without an N+1 per row.
            ->with(['creator' => fn ($creator) => $creator->morphWith([WorkflowRun::class => ['workflow']]), 'submittable'])
            ->where('form_id', $formId)
            ->whereNotNull('approved_at')
            ->when(
                $request->boolean('trashed'),
                fn (Builder $query) => $query->onlyTrashed()
            )
            ->when(
                request()->array('sources'),
                fn (Builder $query, $sources) => $query->whereIn('submittable_type', $sources)
            )
            ->when(
                $request->filled('indexed'),
                fn (Builder $query) => $request->boolean('indexed')
                    ? $query->whereNotNull('indexed_at')
                    : $query->whereNull('indexed_at')
            )
            // `data` is a JSON DOCUMENT, not prose — the term has to be spelled the way the
            // document spells it before it can be matched. See asStoredJsonText().
            ->search('data', self::asStoredJsonText($request->get('search')))
            ->filterByDate('approved_at', $request)
            ->orderBy(
                'approved_at',
                $request->get('sort', 'newest') === 'oldest' ? 'asc' : 'desc'
            )
            ->cursorPaginate(12);
    }

    /**
     * Re-spell a search term the way `form_submissions.data` spells it, so a Polish answer can be
     * found at all.
     *
     * THE PROBLEM THIS EXISTS FOR. `data` is cast `array`, and Eloquent's JSON cast calls
     * `json_encode()` with NO flags (`HasAttributes::getJsonCastFlags()` only adds
     * `JSON_UNESCAPED_UNICODE` for the `json:unicode` cast, which this model does not use). So the
     * column physically holds `{"miejsce":"Pla\u017ca"}` — ASCII bytes, every non-ASCII
     * escaped. `Searchable` compiles to `"data"::text ilike ?`, and on a Postgres `json` column
     * (NOT `jsonb`) `::text` returns the stored text VERBATIM, escapes and all. A user typing
     * `plaża` was therefore searching escaped text for raw UTF-8 and got zero rows — silently, and
     * for every answer containing ą/ć/ę/ł/ń/ó/ś/ź/ż, which in a Polish-language product is most of
     * them. Measured on the development database: `plaża` 0→5, `słońca` 0→6, `uśmiech` 0→8.
     *
     * ORDER IS LOAD-BEARING, AND IT IS THE WHOLE SUBTLETY HERE. The encoded term carries backslash
     * characters (`ż` becomes the six ASCII characters `\u017c`), and the column holds those
     * backslashes as LITERAL BYTES. So
     * `Searchable`'s wildcard escaping — which turns `\` into `\\` — is exactly right, PROVIDED the
     * JSON encoding happens FIRST and the escaping second. Reverse them and the escaping's own
     * backslashes get JSON-escaped into `\\`, the scope escapes them again, and the pattern demands
     * a backslash the data does not contain: zero rows, quietly, which is the same failure mode
     * `Searchable` documents for its own ordering. Encoding belongs HERE, at the call site, before
     * the term is handed over — never inside or after the scope.
     * `FormSubmissionJsonSearchTest` pins that with a positive assertion.
     *
     * WHY `json_encode()` AND NOT A UNICODE ESCAPER. The target is not "escaped Unicode", it is
     * "byte-for-byte what Laravel wrote". `json_encode()` also escapes `/` as `\/` and `"` as `\"`
     * and control characters as `\n`/`\t`, so a hand-rolled `\uXXXX` encoder would match Polish
     * letters and then miss a URL. Using the same function is the only way to be sure. The
     * surrounding quotes come off with `substr($encoded, 1, -1)` — EXACTLY one character each side,
     * because `trim($encoded, '"')` eats a term's own quotes too: `"` encodes to `"\""` and trim
     * hands back a lone backslash.
     *
     * SCOPE. Only this call site searches a JSON column; the other 18 `->search()` calls in the
     * application target plain text columns holding raw UTF-8 and must NOT be encoded. That is why
     * this lives in the service and not in the shared trait, and why
     * `FormSubmissionJsonSearchTest::test_a_plain_text_column_is_still_searched_as_raw_utf8` exists:
     * without it, moving this encoding into the scope would leave every test in that class green
     * while silently breaking Polish search in the other eighteen places.
     *
     * The parameter is `?string` to MATCH THE SCOPE IT FEEDS, deliberately. A non-string `search`
     * (`?search[]=x`) raises a TypeError here exactly as it did when the request value went straight
     * into `scopeSearch(… ?string $term …)`. Widening it to `mixed` would quietly turn that 500 into
     * a 200 with an unfiltered list — a defensible hardening, but one this endpoint does not get to
     * make alone while the other eighteen call sites still 500.
     *
     * TWO LIMITS, NAMED RATHER THAN HIDDEN. (1) A term with no characters JSON escapes — `raport`,
     * `Zoja66` — encodes to itself, so those searches behave EXACTLY as before; that is a property,
     * pinned by test, not a coincidence. (2) Case-insensitivity survives only for ASCII: `ILIKE`
     * folds `RAPORT`/`raport` and the hex digits of an escape, but `Ł` (U+0141) and `ł` (U+0142)
     * encode to different sequences, so a lowercase term no longer matches an uppercase Polish
     * letter. Zero hits became case-sensitive hits — strictly better, but not free: of 362 distinct
     * diacritic words in real answers, 360 became findable and the two that did not are `łucja` and
     * `łukasz`, stored capitalised and found only when typed that way. The full fix is storage-side
     * (a `json:unicode` cast plus a data migration), which the owner deliberately deferred because
     * this one works on rows that already exist.
     */
    private static function asStoredJsonText(?string $term): ?string
    {
        $term = trim($term ?? '');

        // Blank stays blank so the scope's "no term, no filter" behaviour is untouched: encoding
        // first would turn a lone tab into the two-character string `\t`, which is NOT blank, and
        // the unfiltered list would come back empty. Defensive rather than reachable — Laravel's
        // global TrimStrings/ConvertEmptyStringsToNull have already nulled such a term by now — but
        // this method should no more depend on middleware it does not own than the scope does.
        if ($term === '') {
            return null;
        }

        $encoded = json_encode($term);

        // The only way this fails is input that is not valid UTF-8, which no stored document can
        // contain either. Handing the term back UNCHANGED makes that case behave exactly as it did
        // before this method existed — the bad bytes reach the bind and Postgres rejects them, a
        // pre-existing 500 shared by all 19 call sites and not this change's to fix. Returning ''
        // would be the tempting mistake: the scope reads blank as "no filter" and would answer a
        // malformed search with EVERY row.
        if (!is_string($encoded)) {
            return $term;
        }

        return substr($encoded, 1, -1);
    }

    public function findBySubmittable(string $submittableType, string $submittableId): ?FormSubmission
    {
        return FormSubmission::query()
            ->with(['form', 'creator' => fn ($creator) => $creator->morphWith([WorkflowRun::class => ['workflow']])])
            ->where('submittable_type', $submittableType)
            ->where('submittable_id', $submittableId)
            ->first();
    }
}
