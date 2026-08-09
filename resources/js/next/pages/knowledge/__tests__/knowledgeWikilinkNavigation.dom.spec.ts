// @vitest-environment happy-dom
// knowledgeWikilinkNavigation.dom.spec — B7: a `[[wikilink]]` CLICK, all the way to `router.push`.
//
// Every piece of this path is already tested in isolation and the path itself was not:
//   • `MarkdownViewer` builds the anchors (markdownViewerWikilinks.dom.spec);
//   • `wikilinkAnchors.ts` normalizes slugs (wikilinkAnchors.spec);
//   • `KnowledgeReaderView`'s `openEntry` pushes a route — asserted nowhere, because the one spec
//     that mounts the reader STUBS the article body out.
//
// The seam between them is exactly where a wiki breaks, and it breaks silently: the anchors come out
// of `v-html`, so they cannot carry Vue listeners and are served instead by ONE delegated listener on
// a container. Rename the marker attribute, move the listener onto the wrong element, or let the
// component stop passing `entriesBySlug` down, and every link in every article becomes either dead or
// a full page load — with no error, no failing unit test, and no visual difference until it is
// clicked.
//
// So this spec mounts the reader with the REAL article body and the REAL markdown renderer, and
// drives actual DOM clicks on anchors that were produced by the pipeline rather than written by hand.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const h = vi.hoisted(() => {
  const listItem = (id: string, slug: string, title: string) => ({
    id,
    knowledge_base_id: 'b1',
    title,
    slug,
    excerpt: 'Fragment.',
    metadata: {},
    status: 'approved',
    stale_at: null,
    is_stale: false,
    position: 0,
    current_revision_id: 'r1',
    index: { status: 'indexed', chunks_count: 1, indexed_chunks_count: 1, needs_indexing: false, can_retry: false },
    creator: null,
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    can_be_purged: true,
    created_at: null,
    updated_at: null,
    deleted_at: null,
  });

  const target = listItem('e2', 'polityka-rabatow', 'Polityka rabatów');

  const entry = {
    ...listItem('e1', 'cennik', 'Cennik'),
    // One resolved link and one ghost, in the same paragraph — the two branches of the handler.
    content: 'Szczegóły opisuje [[polityka-rabatow]] oraz [[jeszcze-nie-istnieje]].',
    links: [],
    backlinks: [],
    similar: [],
  };

  return {
    entry,
    target,
    router: { push: vi.fn(), replace: vi.fn() },
    toast: { success: vi.fn(), danger: vi.fn(), info: vi.fn(), warning: vi.fn() },
    route: { params: { baseId: 'b1', slug: 'cennik' }, query: {} as Record<string, string>, hash: '' },
    store: {
      entry,
      entries: [entry, target],
      entriesBySlug: new Map([
        [entry.slug, entry],
        [target.slug, target],
      ]),
      entriesLoading: false,
      entriesTruncated: false,
      entryLoading: false,
      entryError: null,
      openBase: { id: 'b1', name: 'Marka', can_be_edited: true, metadata_schema: [] },
      fetchEntries: vi.fn(),
      fetchEntry: vi.fn(),
      resetEntries: vi.fn(),
      resetEntry: vi.fn(),
      // Typed relations are fetched separately from the entry (they belong to TWO entries, so they
      // were never part of one entry's payload). The reader asks on mount, so the stub needs them.
      relations: [],
      relationsError: null,
      fetchRelations: vi.fn(),
      resetRelations: vi.fn(),
      retryIndex: vi.fn(),
      updateEntry: vi.fn(),
      dismissLink: vi.fn(),
      undismissLink: vi.fn(),
      deleteEntry: vi.fn(),
      reorderEntries: vi.fn(),
    },
  };
});

vi.mock('vue-router', () => ({
  useRouter: () => h.router,
  useRoute: () => h.route,
}));
vi.mock('../../../app/stores/knowledge', () => ({
  useKnowledgeStore: () => h.store,
  conflictCodeOf: () => null,
}));
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => h.toast }));
vi.mock('../../../app/composables/useConfirm', () => ({ useConfirm: () => vi.fn() }));

import KnowledgeReaderView from '../KnowledgeReaderView.vue';

/**
 * Everything EXCEPT the article body, which is the component under test here. The rail and the table
 * of contents draw their own links and would make "which anchor was clicked" ambiguous.
 */
function readerStubs() {
  return {
    KnowledgeTocPanel: true,
    KnowledgeEntryRail: true,
    KnowledgeVersionsDrawer: true,
  };
}

async function mountReader() {
  const wrapper = mount(KnowledgeReaderView, {
    attachTo: document.body,
    global: { stubs: readerStubs() },
  });
  await nextTick();
  await nextTick();

  return wrapper;
}

