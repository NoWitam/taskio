// Pure "does this file satisfy the field's accept list" test for the Disk file picker.
//
// A file FIELD stores its accepted types the same way an <input accept> does: each token is
// a MIME type ('application/pdf'), a MIME wildcard ('image/*'), or a dotted extension
// ('.pdf'). The upload dropzone enforces these on an upload; a "pick from Disk" bypasses the
// dropzone, so the picker re-applies the same test to keep a pick and an upload consistent.
// An empty list means the field placed no restriction — everything is accepted.

export function matchesAccept(
  name: string,
  mime: string | null | undefined,
  acceptedTypes: string[],
): boolean {
  if (!acceptedTypes.length) return true;

  const lowerName = name.toLowerCase();
  const lowerMime = (mime ?? '').toLowerCase();

  return acceptedTypes.some((raw) => {
    const token = raw.trim().toLowerCase();
    if (token === '') return false;

    // Dotted extension: '.pdf' matches a name ending in it.
    if (token.startsWith('.')) return lowerName.endsWith(token);

    // MIME wildcard: 'image/*' matches any mime with that top-level type (keep the slash so
    // 'image/*' does not also match a hypothetical 'imagexyz/…').
    if (token.endsWith('/*')) return lowerMime.startsWith(token.slice(0, -1));

    // Otherwise an exact MIME match.
    return lowerMime === token;
  });
}
