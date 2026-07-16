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
import { computed } from 'vue';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

const props = withDefaults(
  defineProps<{
    /** The markdown source to render. Non-string values are treated as empty
     *  (defensive — callers should pass a markdown string). */
    source?: unknown;
    /** Open links in a new tab (adds target=_blank + safe rel). */
    openLinksInNewTab?: boolean;
    /** Accessible label for the rendered region. */
    ariaLabel?: string;
  }>(),
  { openLinksInNewTab: false },
);

const SAFE_LINK = /^(https?:\/\/|mailto:)/i;

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
