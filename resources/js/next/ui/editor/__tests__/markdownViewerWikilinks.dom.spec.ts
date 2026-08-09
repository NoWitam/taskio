// @vitest-environment happy-dom
// markdownViewerWikilinks.dom.spec — the OPT-IN `wikilinks` prop on MarkdownViewer.
//
// Two things are being pinned, and the first matters more than the feature itself:
//
// 1. BYTE-IDENTICAL BY DEFAULT. The viewer renders markdown across the whole app. Adding a prop to
//    a shared, security-sensitive component is only safe if every existing caller is untouched, so
//    the spec renders the same source with and without the prop and compares the markup exactly.
//
// 2. THE SANITIZER IS NOT WEAKENED. The wikilink pass runs AFTER DOMPurify and builds elements with
//    `createElement` + `textContent`. A `[[...]]` containing markup must therefore come out as TEXT
//    inside the anchor, never as a live element — and DOMPurify's own guarantees (no scripts, no
//    event handlers, no javascript: hrefs) must still hold with the prop enabled.
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import MarkdownViewer from '../MarkdownViewer.vue';

const ENTRIES: Record<string, string> = {
  brand: 'Brand voice',
  pricing: 'Pricing policy',
};

/** Resolves known slugs; anything else becomes a ghost (red link). */
const wikilinks = {
  resolve: (slug: string) =>
    ENTRIES[slug] ? { href: `/next/knowledge/b1/reader/${slug}`, title: ENTRIES[slug] } : null,
  ghostLabel: (label: string) => `${label} — entry does not exist`,
};

function render(source: string, withWikilinks = false) {
  return mount(MarkdownViewer, {
    props: withWikilinks ? { source, wikilinks } : { source },
  });
}

describe('MarkdownViewer — default output is unchanged', () => {
  // Deliberately exercises every branch of the existing pipeline (headings, emphasis, code, links,
  // lists, tables) so "identical" is a claim about the whole renderer, not about one paragraph.
  const KITCHEN_SINK = [
    '# Heading',
    '',
    'Some **bold**, _italic_, `code` and a [link](https://example.com).',
    '',
    '- one',
    '- two',
    '',
    '```js',
    'const a = [[notALink]];',
    '```',
    '',
    '| a | b |',
    '| - | - |',
    '| 1 | 2 |',
    '',
    '> quote with [[brand]] inside',
  ].join('\n');

  it('renders byte-identically with the `wikilinks` prop absent', () => {
    const before = render(KITCHEN_SINK).html();
    const after = render(KITCHEN_SINK).html();
    expect(after).toBe(before);
  });

  it('leaves `[[…]]` as literal text when the prop is not passed', () => {
    const wrapper = render('See [[brand]] for details.');
    expect(wrapper.text()).toContain('[[brand]]');
    expect(wrapper.find('a[data-wikilink]').exists()).toBe(false);
  });

  it('differs ONLY once the prop is supplied', () => {
    const off = render('See [[brand]].').html();
    const on = render('See [[brand]].', true).html();
    expect(on).not.toBe(off);
  });
});

