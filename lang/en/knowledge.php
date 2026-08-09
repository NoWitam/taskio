<?php

return [

    // Knowledge bases + entries (B1). The only localized server prose in this module: state-conflict
    // responses and the validation messages a writer can act on. Everything else is structural.
    'entries' => [
        'stale_write' => 'Someone else saved this entry while you were editing it. Reload it to see their version before saving again.',
        'slug_conflict' => 'Another entry is now using the address ":slug". Rename that entry, then restore this one.',
        'seed_is_alias' => '":seed" is another form of the name of an entry that already exists: ":title". Open that entry instead of writing a new one.',
    ],

    // The AI composer (B11a). Only the budget refusal is prose: every other outcome is a stable CODE
    // on the session (`failure_reason`) that the client words itself, because it owns the room and the
    // locale — a message composed here would be untranslatable copy baked into the API.
    'drafting' => [
        'ai_budget_exceeded' => 'The AI composer is unavailable: this workspace has reached its estimated monthly AI budget. It resets at the start of the next month, or an owner can raise the limit.',
        // Names the way forward, not just the refusal: repeating the search unchanged would cost the
        // same and find the same thing, whereas a revision makes the next search worth running.
        'context_already_expanded' => 'The context for this session has already been expanded. Ask for a revision first — the next one will search against what you get back.',
    ],

    // Indexing (B2a) — the one state-conflict a user can hit by pressing a button.
    'index' => [
        'not_retryable' => 'There is nothing to retry: this entry\'s indexing is ":status". Only a run that failed, ran out of AI budget, or finished partially can be retried.',
    ],

    // Graph edges (B2b). Only a machine's SUGGESTION can be dismissed: a wikilink is what the entry's
    // text says (edit the text), and a manual link was drawn on purpose (delete it).
    'links' => [
        'not_dismissable' => 'Only suggested links can be dismissed. Edit the entry to remove a link it writes, or delete a link you drew yourself.',
    ],

    // TYPED RELATIONS — the statements a base records about pairs of entries. These five are the only
    // refusals a well-formed, authorized request can hit, and each names what to do instead: a refusal
    // that says only "no" leaves the writer guessing whether they met a rule or a bug.
    'relations' => [
        'duplicate' => 'This base already records that relation. End the existing one, or give this one a different start date if it is a separate occasion.',
        'cap_reached' => 'This entry already has the maximum of :max active relations. End one that no longer holds, or split the entry — something connected to forty others is usually a category rather than a topic.',
        'pair_refused' => 'A ":from_entry_type" cannot ":relation_type" a ":to_entry_type". Check the direction, or correct the type of one of the entries.',
        'type_not_allowed' => 'This knowledge base does not use the ":relation_type" relation. Its owner can add it to the base\'s allowed relation types.',
        'property_refused' => 'This kind of relation has no ":property" field. Remove it, or put the detail in the description.',
        'dates_reversed' => 'This relation would end (:valid_to) before it began (:valid_from). Correct one of the two dates.',
        // A key the current proposal does not contain means the client is looking at an OUT-OF-DATE
        // preview — almost always because the draft was refined and the operations renumbered. Refused
        // rather than partially applied: acting on a stale selection is how a reviewer approves one
        // thing and gets another.
        'unknown_op_key' => 'This proposal has changed since you opened it (:keys). Reload the review and choose again.',
        // An ending and the relation that replaces it describe ONE change. Accepting half leaves the
        // base saying something nobody meant, so the pair is refused rather than half-applied — and
        // rather than silently completed, which would write something the reviewer never chose.
        'inseparable_ops' => 'These describe one change and must be accepted together, or not at all (:pairs).',
        // Unreachable from the current UI, but reported on the ARRAY so a client that filters on the
        // exact key  shows it in the operations panel rather than against a draft card.
        'duplicate_op_key' => 'The same operation was selected more than once. Reload the review and choose again.',
    ],

    // The relation VERBS read forwards, written to complete "<entry> …" so a panel renders a sentence
    // rather than a label: "Anna — is a member of — Acme".
    'relation_types' => [
        'member_of' => 'is a member of',
        'works_on' => 'works on',
        'knows' => 'knows',
        'created' => 'created',
        'owns' => 'owns',
        'located_in' => 'is located in',
        'participated_in' => 'took part in',
        'occurred_during' => 'occurred during',
        'part_of' => 'is part of',
        'is_a' => 'is a',
        'uses' => 'uses',
        'depends_on' => 'depends on',
        'precedes' => 'precedes',
        'caused' => 'caused',
        'opposes' => 'is in conflict with',
        'visited' => 'visited',
        'organized' => 'organised',
        'won' => 'won',
        'interacted_with' => 'interacted with',
        'related_to' => 'is related to',
    ],

    // The same verbs read BACKWARDS, for the panel on the other entry. Not derivable from the forward
    // form, which is exactly why the server sends both.
    'relation_types_inverse' => [
        'member_of' => 'has member',
        'works_on' => 'is worked on by',
        'knows' => 'knows',
        'created' => 'was created by',
        'owns' => 'is owned by',
        'located_in' => 'is the location of',
        'participated_in' => 'had participant',
        'occurred_during' => 'was the setting for',
        'part_of' => 'includes',
        'is_a' => 'is the category of',
        'uses' => 'is used by',
        'depends_on' => 'is depended on by',
        'precedes' => 'follows',
        'caused' => 'was caused by',
        'opposes' => 'is in conflict with',
        'visited' => 'was visited by',
        'organized' => 'was organised by',
        'won' => 'was won by',
        'interacted_with' => 'was approached by',
        'related_to' => 'is related to',
    ],

    'relation_states' => [
        'active' => 'current',
        'ended' => 'ended',
        'retracted' => 'retracted',
    ],

    // WHAT KIND of thing an entry is about. Only the relation matrix reads these, and an entry nobody
    // has classified is UNTYPED rather than "other".
    'entry_types' => [
        'person' => 'Person',
        'organization' => 'Organization',
        'event' => 'Event',
        'place' => 'Place',
        'product' => 'Product',
        'work' => 'Work',
        'concept' => 'Concept',
        'other' => 'Other',
    ],

    'validation' => [
        // Metadata schema (the base's declared fields).
        'schema_must_be_list' => 'The metadata schema must be a list of fields.',
        'schema_too_many_fields' => 'A knowledge base may declare at most :max metadata fields.',
        'field_key_invalid' => 'Each metadata field needs a key made of letters, digits and underscores, not starting with a digit.',
        'field_key_duplicate' => 'Metadata field keys must be distinct.',
        'field_label_invalid' => 'A metadata field label must be text of at most 255 characters.',

        // Metadata values (an entry's map).
        'metadata_must_be_object' => 'Metadata must be an object of field values.',
        'metadata_field_unknown' => 'The ":field" field is not declared in this knowledge base.',

        // The fail-closed template-directive guard. Each message names the syntax that was found, so
        // the writer knows what to remove rather than guessing what "invalid content" means.
        'directive_directive' => 'Knowledge content cannot contain editor directives (@[...]). Remove them and save again.',
        'directive_reference' => 'Knowledge content cannot contain template placeholders ({{ ... }}). Remove them and save again.',
        'directive_if_block' => 'Knowledge content cannot contain conditional template blocks (```if-block). Remove them and save again.',
        'directive_branch' => 'Knowledge content cannot contain conditional branch markers ([[IF]], [[ELSE_IF]], [[ELSE]]). Remove them and save again.',
        'directive_nul' => 'Knowledge content cannot contain null bytes.',

        // The chunk fan-out cap (B2a). This bounds the COST of indexing one entry and is independent
        // of the character cap: the same text split across many short headed sections produces more
        // passages than it would written as prose. Both numbers are named because without them the
        // writer has no way to judge how much to cut.
        'too_many_chunks' => 'This entry splits into :count passages, and one entry may have at most :max. Split it into several smaller entries.',

        // Slug (the entry's stable address).
        'slug_normalized' => 'That address is not in the expected form. Use ":slug".',
        'slug_taken' => 'Another entry in this knowledge base already uses that address.',

    ],

];
