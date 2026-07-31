// uiLayerImportBoundary.spec — the `ui/** → pages/**` import ban, enforced instead of documented.
//
// `resources/js/next/ui/**` is the design system: it is the LOWER layer, consumed by every page. A
// page may import down into `ui/`; `ui/` must never import up into `pages/`, or the design system
// stops being independently reusable and a bundle edge appears between two layers that are supposed
// to be one-directional.
//
// The ban was previously honored only by comment + local duplication (`ui/forms/TemplateSelect.vue`
// keeps a local copy of a content-type icon map, `ui/variables/types.ts` re-declares a union) while
// a real runtime import had slipped into `ui/forms/BotSelect.vue`. Type-only imports erase at build
// time, but they encode the same wrong direction, so this pins BOTH: any `from '…pages/…'` in `ui/`
// fails, and the cure is to move the shared thing DOWN into `ui/`.
import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';

const UI_ROOT = resolve(__dirname, '../ui');

/** Every `.ts` / `.vue` file under `ui/`, tests included. */
function sourceFiles(dir: string): string[] {
  const out: string[] = [];
  for (const name of readdirSync(dir)) {
    const full = join(dir, name);
    if (statSync(full).isDirectory()) {
      out.push(...sourceFiles(full));
      continue;
    }
    if (name.endsWith('.ts') || name.endsWith('.vue')) out.push(full);
  }
  return out;
}

/**
 * The module specifiers a file imports: `from '…'` (static import, re-export) plus dynamic
 * `import('…')`. Comments are not stripped — a prose mention of the rule has no `from '…'` shape, so
 * it cannot false-positive.
 */
function specifiers(source: string): Array<{ line: number; spec: string }> {
  const found: Array<{ line: number; spec: string }> = [];
  const patterns = [/\bfrom\s*['"]([^'"]+)['"]/g, /\bimport\s*\(\s*['"]([^'"]+)['"]/g];
  for (const pattern of patterns) {
    for (const match of source.matchAll(pattern)) {
      const line = source.slice(0, match.index ?? 0).split('\n').length;
      found.push({ line, spec: match[1] });
    }
  }
  return found;
}

describe('next design-system layer boundaries', () => {
  it('no file under ui/ imports from pages/ (not even type-only)', () => {
    const offenders: string[] = [];
    for (const file of sourceFiles(UI_ROOT)) {
      const source = readFileSync(file, 'utf8');
      for (const { line, spec } of specifiers(source)) {
        if (/(^|\/)pages\//.test(spec)) {
          offenders.push(`${file.slice(file.indexOf('resources'))}:${line} → ${spec}`);
        }
      }
    }

    expect(offenders).toEqual([]);
  });

  it('no file under ui/ imports the frozen legacy frontend', () => {
    const offenders: string[] = [];
    for (const file of sourceFiles(UI_ROOT)) {
      const source = readFileSync(file, 'utf8');
      for (const { line, spec } of specifiers(source)) {
        // `@/…` is the legacy alias; a relative escape out of `next/` is the other way in.
        if (spec.startsWith('@/') || /(^|\/)js\/(?!next\/)/.test(spec)) {
          offenders.push(`${file.slice(file.indexOf('resources'))}:${line} → ${spec}`);
        }
      }
    }

    expect(offenders).toEqual([]);
  });
});
