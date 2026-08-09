<script setup lang="ts">
// MarkdownViewer — a read-only renderer for a MARKDOWN STRING (Tier 7).
//
// Pipeline (same conventions as the editor's FORMAT contract): `marked` parses
// the markdown to HTML, then DOMPurify sanitizes it before it ever touches the
// DOM. We render the SAME base grammar the editor produces (headings, bold/
// italic/underline/strike, inline + fenced code, links, lists, blockquote, hr,
// GFM tables), styled by the shared `.next-md-prose` token typography (see
// next.css). Used for previews and anywhere the app displays markdown.
//
// SECURITY:
//  • DOMPurify strips scripts/event handlers/iframes/etc. `<u>` is allowed (our
//    underline convention); raw HTML beyond the safe allow-list is removed.
//  • Links are post-processed: only http/https/mailto survive; everything else is
//    neutralized. External links get rel="noopener noreferrer" + target opt-in.
//  • WIKILINKS (opt-in, see `wikilinks` below) are built AFTER sanitization from
//    plain text nodes, with `createElement` + `textContent` — never by injecting
//    markup. The sanitizer config is untouched.
import { computed } from 'vue';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

/**
 * Wikilink rendering options (opt-in). Supplying this turns `[[slug]]` /
 * `[[slug|label]]` in the SOURCE TEXT into anchors; omitting it leaves them as
 * the literal characters they are today.
 */
export interface WikilinkOptions {
  /**
   * Resolve a slug to its destination. Return `null` to render a GHOST ("red
   * link") — an entry that does not exist yet, which is an invitation to create
   * it rather than an error.
   */
  resolve: (slug: string) => { href: string; title: string } | null;
  /**
   * Accessible name for a ghost anchor, e.g. `label => "${label} — entry does not
   * exist"`. Passed IN rather than translated here so this design-system
   * component carries no module copy.
   */
  ghostLabel?: (label: string) => string;
}

const props = withDefaults(
  defineProps<{
    /** The markdown source to render. Non-string values are treated as empty
     *  (defensive — callers should pass a markdown string). */
    source?: unknown;
    /** Open links in a new tab (adds target=_blank + safe rel). */
    openLinksInNewTab?: boolean;
    /** Accessible label for the rendered region. */
    ariaLabel?: string;
    /**
     * OPT-IN wikilink rendering. Default OFF: with the prop unset this component's
     * output is byte-identical to what it produced before the feature existed
     * (pinned by a regression test), so no existing consumer changes.
     */
    wikilinks?: WikilinkOptions;
  }>(),
  { openLinksInNewTab: false },
);

const SAFE_LINK = /^(https?:\/\/|mailto:)/i;

/**
 * `[[slug]]` or `[[slug|label]]`.
 *
 * Neither capture may contain a bracket, and the slug may not contain the pipe —
 * so the match can never span two links or swallow a stray bracket.
 */
const WIKILINK_RE = /\[\[([^[\]|]+?)(?:\|([^[\]]+?))?\]\]/g;

/** Tags whose text is never a wikilink: code samples and existing anchors. */
const WIKILINK_SKIP = new Set(['CODE', 'PRE', 'A']);

/**
 * A wikilink `href` is built by the HOST (a router path), so it is not user
 * content — but it is still validated, because "the caller is trusted" is exactly
 * the assumption that turns one careless `href` into an XSS. Only same-origin
 * paths and the protocols the rest of this component already allows get through.
 */
function safeWikilinkHref(href: string): string | null {
  if (href.startsWith('/') && !href.startsWith('//')) return href;
  return SAFE_LINK.test(href) ? href : null;
}

/** Collect the text nodes eligible for wikilink substitution (skipping code/pre/a). */
function collectTextNodes(root: Node, out: Text[]): void {
  for (const node of Array.from(root.childNodes)) {
    if (node.nodeType === 3 /* TEXT_NODE */) {
      out.push(node as Text);
    } else if (node.nodeType === 1 /* ELEMENT_NODE */) {
      if (WIKILINK_SKIP.has((node as Element).tagName)) continue;
      collectTextNodes(node, out);
    }
  }
}

/**
 * Replace `[[…]]` runs inside already-SANITIZED text nodes with anchors.
 *
 * Every element is created with `document.createElement` and every string is
 * assigned through `textContent` / `setAttribute`, so nothing here can introduce
 * markup: a `[[<img src=x onerror=alert(1)>]]` in the source is text by the time
 * it reaches us, and it stays text inside the anchor's label.
 */
