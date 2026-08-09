// @vitest-environment happy-dom
// KnowledgeSearchResultCard.spec — the heading trail, rendered.
//
// `headingPathParts` is unit-tested next door; this spec pins the TEMPLATE, because the defect it
// guards against was a rendering one: `heading_path` is a joined STRING, and a `v-for` over a
// string walks it character by character. "Cennik > Zwroty" drew fifteen crumbs — `C › e › n › …` —
// and the `.length` guard let it through, since a string has a length.
//
// So the assertion is on the number of rendered CRUMBS, not on the helper's return value: only the
// template can regress this way, and only the template can prove it does not.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { setLocale, translate } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import KnowledgeSearchResultCard from '../search/KnowledgeSearchResultCard.vue';
import type { KnowledgeSearchResult } from '../types';

const t = translate;

function result(headingPath: string | null, overrides: Partial<KnowledgeSearchResult> = {}) {
  return {
    id: 'e1',
    knowledge_base_id: 'b1',
    title: 'Polityka zwrotów',
    slug: 'polityka-zwrotow',
    excerpt: 'Zwroty przyjmujemy w 14 dni.',
    metadata: {},
    status: 'approved',
    stale_at: null,
    is_stale: false,
    position: 0,
    current_revision_id: null,
    index: {
      status: 'indexed',
      chunks_count: 3,
      indexed_chunks_count: 3,
      needs_indexing: false,
      can_retry: false,
    },
    creator: null,
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    can_be_purged: true,
    created_at: null,
    updated_at: null,
    deleted_at: null,
    base: { id: 'b1', name: 'Marka' },
    matched_chunk: {
      ordinal: 2,
      heading_path: headingPath,
      score: 0.87,
      snippet: 'Zwroty przyjmujemy w 14 dni.',
      char_start: 0,
      char_length: 28,
      highlights: [[0, 6]] as Array<[number, number]>,
      truncated_before: false,
      truncated_after: false,
    },
    matched_chunks_count: 1,
    rrf_score: 0.5,
    ...overrides,
  } as KnowledgeSearchResult;
}

/** The heading-trail row, found by the accessible name it carries. */
function trail(wrapper: ReturnType<typeof mount>) {
  return wrapper.find(`[aria-label="${t('knowledge.search.headingPath')}"]`);
}

/**
 * The crumbs, by their own marker rather than by "every span that is not a separator" — the base
 * chip is a Badge, and a Badge has spans of its own.
 */
function crumbs(wrapper: ReturnType<typeof mount>): string[] {
  const row = trail(wrapper);
  return row.exists() ? row.findAll('[data-heading-crumb]').map((s) => s.text()) : [];
}

describe('KnowledgeSearchResultCard — the heading trail', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('pl');
    vi.clearAllMocks();
  });
  afterEach(() => {
    restoreBrowserMocks();
    document.body.innerHTML = '';
  });

  function mountCard(headingPath: string | null, props: Record<string, unknown> = {}) {
    return mount(KnowledgeSearchResultCard, {
      attachTo: document.body,
      props: { result: result(headingPath), showBase: false, ...props },
    });
  }

  it('renders a joined trail as SEGMENTS — two crumbs, not fifteen characters', () => {
    const wrapper = mountCard('Cennik > Zwroty');

    expect(crumbs(wrapper)).toEqual(['Cennik', 'Zwroty']);
    // The regression this exists for: one crumb per character.
    expect(crumbs(wrapper)).toHaveLength(2);
    expect(trail(wrapper).text()).not.toContain('C › e');
    wrapper.unmount();
  });

  it('renders a single-segment trail as one crumb', () => {
    const wrapper = mountCard('Cennik');

    expect(crumbs(wrapper)).toEqual(['Cennik']);
    wrapper.unmount();
  });

  it('draws no trail at all for a keyword-only hit (null path, no base chip)', () => {
    const wrapper = mountCard(null);

    expect(trail(wrapper).exists()).toBe(false);
    wrapper.unmount();
  });

  it('still shows the base chip when there is no trail', () => {
    const wrapper = mountCard(null, { showBase: true });

    expect(trail(wrapper).exists()).toBe(true);
    expect(trail(wrapper).text()).toContain('Marka');
    wrapper.unmount();
  });

  it('separates the base chip from the first crumb, and the crumbs from each other', () => {
    const wrapper = mountCard('Cennik > Zwroty', { showBase: true });
    const row = trail(wrapper);

    // One separator before the first crumb (after the base) + one between the two crumbs.
    const separators = row
      .findAll('span')
      .filter((s) => s.attributes('aria-hidden') !== undefined && s.text() === '›');
    expect(separators).toHaveLength(2);
    expect(crumbs(wrapper)).toEqual(['Cennik', 'Zwroty']);
    wrapper.unmount();
  });
});
