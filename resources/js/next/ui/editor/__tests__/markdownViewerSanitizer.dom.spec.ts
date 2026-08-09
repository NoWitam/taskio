// @vitest-environment happy-dom
// markdownViewerSanitizer.dom.spec — B7: the SANITIZER itself, without the wikilink prop.
//
// WHY THIS EXISTS AT ALL. B7 was briefed to record "DOMPurify cannot be tested under happy-dom
// (`isSupported === false`, so `sanitize()` is a pass-through)" as an accepted environment
// limitation. That is NOT true on this repo's toolchain: under happy-dom@20, `DOMPurify.isSupported`
// is `true` and every classic vector below is neutralized. The limitation was real for older
// jsdom-less setups and is worth un-recording, because "we cannot test it" is the kind of note that
// outlives its reason and leaves a security-critical component permanently uncovered.
//
// WHAT IS COVERED ELSEWHERE. markdownViewerWikilinks.dom.spec proves the wikilink pass does not
// WEAKEN the sanitizer (markup inside `[[…]]` stays text, only <a> is emitted, a hostile resolver
// href is refused). It says nothing about the sanitizer on its own — which is the configuration every
// other caller of MarkdownViewer in the app uses, and the one that renders knowledge entries, bot
// output and AI text.
//
// The pipeline being pinned is `marked` → DOMPurify(allow-list) → link scrub. The allow-list is the
// load-bearing half: `ALLOW_DATA_ATTR: false` is what forces the wikilink pass to run AFTER
// sanitization, and a well-meaning change to `ADD_ATTR` would quietly reopen `onerror`.
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import DOMPurify from 'dompurify';
import MarkdownViewer from '../MarkdownViewer.vue';

function render(source: string) {
  return mount(MarkdownViewer, { props: { source } });
}

describe('the sanitizer is actually running in this environment', () => {
  // The premise of every assertion below. Without it they would all pass against a pass-through.
  it('DOMPurify reports itself supported under happy-dom', () => {
    expect((DOMPurify as unknown as { isSupported: boolean }).isSupported).toBe(true);
  });

  it('and it really removes something', () => {
    expect(DOMPurify.sanitize('<p>ok</p><script>alert(1)</script>')).toBe('<p>ok</p>');
  });
});

describe('MarkdownViewer refuses active content', () => {
  // Markdown permits raw HTML, so everything here is a realistic paste into an entry body.
  const vectors: Record<string, string> = {
    'a script tag': '<script>alert(1)</script>',
    'an inline event handler': '<img src=x onerror=alert(1)>',
    'a handler on an allowed tag': '<a href="/ok" onclick="alert(1)">link</a>',
    'an svg load handler': '<svg onload=alert(1)></svg>',
    // No `src`: happy-dom eagerly FETCHES one while DOMPurify is still inspecting the node, and a
    // unit test has no business opening a socket. The assertion is that the tag never survives.
    'an iframe': '<iframe></iframe>',
    'an object tag': '<object data="evil.swf"></object>',
    'a style tag': '<style>body{background:url(javascript:alert(1))}</style>',
    'a form': '<form action="/steal"><input name="x"></form>',
    // The mXSS classic: mutation through a foreign-content parsing quirk.
    'a mathml mutation vector': '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>',
  };

  for (const [name, source] of Object.entries(vectors)) {
    it(`strips ${name}`, () => {
      const html = render(source).html();

      expect(html).not.toContain('<script');
      expect(html).not.toContain('onerror');
      expect(html).not.toContain('onclick');
      expect(html).not.toContain('onload');
      expect(html).not.toContain('<iframe');
      expect(html).not.toContain('<object');
      expect(html).not.toContain('<style');
      expect(html).not.toContain('<form');
    });
  }

  it('keeps the surrounding prose while dropping the active part', () => {
    // Refusal must not cost the reader their document: everything legitimate around the vector
    // survives, which is the difference between a sanitizer and a delete button.
    const wrapper = render('Przed. <script>alert(1)</script> Po.');

    expect(wrapper.text()).toContain('Przed.');
    expect(wrapper.text()).toContain('Po.');
    expect(wrapper.html()).not.toContain('<script');
  });
});

describe('MarkdownViewer refuses unsafe link protocols', () => {
  it('drops a javascript: href while keeping the link text', () => {
    const anchor = render('[klik](javascript:alert(1))').find('a');

    expect(anchor.exists()).toBe(true);
    expect(anchor.attributes('href')).toBeUndefined();
    expect(anchor.text()).toBe('klik');
  });

  it('drops a data: href', () => {
    expect(
      render('[klik](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)')
        .find('a')
        .attributes('href'),
    ).toBeUndefined();
  });

  it('keeps http(s) and mailto, and hardens them with rel', () => {
    for (const href of ['https://example.com', 'http://example.com', 'mailto:a@example.com']) {
      const anchor = render(`[klik](${href})`).find('a');

      expect(anchor.attributes('href')).toBe(href);
      // `noopener` is not decoration: without it a link opened in a new context can rewrite this one.
      expect(anchor.attributes('rel')).toBe('noopener noreferrer nofollow');
    }
  });

  /**
   * CHARACTERIZATION, not an endorsement. The link scrub's `SAFE_LINK` allows only `http(s):` and
   * `mailto:`, so an ORDINARY markdown link to a path inside the app — `[patrz](/next/knowledge/x)` —
   * comes out as unlinked text. Wikilinks are unaffected: they go through `safeWikilinkHref`, which
   * does accept a same-origin path, and they are the module's intended way to link internally.
   *
   * Pinned so the asymmetry is visible. It is defensible (one internal link syntax, not two) but it
   * is also silent — the link simply stops being a link — and B8/B9 should decide whether to document
   * it or to widen the scrub.
   */
  it('also drops a same-origin PATH in an ordinary markdown link', () => {
    const anchor = render('[patrz](/next/knowledge/cennik)').find('a');

    expect(anchor.exists()).toBe(true);
    expect(anchor.text()).toBe('patrz');
    expect(anchor.attributes('href')).toBeUndefined();
  });

  it('refuses a protocol-relative URL, which is same-origin only by accident', () => {
    expect(render('[klik](//evil.example/x)').find('a').attributes('href')).toBeUndefined();
  });
});

describe('MarkdownViewer keeps the allow-list narrow', () => {
  it('strips data-* attributes, which is what forces the wikilink pass to run last', () => {
    const html = render('<span data-wikilink="cennik">x</span>').html();

    expect(html).toContain('x');
    expect(html).not.toContain('data-wikilink');
  });

  it('still renders the formatting the allow-list is for', () => {
    const html = render('# Tytuł\n\n**pogrubienie**, `kod`\n\n- punkt\n\n| a | b |\n| - | - |\n| 1 | 2 |').html();

    for (const tag of ['<h1', '<strong', '<code', '<ul', '<table']) {
      expect(html).toContain(tag);
    }
  });

  it('renders nothing at all for blank input', () => {
    expect(render('   ').html()).not.toContain('<p>');
  });
});
