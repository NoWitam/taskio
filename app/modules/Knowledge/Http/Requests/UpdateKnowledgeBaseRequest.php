<?php

namespace App\Modules\Knowledge\Http\Requests;

use App\Modules\Knowledge\Models\KnowledgeBase;

/**
 * Update shares the Store rules; what differs is AUTHORIZATION, and it is deliberately SPLIT.
 *
 * Editing a base's name/description is ordinary member work. Editing its CHARTER or its METADATA
 * SCHEMA is not: the charter defines what the base is for, and the schema retro-actively decides
 * whether every existing entry's metadata is still valid. Those are governance, so they need the
 * `manage` ability (base creator or workspace owner) while the rest needs only `update`.
 *
 * The split is enforced by ability, not by ignoring the fields: a member who sends a charter/schema
 * change is REFUSED (403) rather than having it silently dropped, because silently discarding a
 * submitted field is how people believe they changed something they did not.
 */
class UpdateKnowledgeBaseRequest extends StoreKnowledgeBaseRequest
{
    /**
     * The fields only a base's creator (or the workspace owner) may change.
     *
     * `relation_types` belongs here for the same reason `metadata_schema` does: narrowing the verb
     * list decides what statements the base is willing to record AT ALL, which is a decision about
     * what the base IS rather than an edit to its contents.
     */
    private const GOVERNED_FIELDS = ['charter', 'metadata_schema', 'relation_types'];

    public function authorize(): bool
    {
        $base = $this->base();

        if ($base === null) {
            return false;
        }

        if ($this->touchesGovernedFields($base)) {
            return $this->user()->can('manage', $base);
        }

        return $this->user()->can('update', $base);
    }

    /**
     * ABSENT MEANS UNCHANGED for every optional field. This is not a convenience — it is the other
     * half of the governance split. Without it a PATCH carrying only `{name}` would resolve the
     * charter to null and the schema to [], WIPING both; and because the payload does not mention
     * them, {@see touchesGovernedFields()} would see no governance change and let any member do it.
     * A member renaming a base would silently destroy the contract its entries are validated against.
     */
    public function resolvedDescription(): ?string
    {
        return $this->has('description') ? parent::resolvedDescription() : $this->base()?->description;
    }

    public function resolvedCharter(): ?string
    {
        return $this->has('charter') ? parent::resolvedCharter() : $this->base()?->charter;
    }

    /** @return array<int, array<string, mixed>> */
    public function resolvedMetadataSchema(): array
    {
        if ($this->has('metadata_schema')) {
            return parent::resolvedMetadataSchema();
        }

        $stored = $this->base()?->metadata_schema;

        return is_array($stored) ? array_values($stored) : [];
    }

    /**
     * Absent means unchanged, here as everywhere. The three-way distinction survives: absent keeps the
     * stored value (which may itself be null = all verbs), `[]` allows none, and a list narrows.
     *
     * @return array<int, string>|null
     */
    public function resolvedRelationTypes(): ?array
    {
        if ($this->has('relation_types')) {
            return parent::resolvedRelationTypes();
        }

        $stored = $this->base()?->relation_types;

        return is_array($stored) ? array_values($stored) : null;
    }

    /** The base's language falls back to its STORED value on update, not to the request locale. */
    public function resolvedLanguage(): string
    {
        $explicit = $this->input('language');

        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        $base = $this->base();

        return $base !== null ? (string) $base->language : parent::resolvedLanguage();
    }

    /** The base bound on the route. */
    private function base(): ?KnowledgeBase
    {
        $base = $this->route('base');

        return $base instanceof KnowledgeBase ? $base : null;
    }

    /**
     * Whether the payload would actually CHANGE a governed field. Merely echoing back the stored
     * charter (as a full-object PATCH from a form does) is not governance, so it must not demand the
     * governance ability — otherwise a member could not save a name change at all.
     */
    private function touchesGovernedFields(KnowledgeBase $base): bool
    {
        foreach (self::GOVERNED_FIELDS as $field) {
            if (!$this->has($field)) {
                continue;
            }

            $submitted = $this->input($field);
            $current = $base->getAttribute($field);

            if ($field === 'metadata_schema') {
                if (json_encode(array_values(is_array($submitted) ? $submitted : [])) !== json_encode(array_values(is_array($current) ? $current : []))) {
                    return true;
                }

                continue;
            }

            if (($submitted === '' ? null : $submitted) !== ($current === '' ? null : $current)) {
                return true;
            }
        }

        return false;
    }
}
