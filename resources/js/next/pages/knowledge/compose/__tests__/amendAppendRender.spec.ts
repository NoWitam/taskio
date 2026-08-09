// @vitest-environment happy-dom
// amendAppendRender.spec — the card must show the RESULT, never the stored column.
//
// THE REGRESSION THIS PINS, stated plainly so nobody "simplifies" it back:
//
// An APPEND shadow stores its addition ALONE in `content`. That is not an accident of the schema —
// it is what keeps the operation commutative with somebody else's concurrent edit, which is why an
// append carries no optimistic lock. The cost is that `content` is NOT what the entry will say.
//
// A card rendering `draft.content` therefore tells the reviewer: "the whole document becomes this
// one sentence." That is not an incomplete picture, it is a confident statement of the WRONG
// outcome, on the one surface whose entire job is to say what accepting will do. Worse than no
// preview at all: an absent diff makes a reviewer cautious, a lying one makes them sure.
//
// `amended_body` is the composed result and is what both the card and the diff render.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import KnowledgeDraftCard from '../KnowledgeDraftCard.vue';
import { setLocale, translate as t } from '../../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../../__tests__/helpers/dom';
import type { KnowledgeDraftEntry } from '../../types';

const CURRENT = '# Cennik\n\nPodstawowa stawka wynosi 100 zł.\n\n## Kalendarium\n\n- 2023: start';
const ADDITION = '- 2024: podwyżka do 120 zł';
const COMPOSED = `${CURRENT}\n${ADDITION}`;

function draft(over: Partial<KnowledgeDraftEntry> = {}): KnowledgeDraftEntry {
  return {
    id: 'd1',
    knowledge_base_id: 'b1',
    title: 'Cennik',
    slug: '__shadow-1',
    aliases: [],
    entry_type: null,
    content: ADDITION,
    metadata: {},
    status: 'draft',
    stale_at: null,
    is_stale: false,
    position: 0,
    current_revision_id: null,
    index: { status: 'pending', chunks_count: null, needs_indexing: true },
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    can_be_purged: true,
    created_at: null,
    updated_at: null,
    deleted_at: null,
    draft_session_id: 's1',
    targets_entry: { id: 'e1', title: 'Cennik', slug: 'cennik', current_revision_id: 'r1' },
    target_revision_id: 'r1',
    target_revision_stale: false,
    amend_mode: 'append',
    amend_section: 'Kalendarium',
    amended_body: COMPOSED,
    ...over,
  } as KnowledgeDraftEntry;
}

function mountCard(over: Partial<KnowledgeDraftEntry> = {}, props: Record<string, unknown> = {}) {
  return mount(KnowledgeDraftCard, {
    attachTo: document.body,
    props: { draft: draft(over), base: null, ...props },
  });
}

describe('an APPEND amendment card', () => {
  beforeEach(() => {
    setLocale('pl');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('renders the RESULT, not the addition alone', () => {
    const wrapper = mountCard();
    const text = wrapper.text();

    // THE assertion. A card built on `draft.content` shows only the new line and passes nothing
    // here — which is exactly the naive implementation this test exists to fail.
    expect(text).toContain('Podstawowa stawka wynosi 100 zł.');
    // Without the markdown bullet: MarkdownViewer renders `- x` as a list item and the dash is
    // structure, not text.
    expect(text).toContain('2024: podwyżka do 120 zł');
    wrapper.unmount();
  });

  it('names the SECTION the addition joins', () => {
    // The composed body is correct and the attribution is missing without this: the reviewer sees
    // the whole document and cannot tell which part is the proposal.
    const wrapper = mountCard();

    expect(wrapper.find('[data-amend-mode]').text()).toContain('Kalendarium');
    wrapper.unmount();
  });

  it('says "at the end" when no section was named', () => {
    const wrapper = mountCard({ amend_section: null });

    expect(wrapper.find('[data-amend-mode]').text()).toBe(t('knowledge.compose.amendAppendEnd'));
    wrapper.unmount();
  });
});

describe('a REWRITE amendment card is unchanged', () => {
  beforeEach(() => {
    setLocale('pl');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('renders the proposed body, with no append affordance', () => {
    // For a rewrite the server returns `amended_body === content`, so this is byte-identical to
    // what the card did before the append channel existed.
    const wrapper = mountCard({
      amend_mode: 'rewrite',
      amend_section: null,
      content: 'Zupełnie nowa treść.',
      amended_body: 'Zupełnie nowa treść.',
    });

    expect(wrapper.text()).toContain('Zupełnie nowa treść.');
    expect(wrapper.find('[data-amend-mode]').exists()).toBe(false);
    wrapper.unmount();
  });
});

describe('an ORDINARY new draft', () => {
  beforeEach(() => {
    setLocale('pl');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('renders `content`, because the field is serialized for shadows only', () => {
    // The `?? content` fallback is load-bearing rather than defensive: a plain draft has no
    // `amended_body` at all, and its `content` IS the result.
    const wrapper = mountCard({
      targets_entry: null,
      amend_mode: null,
      amend_section: null,
      amended_body: undefined,
      content: 'Treść nowego wpisu.',
    });

    expect(wrapper.text()).toContain('Treść nowego wpisu.');
    wrapper.unmount();
  });
});

describe("run notes on the card", () => {
  beforeEach(() => {
    setLocale("pl");
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = "";
    restoreBrowserMocks();
  });

  it("renders the note beside the proposal it describes", () => {
    // A note attached to the card it is about is one a reader connects; the same sentence at the
    // bottom of a long page is one they do not.
    const wrapper = mountCard({}, {
      notes: [
        {
          code: "amend_append_only",
          title: "Dopisano zamiast przepisać",
          hint: "Wpis był za długi.",
          icon: "info",
          existingRelationId: null,
        },
      ],
    });
    const note = wrapper.find("[data-note=\"amend_append_only\"]");

    expect(note.exists()).toBe(true);
    expect(note.text()).toContain("Dopisano zamiast przepisać");
    expect(note.text()).toContain("Wpis był za długi.");
    wrapper.unmount();
  });

  it("shows nothing when the run had nothing to say about this draft", () => {
    expect(mountCard().find("[data-note]").exists()).toBe(false);
  });
});
