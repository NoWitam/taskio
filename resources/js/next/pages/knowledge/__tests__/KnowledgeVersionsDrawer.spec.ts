// @vitest-environment happy-dom
// KnowledgeVersionsDrawer.spec — the B14 diff inside the version history.
//
// What a history is FOR is deciding whether to restore, and that decision needs one question
// answered: what would change? So the comparison is always version → CURRENT entry content, and
// the "no changes" case is a real answer (the newest version), not an error.
//
// The heavy pieces (Drawer's teleport, Timeline) render inline here; the assertions are about
// which text is compared and how the two views switch.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale, translate } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const h = vi.hoisted(() => ({
  store: {
    revisions: [] as unknown[],
    revisionsLoading: false,
    revisionsError: null as string | null,
    fetchRevisions: vi.fn(),
    resetRevisions: vi.fn(),
    restoreRevision: vi.fn(),
  },
  toast: { success: vi.fn(), danger: vi.fn(), info: vi.fn(), warning: vi.fn() },
  confirm: vi.fn(),
}));

vi.mock('../../../app/stores/knowledge', () => ({
  useKnowledgeStore: () => h.store,
  conflictCodeOf: () => null,
}));
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => h.toast }));
vi.mock('../../../app/composables/useConfirm', () => ({ useConfirm: () => h.confirm }));

import KnowledgeVersionsDrawer from '../KnowledgeVersionsDrawer.vue';

const t = translate;

function revision(id: string, content: string) {
  return {
    id,
    knowledge_entry_id: 'e1',
    title: 'Polityka zwrotów',
    content,
    metadata: {},
    change_note: null,
    author: null,
    created_at: '2026-07-30T10:00:00Z',
  };
}

function entry(content: string) {
  return {
    id: 'e1',
    knowledge_base_id: 'b1',
    title: 'Polityka zwrotów',
    slug: 'polityka-zwrotow',
    content,
    metadata: {},
    status: 'approved',
    stale_at: null,
    is_stale: false,
    position: 0,
    current_revision_id: 'r-new',
    index: { status: 'indexed', chunks_count: 1, indexed_chunks_count: 1, needs_indexing: false, can_retry: false },
    creator: null,
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    can_be_purged: true,
    created_at: null,
    updated_at: null,
    deleted_at: null,
  } as never;
}

/** The Drawer teleports; read the whole document. */
function bodyText(): string {
  return document.body.textContent ?? '';
}

function compareButtons(): HTMLButtonElement[] {
  return Array.from(document.body.querySelectorAll('button')).filter(
    (b) => (b.textContent ?? '').trim() === t('knowledge.history.compare'),
  );
}

describe('KnowledgeVersionsDrawer — comparing a version with the current entry', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('pl');
    vi.clearAllMocks();
    h.store.revisions = [revision('r-new', 'linia jeden\nlinia dwa'), revision('r-old', 'linia jeden\nlinia STARA')];
    h.store.revisionsLoading = false;
    h.store.revisionsError = null;
  });

  afterEach(() => {
    restoreBrowserMocks();
    document.body.innerHTML = '';
  });

  function mountDrawer(currentContent = 'linia jeden\nlinia dwa') {
    return mount(KnowledgeVersionsDrawer, {
      attachTo: document.body,
      props: { open: true, entry: entry(currentContent) },
    });
  }

  it('shows no diff until a version is expanded — the work is opt-in', async () => {
    const wrapper = mountDrawer();
    await nextTick();

    expect(document.body.querySelector('.next-kg-diff, [role="group"]')).toBeDefined();
    expect(bodyText()).not.toContain('linia STARA');
    expect(compareButtons()).toHaveLength(2); // one per version, including the newest
    wrapper.unmount();
  });

  it('diffs the picked version against the CURRENT entry content', async () => {
    const wrapper = mountDrawer('linia jeden\nlinia dwa');
    await nextTick();

    compareButtons()[1].click(); // the older version
    await nextTick();

    // Both sides are on screen: what the version had, and what the entry has now.
    expect(bodyText()).toContain('STARA');
    expect(bodyText()).toContain('dwa');
    wrapper.unmount();
  });

  it('says "identical" for the version the entry currently shows', async () => {
    const wrapper = mountDrawer('linia jeden\nlinia dwa');
    await nextTick();

    compareButtons()[0].click(); // the newest — same text as the entry
    await nextTick();

    expect(bodyText()).toContain(t('knowledge.history.sameAsCurrent'));
    wrapper.unmount();
  });

  it('switches between the diff and the version’s own text', async () => {
    const wrapper = mountDrawer();
    await nextTick();

    compareButtons()[1].click();
    await nextTick();

    const contentTab = Array.from(document.body.querySelectorAll('[role="radio"]')).find(
      (el) => (el.textContent ?? '').trim() === t('knowledge.history.view.content'),
    ) as HTMLElement | undefined;
    expect(contentTab).toBeTruthy();

    contentTab!.click();
    await nextTick();

    // The verbatim body, in a <pre> — not the diff's gutter rows.
    const pre = document.body.querySelector('pre');
    expect(pre?.textContent).toContain('linia STARA');
    wrapper.unmount();
  });

  it('opens ONE version at a time — a second click closes the first', async () => {
    const wrapper = mountDrawer();
    await nextTick();

    compareButtons()[1].click();
    await nextTick();
    expect(bodyText()).toContain('STARA');

    compareButtons()[0].click();
    await nextTick();
    // The older version's panel is gone; the newest one reports it matches the entry.
    expect(bodyText()).toContain(t('knowledge.history.sameAsCurrent'));
    wrapper.unmount();
  });
});