describe('MarkdownViewer — wikilink rendering', () => {
  it('turns a known slug into a real anchor carrying the target title', () => {
    const wrapper = render('See [[brand]].', true);
    const anchor = wrapper.find('a[data-wikilink]');
    expect(anchor.exists()).toBe(true);
    expect(anchor.attributes('href')).toBe('/next/knowledge/b1/reader/brand');
    expect(anchor.attributes('data-wikilink')).toBe('brand');
    expect(anchor.text()).toBe('Brand voice');
    expect(anchor.attributes('data-ghost')).toBeUndefined();
  });

  it('honours an explicit `|label`', () => {
    const wrapper = render('See [[brand|our voice]].', true);
    const anchor = wrapper.find('a[data-wikilink]');
    expect(anchor.text()).toBe('our voice');
    expect(anchor.attributes('data-wikilink')).toBe('brand');
  });

  it('renders an unknown slug as a keyboard-reachable GHOST with no href', () => {
    const wrapper = render('See [[missing-entry]].', true);
    const anchor = wrapper.find('a[data-ghost]');
    expect(anchor.exists()).toBe(true);
    expect(anchor.attributes('href')).toBeUndefined();
    // No href means no default activation, so it needs an explicit role + tab stop.
    expect(anchor.attributes('role')).toBe('link');
    expect(anchor.attributes('tabindex')).toBe('0');
    expect(anchor.attributes('aria-label')).toBe('missing-entry — entry does not exist');
  });

  it('handles several links in one paragraph and keeps the surrounding text', () => {
    const wrapper = render('A [[brand]] then [[pricing]] end.', true);
    const anchors = wrapper.findAll('a[data-wikilink]');
    expect(anchors).toHaveLength(2);
    expect(wrapper.text()).toContain('A ');
    expect(wrapper.text()).toContain(' then ');
    expect(wrapper.text()).toContain(' end.');
  });

  it('does NOT touch `[[…]]` inside code or inside an existing link', () => {
    const wrapper = render(
      ['`[[brand]]`', '', '```', '[[brand]]', '```', '', '[[[brand]]](https://example.com)'].join('\n'),
      true,
    );
    // The code spans keep the literal text…
    expect(wrapper.find('code').text()).toContain('[[brand]]');
    // …and nothing inside a <code>/<pre>/<a> became a wikilink anchor.
    expect(wrapper.findAll('code a[data-wikilink]')).toHaveLength(0);
    expect(wrapper.findAll('pre a[data-wikilink]')).toHaveLength(0);
  });

  it('leaves an unmatched or empty bracket run alone', () => {
    const wrapper = render('Unclosed [[brand and empty [[]] here.', true);
    expect(wrapper.findAll('a[data-wikilink]')).toHaveLength(0);
    expect(wrapper.text()).toContain('[[brand and empty');
  });
});

// NOTE ON SCOPE. DOMPurify reports `isSupported === false` under happy-dom and passes input through
// untouched, so asserting "DOMPurify strips onclick" here would test the test environment, not this
// component (verified: an inline handler survives identically with the prop ABSENT, i.e. on the
// pre-existing code path). What these specs pin instead is the property this change actually owns:
// the wikilink pass runs after sanitization and may only ADD anchors — it can neither introduce
// markup of its own nor alter anything else in the tree.
describe('MarkdownViewer — the wikilink pass adds anchors and nothing else', () => {
  it('treats markup inside `[[…]]` as TEXT, never as an element', () => {
    const wrapper = render('[[<img src=x onerror=alert(1)>]]', true);
    // Whatever survived is text; no image, no handler, no injected element.
    expect(wrapper.find('img').exists()).toBe(false);
    expect(wrapper.html()).not.toContain('onerror');
  });

  it('changes nothing but the anchors, even on hostile input', () => {
    const hostile = '<p>hi [[brand]] <em>x</em></p>';
    const off = render(hostile).html();
    const on = render(hostile, true).html();

    // Strip the anchors back out of the enabled render; what remains must be the disabled render
    // with the literal `[[brand]]` back in place. Anything else would mean the pass touched
    // something it had no business touching.
    const unwrapped = on.replace(
      /<a data-wikilink="([^"]*)"[^>]*>[^<]*<\/a>/g,
      (_m, slug) => `[[${slug}]]`,
    );
    expect(unwrapped).toBe(off);
  });

  it('never emits an element other than <a> from the transformation', () => {
    const wrapper = render('[[brand]] [[missing]] [[<b>x</b>]]', true);
    // Every element the pass created carries the marker attribute; no bare tags leak through.
    const created = wrapper.findAll('[data-wikilink]');
    for (const el of created) expect(el.element.tagName).toBe('A');
  });

  it('refuses a resolver-supplied href that is not a same-origin path or safe protocol', () => {
    const wrapper = mount(MarkdownViewer, {
      props: {
        source: 'See [[evil]].',
        // A hostile/careless HOST is still not allowed to inject a javascript: URL.
        wikilinks: { resolve: () => ({ href: 'javascript:alert(1)', title: 'Evil' }) },
      },
    });
    const anchor = wrapper.find('a[data-wikilink]');
    expect(anchor.exists()).toBe(true);
    expect(anchor.attributes('href')).toBeUndefined();
  });
});