/** The anchor the markdown pipeline actually produced for a slug. */
function anchorFor(wrapper: ReturnType<typeof mount>, slug: string): HTMLAnchorElement {
  const el = wrapper.element.querySelector<HTMLAnchorElement>(`a[data-wikilink="${slug}"]`);
  expect(el, `no anchor was rendered for [[${slug}]]`).toBeTruthy();

  return el as HTMLAnchorElement;
}

describe('a wikilink click navigates', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('pl');
    vi.clearAllMocks();
    h.route.query = {};
    h.store.entry = h.entry;
  });
  afterEach(() => {
    restoreBrowserMocks();
    document.body.innerHTML = '';
  });

  it('renders the two kinds of link the resolver distinguishes', async () => {
    const wrapper = await mountReader();

    // A resolved link gets a real href, so middle-click and "open in new tab" work like any link.
    const resolved = anchorFor(wrapper, 'polityka-rabatow');
    expect(resolved.getAttribute('href')).toBe('/next/knowledge/b1/reader/polityka-rabatow');
    expect(resolved.hasAttribute('data-ghost')).toBe(false);

    // A ghost has none — there is nothing to open yet.
    const ghost = anchorFor(wrapper, 'jeszcze-nie-istnieje');
    expect(ghost.hasAttribute('data-ghost')).toBe(true);
    expect(ghost.hasAttribute('href')).toBe(false);

    wrapper.unmount();
  });

  it('pushes the reader route for the target slug instead of following the href', async () => {
    const wrapper = await mountReader();

    const anchor = anchorFor(wrapper, 'polityka-rabatow');
    const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
    anchor.dispatchEvent(event);
    await nextTick();

    expect(event.defaultPrevented).toBe(true); // a full page load here would lose the whole SPA state
    expect(h.router.push).toHaveBeenCalledTimes(1);
    expect(h.router.push).toHaveBeenCalledWith({
      name: 'next.knowledge.base.reader',
      params: { baseId: 'b1', slug: 'polityka-rabatow' },
      query: {},
    });

    wrapper.unmount();
  });

  it('carries the current query across the hop', async () => {
    // Filters and saved views live in the query string; dropping them on every internal link would
    // silently reset the reader's context each time somebody followed a link.
    h.route.query = { status: 'approved' };

    const wrapper = await mountReader();

    anchorFor(wrapper, 'polityka-rabatow').dispatchEvent(
      new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }),
    );
    await nextTick();

    expect(h.router.push).toHaveBeenCalledWith(
      expect.objectContaining({ query: { status: 'approved' } }),
    );

    wrapper.unmount();
  });

  it('sends a RED link to the COMPOSER, seeded with the slug', async () => {
    const wrapper = await mountReader();

    anchorFor(wrapper, 'jeszcze-nie-istnieje').dispatchEvent(
      new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }),
    );
    await nextTick();

    // Entries are not written by hand any more (spec §25.4): the invitation leads to the composer,
    // carrying the slug that was linked to so the user does not have to retype it.
    expect(h.router.push).toHaveBeenCalledWith({
      name: 'next.knowledge.base.compose',
      params: { baseId: 'b1' },
      query: { seed: 'jeszcze-nie-istnieje' },
    });

    wrapper.unmount();
  });

  it('activates a red link from the keyboard, which has no default action to fall back on', async () => {
    const wrapper = await mountReader();

    const ghost = anchorFor(wrapper, 'jeszcze-nie-istnieje');
    // A ghost carries no href, so Enter does nothing unless the handler takes it. A keyboard user
    // who could not follow red links would be unable to create entries the way everyone else does.
    ghost.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
    await nextTick();

    expect(h.router.push).toHaveBeenCalledWith(
      expect.objectContaining({ name: 'next.knowledge.base.compose' }),
    );

    wrapper.unmount();
  });

  it('leaves a MODIFIED click to the browser', async () => {
    const wrapper = await mountReader();
    const anchor = anchorFor(wrapper, 'polityka-rabatow');

    for (const modifier of ['metaKey', 'ctrlKey', 'shiftKey'] as const) {
      const event = new MouseEvent('click', {
        bubbles: true,
        cancelable: true,
        button: 0,
        [modifier]: true,
      });
      anchor.dispatchEvent(event);
      await nextTick();

      // Ctrl/Cmd-click means "new tab" everywhere else on the web, and the anchors carry real hrefs
      // precisely so it can keep meaning that here.
      expect(event.defaultPrevented, `${modifier}-click must not be intercepted`).toBe(false);
    }

    expect(h.router.push).not.toHaveBeenCalled();

    wrapper.unmount();
  });

  it('ignores a click on ordinary prose', async () => {
    const wrapper = await mountReader();

    wrapper.element
      .querySelector('p')
      ?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
    await nextTick();

    expect(h.router.push).not.toHaveBeenCalled();

    wrapper.unmount();
  });
});
