// Unit tests for the isolated "next" i18n layer.
//
// Covers the `t()` contract (dot-path lookup, {param} interpolation, defaultValue
// + key fallback on a miss), runtime locale switching, and — crucially — 1:1 key
// parity between the `en` and `pl` catalogs so a missing translation is caught at
// CI time rather than shipping an untranslated string.
import { beforeEach, describe, expect, it } from 'vitest';
import { useI18n, setLocale, translate, AVAILABLE_LOCALES } from '../index';
import { en } from '../en';
import { pl } from '../pl';

/** Recursively collect every dot-path leaf key from a nested catalog object. */
function leafKeys(obj: Record<string, unknown>, prefix = ''): string[] {
  return Object.entries(obj).flatMap(([key, value]) => {
    const path = prefix ? `${prefix}.${key}` : key;
    return value && typeof value === 'object'
      ? leafKeys(value as Record<string, unknown>, path)
      : [path];
  });
}

describe('next i18n', () => {
  beforeEach(() => {
    // Reset to a known locale; switching is a no-op-safe singleton operation.
    setLocale('en');
  });

  describe('t() lookup + interpolation', () => {
    it('resolves a dot-path key in the active locale', () => {
      expect(translate('common.save')).toBe('Save');
      setLocale('pl');
      expect(translate('common.save')).toBe('Zapisz');
    });

    it('interpolates {param} tokens', () => {
      expect(
        translate('pagination.summary', undefined, { from: 1, to: 10, total: 42 }),
      ).toBe('1–10 of 42');
    });

    it('interpolates the same token appearing multiple times', () => {
      // `replaceAll` semantics — a synthetic default with a repeated token.
      expect(translate('___none___', '{x} and {x}', { x: 'A' })).toBe('A and A');
    });

    it('falls back to defaultValue on a missing key', () => {
      expect(translate('does.not.exist', 'Fallback')).toBe('Fallback');
    });

    it('interpolates the defaultValue fallback too', () => {
      expect(translate('does.not.exist', 'Hi {name}', { name: 'Ada' })).toBe('Hi Ada');
    });

    it('returns the key itself when there is no value and no default', () => {
      expect(translate('totally.missing.key')).toBe('totally.missing.key');
    });

    it('does not resolve a non-leaf (object) path to "[object Object]"', () => {
      // `common` is an object, not a string — should miss and use the default.
      expect(translate('common', 'fallback')).toBe('fallback');
    });
  });

  describe('useI18n composable', () => {
    it('exposes a reactive locale that reflects setLocale', () => {
      const { locale, currentLocale, setLocale: set } = useI18n();
      set('pl');
      expect(locale.value).toBe('pl');
      expect(currentLocale.value).toBe('pl');
      set('en');
      expect(locale.value).toBe('en');
    });

    it('lists the available locales', () => {
      const { availableLocales } = useI18n();
      expect([...availableLocales]).toEqual([...AVAILABLE_LOCALES]);
      expect(availableLocales).toContain('pl');
      expect(availableLocales).toContain('en');
    });
  });

  describe('catalog parity (pl must match en)', () => {
    it('has identical key sets in en and pl', () => {
      const enKeys = leafKeys(en).sort();
      const plKeys = leafKeys(pl).sort();

      const missingInPl = enKeys.filter((k) => !plKeys.includes(k));
      const extraInPl = plKeys.filter((k) => !enKeys.includes(k));

      expect(missingInPl, `keys missing in pl: ${missingInPl.join(', ')}`).toEqual([]);
      expect(extraInPl, `keys present only in pl: ${extraInPl.join(', ')}`).toEqual([]);
    });

    it('has no empty translation strings', () => {
      for (const [catalog, name] of [
        [en, 'en'],
        [pl, 'pl'],
      ] as const) {
        for (const key of leafKeys(catalog as Record<string, unknown>)) {
          expect(translate.length).toBeGreaterThan(0); // guard noop
          const value = key
            .split('.')
            .reduce<unknown>((acc, part) => (acc as Record<string, unknown>)?.[part], catalog);
          expect(typeof value, `${name}.${key} should be a string`).toBe('string');
          expect((value as string).length, `${name}.${key} is empty`).toBeGreaterThan(0);
        }
      }
    });
  });

  describe('video_script rework keys (shot_list + storyboard) exist in both catalogs', () => {
    // The new part kinds' editor + result strings must be present + non-empty in BOTH locales (a targeted
    // guard on top of the whole-catalog parity above — so a half-added key set fails loudly here).
    const NEW_KEYS = [
      'generator.templates.editor.partLabel.shot_list',
      'generator.templates.editor.partLabel.storyboard',
      'generator.templates.editor.shotList.briefLabel',
      'generator.templates.editor.shotList.briefHelp',
      'generator.templates.editor.shotList.briefPlaceholder',
      'generator.templates.editor.shotList.previewNote',
      'generator.templates.editor.storyboard.help',
      'generator.templates.editor.storyboard.styleLabel',
      'generator.templates.editor.storyboard.stylePlaceholder',
      'generator.templates.editor.storyboard.previewNote',
      'generator.sessions.result.hook',
      'generator.sessions.result.cta',
      'generator.sessions.result.shot',
      'generator.sessions.result.visual',
      'generator.sessions.result.voiceover',
      'generator.sessions.result.seconds',
      'generator.sessions.result.shotImageAlt',
      'generator.sessions.result.parseFallback',
      'generator.sessions.result.storyboardEmpty',
      'generator.sessions.result.regenerateStoryboard',
      'generator.sessions.result.stale',
      'generator.sessions.result.staleHint',
    ];

    it.each(NEW_KEYS)('%s resolves to a non-empty string in en and pl', (key) => {
      setLocale('en');
      const enValue = translate(key);
      expect(enValue, `en.${key} missing`).not.toBe(key);
      expect(enValue.length).toBeGreaterThan(0);
      setLocale('pl');
      const plValue = translate(key);
      expect(plValue, `pl.${key} missing`).not.toBe(key);
      expect(plValue.length).toBeGreaterThan(0);
    });
  });

  describe('bot delegation keys (R2 sub-stage 3) exist in both catalogs', () => {
    // The delegate/undo + fill-report + author-chip strings must be present + non-empty in BOTH locales.
    const NEW_KEYS = [
      'generator.sessions.delegate.button',
      'generator.sessions.delegate.buttonDisabled',
      'generator.sessions.delegate.dialogTitle',
      'generator.sessions.delegate.dialogSubtitle',
      'generator.sessions.delegate.autoGenerate',
      'generator.sessions.delegate.autoGenerateHelp',
      'generator.sessions.delegate.confirm',
      'generator.sessions.delegate.emptyTitle',
      'generator.sessions.delegate.emptyDescription',
      'generator.sessions.delegate.emptyAction',
      'generator.sessions.delegate.authoredBy',
      'generator.sessions.delegate.undo',
      'generator.sessions.delegate.undoConfirmTitle',
      'generator.sessions.delegate.undoConfirmMessage',
      'generator.sessions.delegate.report.title',
      'generator.sessions.delegate.report.filledSummary',
      'generator.sessions.delegate.report.skipped',
      'generator.sessions.delegate.report.noneFilled',
      'generator.sessions.delegate.report.unfilled',
      'generator.sessions.delegate.report.needsYourFile',
      'generator.sessions.delegate.report.completeInputs',
      'generator.sessions.delegate.report.reason.unknown_slot',
      'generator.sessions.delegate.report.reason.out_of_scope',
      'generator.sessions.delegate.report.reason.invalid',
      'generator.sessions.delegate.toasts.ok',
      'generator.sessions.delegate.toasts.autoOk',
      'generator.sessions.delegate.toasts.busy',
      'generator.sessions.delegate.toasts.notEditable',
      'generator.sessions.delegate.toasts.error',
      'generator.sessions.delegate.toasts.undone',
      'generator.sessions.delegate.toasts.undoError',
    ];

    it.each(NEW_KEYS)('%s resolves to a non-empty string in en and pl', (key) => {
      setLocale('en');
      const enValue = translate(key);
      expect(enValue, `en.${key} missing`).not.toBe(key);
      expect(enValue.length).toBeGreaterThan(0);
      setLocale('pl');
      const plValue = translate(key);
      expect(plValue, `pl.${key} missing`).not.toBe(key);
      expect(plValue.length).toBeGreaterThan(0);
    });

    // Click-time FILL MODE (gaps vs fresh) + the honest "nothing to fill" report state.
    const FILL_MODE_KEYS = [
      'generator.sessions.delegate.fillMode.legend',
      'generator.sessions.delegate.fillMode.gaps',
      'generator.sessions.delegate.fillMode.gapsHelp',
      'generator.sessions.delegate.fillMode.fresh',
      'generator.sessions.delegate.fillMode.freshHelp',
      'generator.sessions.delegate.report.nothingTitle',
      'generator.sessions.delegate.report.modeLine',
      'generator.sessions.delegate.report.mode.gaps',
      'generator.sessions.delegate.report.mode.fresh',
      'generator.sessions.delegate.report.nothingToFill',
      'generator.sessions.delegate.report.nothingToFillNoSpend',
      'generator.sessions.delegate.report.nothingToFillHint',
      'generator.sessions.delegate.toasts.nothingToFill',
    ];

    it.each(FILL_MODE_KEYS)('fill-mode key %s resolves in en and pl', (key) => {
      setLocale('en');
      const enValue = translate(key);
      expect(enValue, `en.${key} missing`).not.toBe(key);
      expect(enValue.length).toBeGreaterThan(0);
      setLocale('pl');
      const plValue = translate(key);
      expect(plValue, `pl.${key} missing`).not.toBe(key);
      expect(plValue.length).toBeGreaterThan(0);
    });

    it('the fresh-mode helper promises undo restores the human values in both locales', () => {
      setLocale('en');
      expect(translate('generator.sessions.delegate.fillMode.freshHelp')).toContain('Undo');
      setLocale('pl');
      expect(translate('generator.sessions.delegate.fillMode.freshHelp')).toContain('Cofnięcie');
    });

    it('the authoredBy string interpolates the bot {name} in both locales', () => {
      setLocale('en');
      expect(translate('generator.sessions.delegate.authoredBy', undefined, { name: 'Copy Bot' })).toBe(
        'Authored by Copy Bot',
      );
      setLocale('pl');
      expect(translate('generator.sessions.delegate.authoredBy', undefined, { name: 'Copy Bot' })).toBe(
        'Autor: Copy Bot',
      );
    });
  });

  describe('AI cost limits keys (R2 sub-stage 4) exist in both catalogs', () => {
    // The usage-page + owner-editor + inline generator budget strings must be present + non-empty in BOTH
    // locales (a targeted guard on top of the whole-catalog parity above).
    const NEW_KEYS = [
      'userMenu.aiUsage',
      'workspaces.aiUsage.title',
      'workspaces.aiUsage.used',
      'workspaces.aiUsage.cap',
      'workspaces.aiUsage.remaining',
      'workspaces.aiUsage.noLimit',
      'workspaces.aiUsage.estimatedCaveat',
      'workspaces.aiUsage.resetsOn',
      'workspaces.aiUsage.state.ok',
      'workspaces.aiUsage.state.warn',
      'workspaces.aiUsage.state.blocked',
      'workspaces.aiUsage.state.unlimited',
      'workspaces.aiUsage.capSource.workspace',
      'workspaces.aiUsage.capSource.default',
      'workspaces.aiUsage.capSource.unlimited',
      'workspaces.aiUsage.channel.ai_text',
      'workspaces.aiUsage.channel.ai_image_edit',
      'workspaces.aiUsage.channel.ai_image_generate',
      'workspaces.aiUsage.actor.user',
      'workspaces.aiUsage.actor.bot',
      'workspaces.aiUsage.actor.workflow_run',
      'workspaces.aiUsage.actor.others',
      'workspaces.aiUsage.editor.limitOption',
      'workspaces.aiUsage.editor.unlimitedOption',
      'workspaces.aiUsage.editor.defaultOption',
      'workspaces.aiUsage.editor.amountLabel',
      'workspaces.aiUsage.editor.save',
      // The CHIP's copy stays with the Generator; the BANNER's moved to the shared `aiBudget.*`
      // block when the component was promoted to the design system (B15a).
      'generator.sessions.budget.chipWarn',
      'generator.sessions.budget.chipBlocked',
      'aiBudget.blockedTitle',
      'aiBudget.blockedMessage',
      'aiBudget.raiseLimit',
      'aiBudget.contactOwner',
    ];

    it.each(NEW_KEYS)('%s resolves to a non-empty string in en and pl', (key) => {
      setLocale('en');
      const enValue = translate(key);
      expect(enValue, `en.${key} missing`).not.toBe(key);
      expect(enValue.length).toBeGreaterThan(0);
      setLocale('pl');
      const plValue = translate(key);
      expect(plValue, `pl.${key} missing`).not.toBe(key);
      expect(plValue.length).toBeGreaterThan(0);
    });

    it('the warn chip interpolates the {percent} in both locales', () => {
      setLocale('en');
      expect(translate('generator.sessions.budget.chipWarn', undefined, { percent: 80 })).toBe('80% of budget');
      setLocale('pl');
      expect(translate('generator.sessions.budget.chipWarn', undefined, { percent: 80 })).toBe('80% budżetu');
    });
  });

  describe('generate_content step + waiting run keys (R2 sub-stage 5) exist in both catalogs', () => {
    // The step editor's copy (template picker, slot markers, the COMPOSITE refusal, the
    // template-DRIFT warning, the scale note and the honest outputs note) plus the `waiting`
    // run surfaces and the new session step-result card must be present + non-empty in BOTH
    // locales — a targeted guard on top of the whole-catalog parity above.
    const NEW_KEYS = [
      'templateSelect.placeholder',
      'templateSelect.search',
      'templateSelect.ariaLabel',
      'workflows.step.generate_content.label',
      'workflows.step.generate_content.description',
      'workflows.step.generate_content.maxSteps',
      'workflows.step.generate_content.templateLabel',
      'workflows.step.generate_content.templateHint',
      'workflows.step.generate_content.templatePlaceholder',
      'workflows.step.generate_content.templateLoading',
      'workflows.step.generate_content.templateLoadError',
      'workflows.step.generate_content.templateRetry',
      'workflows.step.generate_content.slotsTitle',
      'workflows.step.generate_content.slotsHint',
      'workflows.step.generate_content.noSlots',
      'workflows.step.generate_content.requiredMarker',
      'workflows.step.generate_content.optionalMarker',
      'workflows.step.generate_content.missingRequired',
      'workflows.step.generate_content.addItem',
      'workflows.step.generate_content.removeItem',
      'workflows.step.generate_content.composite.placeholder',
      'workflows.step.generate_content.composite.requiredNote',
      'workflows.step.generate_content.composite.optionalNote',
      'workflows.step.generate_content.composite.mappedNote',
      'workflows.step.generate_content.composite.unmap',
      'workflows.step.generate_content.composite.requiredWarningTitle',
      'workflows.step.generate_content.composite.requiredWarning',
      'workflows.step.generate_content.drift.title',
      'workflows.step.generate_content.drift.removed',
      'workflows.step.generate_content.drift.added',
      'workflows.step.generate_content.drift.removeUnknown',
      // The AUTHOR (bot) sub-field: its own "this bot is gone" verdict. NOT the ai-text panel's
      // `editor.aiText.authorMissing`, which promises a neutral-tone fallback that does not exist
      // here — a workflow step with a dead author is refused at save and fails at run.
      'workflows.step.generate_content.botMissing',
      'workflows.step.generate_content.nameLabel',
      'workflows.step.generate_content.nameHint',
      'workflows.step.generate_content.namePlaceholder',
      'workflows.step.generate_content.folderLabel',
      'workflows.step.generate_content.folderHint',
      'workflows.step.generate_content.folderRoot',
      'workflows.step.generate_content.folderChosen',
      'workflows.step.generate_content.scale.post',
      'workflows.step.generate_content.scale.post_with_image',
      'workflows.step.generate_content.scale.video_script',
      'workflows.step.generate_content.scale.other',
      'workflows.step.generate_content.outputs.title',
      'workflows.step.generate_content.outputs.hint',
      'workflows.step.generate_content.outputs.statusNote',
      'workflows.step.generate_content.summary',
      'workflows.step.summary.generateContentFallback',
      'workflows.step.summary.generateContentInputs',
      'workflows.runs.state.waiting',
      'workflows.runs.detail.waiting.title',
      'workflows.runs.detail.waiting.body',
      'workflows.runs.detail.waiting.elapsed',
      'workflows.runs.detail.waiting.refresh',
      'workflows.runs.detail.waiting.noCancel',
      'workflows.runs.detail.stepResult.sessionTitle',
      'workflows.runs.detail.stepResult.openSession',
    ];

    it.each(NEW_KEYS)('%s resolves to a non-empty string in en and pl', (key) => {
      setLocale('en');
      const enValue = translate(key);
      expect(enValue, `en.${key} missing`).not.toBe(key);
      expect(enValue.length).toBeGreaterThan(0);
      setLocale('pl');
      const plValue = translate(key);
      expect(plValue, `pl.${key} missing`).not.toBe(key);
      expect(plValue.length).toBeGreaterThan(0);
    });

    it('the outputs hint substitutes the step KEY into every reference in both locales', () => {
      for (const locale of ['en', 'pl'] as const) {
        setLocale(locale);
        const hint = translate('workflows.step.generate_content.outputs.hint', undefined, { key: 'content' });
        expect(hint, `${locale} outputs.hint`).toContain('{{steps.content.content}}');
        expect(hint, `${locale} outputs.hint`).toContain('{{steps.content.image_file_ids}}');
        expect(hint, `${locale} outputs.hint`).not.toContain('{key}');
      }
    });

    it('the status note stays HONEST about `status` always being "ready" in both locales', () => {
      setLocale('en');
      expect(translate('workflows.step.generate_content.outputs.statusNote')).toContain('ready');
      setLocale('pl');
      expect(translate('workflows.step.generate_content.outputs.statusNote')).toContain('ready');
    });

    it('the video_script scale note names the image ceiling in both locales', () => {
      for (const locale of ['en', 'pl'] as const) {
        setLocale(locale);
        expect(
          translate('workflows.step.generate_content.scale.video_script', undefined, { max: 8 }),
          `${locale} scale.video_script`,
        ).toContain('8');
      }
    });
  });

  describe('Calendar keys (R3 B6/B7) exist in both catalogs', () => {
    // A targeted guard on top of the whole-catalog parity above, so a half-added key set
    // fails loudly HERE with the missing path named.
    const NEW_KEYS = [
      'nav.calendar',
      'calendar.title',
      'calendar.subtitle',
      'calendar.mode.label',
      'calendar.mode.grid',
      'calendar.mode.agenda',
      'calendar.nav.prevMonth',
      'calendar.nav.nextMonth',
      'calendar.nav.today',
      'calendar.nav.todayDisabled',
      'calendar.timezone.chip',
      'calendar.timezone.mismatch',
      'calendar.timezone.fieldHint',
      'calendar.day.more',
      'calendar.day.showAll',
      'calendar.day.count',
      'calendar.day.empty',
      'calendar.day.createHere',
      'calendar.occurrence.allDay',
      'calendar.occurrence.from',
      'calendar.occurrence.range',
      'calendar.occurrence.when',
      'calendar.occurrence.source',
      'calendar.occurrence.timezone',
      'calendar.occurrence.open',
      'calendar.occurrence.openList',
      'calendar.occurrence.editable',
      'calendar.occurrence.external',
      'calendar.dense.chip',
      'calendar.dense.aria',
      'calendar.filters.searchPlaceholder',
      'calendar.filters.sources',
      'calendar.filters.clearAll',
      'calendar.filters.chip.source',
      'calendar.filters.chip.search',
      'calendar.results.count',
      'calendar.results.none',
      'calendar.legend.sources',
      'calendar.legend.colorNote',
      'calendar.legend.unavailable',
      // Two wordings, because `unavailable_sources[].reason` says whether a retry is worth
      // offering — one sentence could only have been vague enough to cover both.
      'calendar.unavailable.retryable',
      'calendar.unavailable.permanent',
      'calendar.unavailable.rest',
      'calendar.unavailable.retry',
      'calendar.empty.title',
      'calendar.empty.description',
      'calendar.empty.action',
      'calendar.empty.filtered.title',
      'calendar.empty.filtered.description',
      'calendar.empty.filtered.action',
      'calendar.error.title',
      'calendar.error.description',
      'calendar.error.retry',
      'calendar.loading',
      'calendar.event.new',
      'calendar.event.edit',
      'calendar.event.view',
      'calendar.event.save',
      'calendar.event.cancel',
      'calendar.event.delete',
      'calendar.event.saved',
      'calendar.event.deleted',
      'calendar.event.saveError',
      'calendar.event.deleteError',
      'calendar.event.loadError',
      'calendar.event.noPermission',
      'calendar.event.field.title',
      'calendar.event.field.allDay',
      'calendar.event.field.start',
      'calendar.event.field.end',
      'calendar.event.field.description',
      'calendar.event.validation.titleRequired',
      'calendar.event.validation.startDateRequired',
      'calendar.event.validation.startsAtRequired',
      'calendar.event.validation.endBeforeStart',
      'calendar.event.deleteConfirm.title',
      'calendar.event.deleteConfirm.message',
      // The `create_event` workflow step (B7) + its read surfaces.
      'workflows.step.create_event.label',
      'workflows.step.create_event.description',
      'workflows.step.create_event.titleLabel',
      'workflows.step.create_event.descriptionLabel',
      'workflows.step.create_event.allDay',
      'workflows.step.create_event.allDayHint',
      'workflows.step.create_event.allDayToggle',
      'workflows.step.create_event.startDate',
      'workflows.step.create_event.startsAt',
      'workflows.step.create_event.endsAt',
      'workflows.step.create_event.outputs.title',
      'workflows.step.create_event.outputs.hint',
      'workflows.step.summary.createEventFallback',
      'workflows.runs.detail.stepResult.untitledEvent',
      'workflows.runs.detail.stepResult.openEvent',
    ];

    it.each(NEW_KEYS)('%s resolves to a non-empty string in en and pl', (key) => {
      setLocale('en');
      const enValue = translate(key);
      expect(enValue, `en.${key} missing`).not.toBe(key);
      expect(enValue.length).toBeGreaterThan(0);
      setLocale('pl');
      const plValue = translate(key);
      expect(plValue, `pl.${key} missing`).not.toBe(key);
      expect(plValue.length).toBeGreaterThan(0);
    });

    /**
     * NO COLOUR VOCABULARY IS LEFT, AND THE ABSENCE IS THE CONTRACT.
     *
     * Colour on the calendar is a DICTIONARY OF MEANINGS: a task deadline is coloured by its
     * priority, a workflow run by its result, a schedule by the one colour that says "this
     * is a projection, not a fact". Two surfaces let a human pick from the same six values
     * with the pick meaning nothing — the event drawer and the `create_event` workflow step
     * — so in one grid red said "urgent", "failed", and nothing. Both pickers are gone; an
     * event's colour is a server-assigned constant.
     *
     * This is asserted rather than merely deleted because a colour NAME reappearing in a
     * catalog is the cheap half of a picker coming back, and it would come back reading as
     * a tidy little addition to a translation file.
     */
    it('defines no colour NAMES and no colour FIELD label — both pickers were removed', () => {
      const gone = [
        'calendar.event.field.color',
        'workflows.step.create_event.color',
        'workflows.step.create_event.colorHint',
        ...['neutral', 'primary', 'success', 'warning', 'danger', 'info'].map(
          (c) => `calendar.event.color.${c}`,
        ),
      ];
      for (const key of gone) {
        for (const locale of ['en', 'pl'] as const) {
          setLocale(locale);
          // `translate` echoes the key back when nothing resolves.
          expect(translate(key), `${locale} still defines ${key}`).toBe(key);
        }
      }
    });

    it('the loss vocabulary has SIX sentences — a known and an unknown count per kind', () => {
      // An unknown count is a different STATEMENT, not a missing number. If any `unknown`
      // variant went missing, the component would fall back to printing the key.
      for (const kind of ['windowTrimmed', 'itemsDropped', 'itemDensified']) {
        for (const locale of ['en', 'pl'] as const) {
          setLocale(locale);
          const withCount = translate(`calendar.truncation.${kind}.withCount`);
          const unknown = translate(`calendar.truncation.${kind}.unknown`);
          expect(withCount, `${locale} ${kind}.withCount`).toContain('{n}');
          // The unknown variant must NOT carry a number token at all — an interpolation
          // that never receives a value is how "0" gets on screen.
          expect(unknown, `${locale} ${kind}.unknown`).not.toContain('{n}');
          expect(unknown).not.toBe(withCount);
        }
      }
    });

    it('the densified sentence keeps the "empty days" warning in both locales', () => {
      // Without it, the cliff a folded series leaves on the grid reads as "it stopped".
      setLocale('en');
      expect(translate('calendar.truncation.itemDensified.unknown')).toMatch(/empty days/i);
      setLocale('pl');
      expect(translate('calendar.truncation.itemDensified.unknown')).toMatch(/puste dni/i);
    });

    it('never promises that a deleted event can be restored (there is no restore endpoint)', () => {
      for (const locale of ['en', 'pl'] as const) {
        setLocale(locale);
        const message = translate('calendar.event.deleteConfirm.message', undefined, { name: 'X' });
        expect(message).not.toMatch(/undo|restore|cofn|przywr/i);
      }
    });
  });

  describe('boolean type is always named Condition / Warunek (§refinement 2)', () => {
    const get = (cat: Record<string, unknown>, path: string): string =>
      path
        .split('.')
        .reduce<unknown>((acc, k) => (acc as Record<string, unknown>)?.[k], cat) as string;

    it('the boolean TYPE label is Condition (en) / Warunek (pl) everywhere it is named', () => {
      expect(get(en, 'editor.types.boolean')).toBe('Condition');
      expect(get(pl, 'editor.types.boolean')).toBe('Warunek');
      expect(get(en, 'variables.consts.base.boolean')).toBe('Condition');
      expect(get(pl, 'variables.consts.base.boolean')).toBe('Warunek');
    });

    it('no user-facing "yes/no" / "tak/nie" / "boolean"-as-type copy remains in the condition strings', () => {
      const keys = [
        'editor.ifBlock.conditionInvalid',
        'editor.ifCondition.mustBeBoolean',
        'editor.ifCondition.valid',
        'editor.ifCondition.invalid',
        'workflows.condition.validation.treeIncomplete',
        'workflows.condition.modal.mustBeBoolean',
        'workflows.condition.modal.notReady',
      ];
      for (const key of keys) {
        expect(get(en, key), `en.${key}`).not.toMatch(/yes\/no|boolean/i);
        expect(get(pl, key), `pl.${key}`).not.toMatch(/tak\/nie|boolean/i);
      }
    });

    it('the corrected strings name the Condition / Warunek type', () => {
      expect(get(en, 'workflows.condition.modal.notReady')).toBe(
        'Keep going until the check returns a Condition result.',
      );
      expect(get(pl, 'workflows.condition.modal.notReady')).toBe(
        'Kontynuuj, aż sprawdzenie zwróci wynik typu Warunek.',
      );
      expect(get(en, 'editor.ifCondition.valid')).toBe('The condition returns a Condition.');
      expect(get(pl, 'editor.ifCondition.valid')).toBe('Warunek zwraca wynik typu Warunek.');
    });
  });
});
