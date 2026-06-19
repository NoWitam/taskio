// labelIcon.spec.ts — the backend IconEnum → next Icon mapping (aliases, exact
// match, and the generic fallback for null/unmapped values).
import { describe, it, expect } from 'vitest';
import { resolveLabelIcon, LABEL_FALLBACK_ICON } from '../labelIcon';
import { ICONS } from '../../primitives/icons';

describe('resolveLabelIcon', () => {
  it('falls back to the generic tag icon for null / undefined / empty', () => {
    expect(resolveLabelIcon(null)).toBe(LABEL_FALLBACK_ICON);
    expect(resolveLabelIcon(undefined)).toBe(LABEL_FALLBACK_ICON);
    expect(resolveLabelIcon('')).toBe(LABEL_FALLBACK_ICON);
  });

  it('maps known aliases onto a valid next icon', () => {
    expect(resolveLabelIcon('label')).toBe('tag');
    expect(resolveLabelIcon('info-circle')).toBe('info');
    expect(resolveLabelIcon('list-check')).toBe('list-checks');
  });

  it('passes through values that are already valid next icon names', () => {
    expect(resolveLabelIcon('flag')).toBe('flag');
    expect(resolveLabelIcon('search')).toBe('search');
    expect(resolveLabelIcon('file-text')).toBe('file-text');
  });

  it('falls back for backend values with no next counterpart', () => {
    expect(resolveLabelIcon('webhook')).toBe(LABEL_FALLBACK_ICON);
    expect(resolveLabelIcon('not-a-real-icon')).toBe(LABEL_FALLBACK_ICON);
  });

  it('always resolves to a renderable icon (present in the registry)', () => {
    for (const v of ['label', 'flag', 'webhook', null, 'circle-help']) {
      expect(ICONS[resolveLabelIcon(v)]).toBeDefined();
    }
  });
});
