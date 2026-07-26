// Pure accept-matching for the Disk file picker — mirrors how <input accept> reads a token.
import { describe, it, expect } from 'vitest';
import { matchesAccept } from '../pickerMatch';

describe('matchesAccept', () => {
  it('accepts everything when the list is empty (the field placed no restriction)', () => {
    expect(matchesAccept('anything.xyz', 'application/octet-stream', [])).toBe(true);
  });

  it('matches a dotted extension against the file name, case-insensitively', () => {
    expect(matchesAccept('Report.PDF', 'application/pdf', ['.pdf'])).toBe(true);
    expect(matchesAccept('photo.png', 'image/png', ['.pdf'])).toBe(false);
  });

  it('matches a MIME wildcard on the top-level type only', () => {
    expect(matchesAccept('photo.png', 'image/png', ['image/*'])).toBe(true);
    expect(matchesAccept('clip.mp4', 'video/mp4', ['image/*'])).toBe(false);
  });

  it('matches an exact MIME type', () => {
    expect(matchesAccept('doc.pdf', 'application/pdf', ['application/pdf'])).toBe(true);
    expect(matchesAccept('doc.pdf', 'application/pdf', ['image/png'])).toBe(false);
  });

  it('passes when ANY token in the list matches', () => {
    expect(matchesAccept('photo.png', 'image/png', ['.pdf', 'image/*'])).toBe(true);
  });

  it('is safe when the mime is missing (an extension token can still match)', () => {
    expect(matchesAccept('archive.zip', null, ['.zip'])).toBe(true);
    expect(matchesAccept('archive.zip', null, ['application/zip'])).toBe(false);
  });
});
