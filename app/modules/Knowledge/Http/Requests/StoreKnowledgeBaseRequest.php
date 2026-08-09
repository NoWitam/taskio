<?php

namespace App\Modules\Knowledge\Http\Requests;

use App\Modules\Knowledge\Enums\KnowledgeRelationType;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Services\KnowledgeMetadataValidator;
use App\Modules\Knowledge\Support\TemplateDirectiveGuard;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a KNOWLEDGE BASE on create.
 *
 * Two checks beyond the flat rules, both delegated so this module owns no duplicate authority:
 *   - the METADATA SCHEMA goes to {@see KnowledgeMetadataValidator}, which in turn delegates the type
 *     judgement to the shared descriptor validator;
 *   - every prose field goes through {@see TemplateDirectiveGuard}, because a base's charter and
 *     description are retrieved and injected exactly like an entry's body is.
 */
class StoreKnowledgeBaseRequest extends FormRequest
{
    /** A short language tag: `pl`, `en`, `pt-BR`. Bounded by the 5-char column. */
    private const LANGUAGE = '/^[a-z]{2}(-[A-Za-z]{2})?$/';

    public function authorize(): bool
    {
        return $this->user()->can('create', KnowledgeBase::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'charter' => ['nullable', 'string', 'max:10000'],
            // The list shape + each field's type are checked in withValidator (granular errors).
            'metadata_schema' => ['nullable', 'array'],
            // The relation verbs this base allows. ABSENT means the whole vocabulary; an empty ARRAY
            // means none at all — see RelationVocabulary for why those have to stay different answers.
            'relation_types' => ['nullable', 'array'],
            'relation_types.*' => [Rule::in(KnowledgeRelationType::ids())],
            'language' => ['nullable', 'string', 'max:5', 'regex:' . self::LANGUAGE],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $guard = app(TemplateDirectiveGuard::class);

            foreach (['name', 'description', 'charter'] as $field) {
                $violation = $guard->violation($this->input($field));

                if ($violation !== null) {
                    $validator->errors()->add($field, __('knowledge.validation.directive_' . $violation));
                }
            }

            if ($this->has('metadata_schema')) {
                app(KnowledgeMetadataValidator::class)->validateSchema($validator, $this->input('metadata_schema'));
            }
        });
    }

    public function resolvedDescription(): ?string
    {
        return $this->filled('description') ? $this->string('description')->value() : null;
    }

    public function resolvedCharter(): ?string
    {
        return $this->filled('charter') ? $this->string('charter')->value() : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function resolvedMetadataSchema(): array
    {
        $schema = $this->input('metadata_schema');

        return is_array($schema) ? array_values($schema) : [];
    }

    /**
     * The allow-list, or NULL for "the whole vocabulary".
     *
     * Null and `[]` are kept apart all the way to the column, because a base that has never been asked
     * about relation types must not end up quietly stricter than one whose owner deliberately allowed
     * none.
     *
     * @return array<int, string>|null
     */
    public function resolvedRelationTypes(): ?array
    {
        if (!$this->has('relation_types')) {
            return null;
        }

        $types = $this->input('relation_types');

        if (!is_array($types)) {
            return null;
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($type): string => is_string($type) ? $type : '', $types),
            static fn (string $type): bool => in_array($type, KnowledgeRelationType::ids(), true),
        )));
    }

    /**
     * The base's language: explicit, else the active app locale truncated to the column width. A base
     * has to declare one — retrieval quality depends on knowing what language its text is in — and
     * the request's own locale is the only honest guess available at this layer.
     */
    public function resolvedLanguage(): string
    {
        $explicit = $this->input('language');

        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        return substr((string) app()->getLocale(), 0, 5);
    }
}