function applyWikilinks(root: DocumentFragment, options: WikilinkOptions): void {
  const textNodes: Text[] = [];
  collectTextNodes(root, textNodes);

  for (const node of textNodes) {
    const text = node.nodeValue ?? '';
    if (!text.includes('[[')) continue;

    WIKILINK_RE.lastIndex = 0;
    let match: RegExpExecArray | null;
    let cursor = 0;
    const frag = document.createDocumentFragment();

    while ((match = WIKILINK_RE.exec(text)) !== null) {
      const [raw, rawSlug, rawLabel] = match;
      const slug = rawSlug.trim();
      if (slug === '') continue;

      if (match.index > cursor) {
        frag.appendChild(document.createTextNode(text.slice(cursor, match.index)));
      }
      cursor = match.index + raw.length;

      const label = (rawLabel ?? '').trim();
      const target = options.resolve(slug);
      const anchor = document.createElement('a');
      anchor.setAttribute('data-wikilink', slug);

      if (target) {
        const href = safeWikilinkHref(target.href);
        if (href) anchor.setAttribute('href', href);
        anchor.textContent = label || target.title || slug;
      } else {
        // A ghost has no href, so it is not focusable or activatable by default —
        // it gets the link role, a tab stop, and (from the host) a name that says
        // the entry does not exist. The reader answers Enter for it.
        anchor.setAttribute('data-ghost', 'true');
        anchor.setAttribute('role', 'link');
        anchor.setAttribute('tabindex', '0');
        const text = label || slug;
        anchor.textContent = text;
        const aria = options.ghostLabel?.(text);
        if (aria) anchor.setAttribute('aria-label', aria);
      }

      frag.appendChild(anchor);
    }

    if (cursor === 0) continue; // nothing matched — leave the node exactly as it was
    if (cursor < text.length) {
      frag.appendChild(document.createTextNode(text.slice(cursor)));
    }
    node.parentNode?.replaceChild(frag, node);
  }
}

const html = computed(() => {
  // Guard: only a STRING is renderable markdown. A non-string source (e.g. a
  // ProseMirror doc object passed by mistake) is treated as empty so this can
  // never crash — callers should convert docs to markdown before passing them.
  const raw = typeof props.source === 'string' ? props.source : '';
  if (!raw.trim()) return '';

  const parsed = marked.parse(raw, { gfm: true, breaks: false, async: false });
  const dirty = typeof parsed === 'string' ? parsed : '';

  const clean = DOMPurify.sanitize(dirty, {
    ALLOWED_TAGS: [
      'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
      'strong', 'b', 'em', 'i', 'u', 'del', 's', 'strike',
      'a', 'code', 'pre', 'blockquote',
      'ul', 'ol', 'li',
      'table', 'thead', 'tbody', 'tr', 'th', 'td',
      'span',
    ],
    ALLOWED_ATTR: ['href', 'title', 'align', 'start'],
    ALLOW_DATA_ATTR: false,
  });

  // Post-process links in a detached fragment: drop unsafe protocols, add safe
  // rel + optional target. Never touches the live DOM.
  const tpl = document.createElement('template');
  tpl.innerHTML = clean;
  tpl.content.querySelectorAll('a').forEach((a) => {
    const href = a.getAttribute('href') ?? '';
    if (!SAFE_LINK.test(href)) {
      a.removeAttribute('href');
      return;
    }
    a.setAttribute('rel', 'noopener noreferrer nofollow');
    if (props.openLinksInNewTab) a.setAttribute('target', '_blank');
  });

  // OPT-IN, and deliberately the LAST step: the wikilink pass only ever sees text
  // that already survived DOMPurify + the link scrub. With `wikilinks` unset it
  // never runs, which is what keeps the default output byte-identical.
  if (props.wikilinks) applyWikilinks(tpl.content, props.wikilinks);

  return tpl.innerHTML;
});

const isEmpty = computed(() => !html.value);
</script>

<template>
  <div
    v-if="!isEmpty"
    class="next-md-prose"
    :aria-label="ariaLabel"
    v-html="html"
  />
  <p v-else class="next-md-prose text-next-muted-foreground italic">
    Nothing to show.
  </p>
</template>
