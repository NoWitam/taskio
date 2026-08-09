// wikilinkAnchors — the reader's pure helpers: slug normalization, heading anchors, and the
// content-offset → DOM jump. No Vue, no store.

/**
 * Latin letters that Unicode normalization does NOT decompose, mapped the way Laravel's
 * `Str::ascii()` maps them.
 *
 * This exists because NFKD is not a transliterator. `ó` → `o` + a combining accent (so stripping
 * marks works), but `ł` has no canonical decomposition and survives NFKD intact — it would then hit
 * the `[^a-z0-9]` rule and become a HYPHEN. "Zażółć" would slug to `zazo-c` on the client and
 * `zazolc` on the server, so every Polish wikilink the picker wrote would point at nothing. In a
 * Polish-first product that is not an edge case.
 */
const TRANSLITERATE: Record<string, string> = {
  ł: 'l', Ł: 'l',
  đ: 'd', Đ: 'd',
  ø: 'o', Ø: 'o',
  ß: 'ss',
  æ: 'ae', Æ: 'ae',
  œ: 'oe', Œ: 'oe',
  þ: 'th', Þ: 'th',
  ð: 'd', Ð: 'd',
  ı: 'i', İ: 'i',
};

/**
 * Normalize a phrase into a wikilink slug.
 *
 * MIRRORS the backend's `Support/WikilinkParser::normalize`, which is `Str::slug(trim($raw))` —
 * i.e. Laravel transliterates to ASCII, lowercases, and collapses the rest to single hyphens.
 * The two MUST agree: this slug is what gets written into the markdown as `[[slug]]` and what the
 * server later resolves an edge against, so a client that slugified differently would author red
 * links pointing at entries that already exist.
 *
 * (The server remains authoritative — it mints an entry's slug itself on create. This function
 * covers the two places the client must predict it: the text the `[[` picker inserts, and the slug
 * preview in the editor.)
 */
export function normalizeSlug(value: string): string {
  return (value ?? '')
    // Transliterate the non-decomposing letters FIRST — NFKD would leave them for the
    // `[^a-z0-9]` rule to turn into hyphens.
    .replace(/[łŁđĐøØßæÆœŒþÞðÐıİ]/g, (ch) => TRANSLITERATE[ch] ?? ch)
    .normalize('NFKD')
    // Strip combining marks so "Ćwiczenia" and "Cwiczenia" reach the same slug.
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

/** Heading id prefix. Namespaced so it cannot collide with an id elsewhere on the page. */
const ANCHOR_PREFIX = 'h-';

/** The DOM id for a heading's text. */
export function headingAnchorId(text: string): string {
  return `${ANCHOR_PREFIX}${normalizeSlug(text) || 'section'}`;
}

/**
 * Give every `h1..h3` in a rendered article a stable id and make it programmatically focusable.
 *
 * Runs AFTER each render (the markdown is `v-html`, so the nodes are replaced wholesale). Duplicate
 * heading texts get a `-2`, `-3` … suffix: two sections called "Zasady" are common in a real
 * document, and without disambiguation a deep link would always land on the first.
 */
export function assignHeadingAnchors(root: HTMLElement | null | undefined): void {
  if (!root) return;
  const seen = new Map<string, number>();

  root.querySelectorAll('h1, h2, h3').forEach((heading) => {
    const base = headingAnchorId(heading.textContent ?? '');
    const count = (seen.get(base) ?? 0) + 1;
    seen.set(base, count);
    heading.setAttribute('id', count === 1 ? base : `${base}-${count}`);
    // -1 keeps it out of the tab order while still allowing focus() after a jump, so a screen
    // reader starts reading at the section the user asked for.
    heading.setAttribute('tabindex', '-1');
  });
}

/** How long the landing highlight stays before it is cleaned up. */
export const JUMP_HIGHLIGHT_MS = 2000;

/** True when the user asked the OS for less motion. Safe when `matchMedia` is absent (SSR/tests). */
function prefersReducedMotion(): boolean {
  return typeof window !== 'undefined' && typeof window.matchMedia === 'function'
    ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
    : false;
}

/**
 * Scroll a heading into view, focus it, and flash it briefly.
 *
 * Returns the timer id so a caller that navigates away can clear it. Under `prefers-reduced-motion`
 * the scroll is instant — an animated jump is exactly the vestibular trigger that setting exists
 * for. Focus is moved with `preventScroll` so the browser does not fight the scroll we just did.
 */
export function jumpToAnchor(
  root: HTMLElement | null | undefined,
  anchorId: string,
): ReturnType<typeof setTimeout> | null {
  const target = root?.querySelector<HTMLElement>(`#${CSS.escape(anchorId)}`);
  if (!target) return null;

  target.scrollIntoView({
    block: 'start',
    behavior: prefersReducedMotion() ? 'auto' : 'smooth',
  });
  target.focus({ preventScroll: true });
  target.classList.add('is-jump-target');

  return setTimeout(() => target.classList.remove('is-jump-target'), JUMP_HIGHLIGHT_MS);
}

/**
 * Find the heading that OWNS a character offset in the raw markdown, and return its anchor id.
 *
 * This is how "jump to passage" works from a search result: the server gives a `char_start` into
 * the entry's own content, and the rendered DOM has no offsets at all. Rather than trying to map
 * offsets onto DOM nodes — which markdown rendering makes impossible in general — we scan the
 * SOURCE for the last ATX heading at or before the offset and jump to that section. Landing at the
 * top of the right section is honest and stable; landing on a character we guessed is neither.
 *
 * Fenced code blocks are skipped so a `# comment` inside a sample is never mistaken for a heading.
 */
export function anchorForOffset(content: string, charStart: number): string | null {
  if (typeof content !== 'string' || content === '' || !Number.isFinite(charStart)) return null;

  const chars = Array.from(content);
  const upTo = chars.slice(0, Math.max(0, Math.trunc(charStart))).join('');

  let inFence = false;
  let heading: string | null = null;
  const counts = new Map<string, number>();

  // The whole prefix is scanned (not just the tail) because duplicate-heading numbering has to
  // count every earlier occurrence to produce the SAME suffix `assignHeadingAnchors` produced.
  for (const line of upTo.split(/\r?\n/)) {
    if (/^\s*(```|~~~)/.test(line)) {
      inFence = !inFence;
      continue;
    }
    if (inFence) continue;

    const match = line.match(/^\s{0,3}(#{1,3})\s+(.+?)\s*#*\s*$/);
    if (!match) continue;

    const base = headingAnchorId(match[2]);
    const count = (counts.get(base) ?? 0) + 1;
    counts.set(base, count);
    heading = count === 1 ? base : `${base}-${count}`;
  }

  return heading;
}
