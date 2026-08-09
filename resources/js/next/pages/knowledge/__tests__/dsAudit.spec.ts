// @vitest-environment happy-dom
// dsAudit.spec — the design-system findings that are cheap to state and easy to regress.
//
// Each of these is a case where the module said something with COLOUR, an untranslated ENUM, or a
// hand-rolled copy of a primitive. None of them break a render, which is exactly why they need
// pinning: nothing else fails when they come back.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import KnowledgeSearchResultCard from '../search/KnowledgeSearchResultCard.vue';
import KnowledgeSimilarRow from '../reader/KnowledgeSimilarRow.vue';
import KnowledgeMentionRow from '../reader/KnowledgeMentionRow.vue';
import { setLocale, translate as t } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const TARGET = { id: 'e1', title: 'Cennik', slug: 'cennik' };

describe('a search hit is marked semantically, not only in colour', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  function mountCard() {
    return mount(KnowledgeSearchResultCard, {
      attachTo: document.body,
      props: {
        result: {
          entry: { ...TARGET, status: "approved", is_stale: false, entry_type: null },
          excerpt: "Podstawowa stawka wynosi 100 zł.",
          matched_chunk: {
            ordinal: 0,
            heading_path: null,
            snippet: "Podstawowa stawka wynosi 100 zł.",
            // A TUPLE `[start, length]`, not an object — the wire shape.
            highlights: [[0, 10]],
            score: 0.8,
          },
          rrf_score: 0.5,
          matched_chunks_count: 1,
        },
      },
    });
  }

  it('uses a real <mark>, not a tinted span', () => {
    // A neutral span with a background tint carries the match in COLOUR ALONE: it disappears in
    // greyscale and does not exist for a screen reader — the reader most in need of being told
    // which words matched.
    const wrapper = mountCard();

    expect(wrapper.findAll('mark').length).toBeGreaterThan(0);
    wrapper.unmount();
  });

  it('speaks the marker too, the way TextDiffView does', () => {
    const wrapper = mountCard();
    const mark = wrapper.find('mark');

    expect(mark.text()).toContain(t('knowledge.search.matchStart'));
    wrapper.unmount();
  });
});

describe('a similarity score reads the same way it is announced', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('renders the TRANSLATED label, not a bare percentage', () => {
    // The component already built this string and used it only for `aria-label`, so the sighted
    // reading and the spoken one were different — and the search card disagreed with this row
    // about how a score is written at all.
    const wrapper = mount(KnowledgeSimilarRow, {
      attachTo: document.body,
      props: {
        row: { linkId: 'l1', target: TARGET, score: 0.82, dismissed: false, canDismiss: true },
      },
    });

    expect(wrapper.text()).toContain(t('knowledge.similar.score', '', { percent: 82 }));
    wrapper.unmount();
  });
});

describe('quotation marks come from the catalog', () => {
  beforeEach(() => {
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  function mountRow() {
    return mount(KnowledgeMentionRow, {
      attachTo: document.body,
      props: {
        row: {
          linkId: 'l1',
          target: TARGET,
          direction: 'out' as const,
          text: 'polityka zwrotów',
          dismissed: false,
          canDismiss: true,
        },
      },
    });
  }

  it('uses Polish quotes in Polish', () => {
    setLocale('pl');
    const wrapper = mountRow();

    expect(wrapper.text()).toContain('„polityka zwrotów”');
    wrapper.unmount();
  });

  it('uses straight quotes in English — they were hardcoded Polish before', () => {
    setLocale('en');
    const wrapper = mountRow();

    expect(wrapper.text()).toContain('"polityka zwrotów"');
    expect(wrapper.text()).not.toContain('„');
    wrapper.unmount();
  });
});

describe('a row title is the Link primitive, and still inherits the row colour', () => {
  // These rows moved off a raw <a> onto `Link variant="plain"`. Two things had to survive the swap
  // and neither fails loudly when it does not:
  //   • the click still reaches the row's handler AND the anchor's own `#slug` navigation is still
  //     prevented — `@click.prevent` now rides a COMPONENT event, not a native one;
  //   • the dismissed state still paints the title (muted + struck through). Every other Link
  //     variant forces a colour, so picking the wrong one repaints the row and erases that signal.
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  function mountSimilar(dismissed: boolean) {
    return mount(KnowledgeSimilarRow, {
      attachTo: document.body,
      props: { row: { linkId: 'l1', target: TARGET, score: 0.82, dismissed, canDismiss: true } },
    });
  }

  it('opens the entry on click without letting the anchor navigate to #slug', async () => {
    const wrapper = mountSimilar(false);
    const anchor = wrapper.find('a');
    expect(anchor.attributes('href')).toBe('#cennik');

    const event = new MouseEvent('click', { bubbles: true, cancelable: true });
    anchor.element.dispatchEvent(event);

    expect(wrapper.emitted('open')?.[0]).toEqual(['cennik']);
    expect(event.defaultPrevented).toBe(true);
    wrapper.unmount();
  });

  it('keeps the dismissed title muted and struck through, with no forced link colour', () => {
    const wrapper = mountSimilar(true);
    const classes = wrapper.find('a').classes();

    expect(classes).toContain('line-through');
    expect(classes).toContain('text-next-muted-foreground');
    expect(classes).not.toContain('text-next-primary');
    wrapper.unmount();
  });

  it('leaves a live title with no colour of its own — it inherits the row', () => {
    const wrapper = mountSimilar(false);
    const classes = wrapper.find('a').classes();

    expect(classes).not.toContain('text-next-primary');
    expect(classes).not.toContain('text-next-muted-foreground');
    // Still legible AS a link: the affordance is the hover/focus underline.
    expect(classes).toContain('hover:underline');
    wrapper.unmount();
  });
});
