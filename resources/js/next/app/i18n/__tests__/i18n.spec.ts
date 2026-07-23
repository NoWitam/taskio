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

  describe('boolean type is always named Condition / Warunek (§refinement 2)', () => {
    const get = (cat: Record<string, unknown>, path: string): string =>
      path
        .split('.')
        .reduce<unknown>((acc, k) => (acc as Record<string, unknown>)?.[k], cat) as string;

    it('the boolean TYPE label is Condition (en) / Warunek (pl) everywhere it is named', () => {
      expect(get(en, 'editor.types.boolean')).toBe('Condition');
      expect(get(pl, 'editor.types.boolean')).toBe('Warunek');
      expect(get(en, 'workflows.globals.base.boolean')).toBe('Condition');
      expect(get(pl, 'workflows.globals.base.boolean')).toBe('Warunek');
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
