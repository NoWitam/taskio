// publicationPayload.spec — the whole-row write, asserted on the PAYLOAD.
//
// This is the one test in the module whose absence would be felt only in production. A `PUT`
// assigns every column; a key the form omits is nulled in the database. Two of the three
// consequences are invisible on screen:
//
//   • `options` has no editor at all (D13), so losing `{"privacy":"unlisted"}` while editing
//     a caption shows nothing anywhere;
//   • a missing `scheduled_at` on a `scheduled` row leaves the STATUS at `scheduled` with a
//     null moment, which the sweep (`scheduled_at <= now()`) can never select — armed
//     forever, going nowhere, silently.
//
// So the assertions below are about keys and their exact values, not about the UI.
import { describe, it, expect } from 'vitest';
import { buildPublicationPayload } from '../publicationPayload';

const base = {
  title: 'Autumn teaser',
  body: 'Something new is coming.',
  platform: 'youtube' as const,
  connectionId: 'c1',
  moment: '2026-09-10T09:00',
  media: ['file-a', 'file-b'],
  options: { privacy: 'unlisted' },
};

describe('buildPublicationPayload', () => {
  it('emits EVERY column the server assigns, every time', () => {
    expect(Object.keys(buildPublicationPayload(base)).sort()).toEqual([
      'body',
      'media',
      'options',
      'platform',
      'platform_connection_id',
      'scheduled_at',
      'title',
    ]);
  });

  it('carries `options` through untouched, though no form renders it', () => {
    const payload = buildPublicationPayload(base);
    expect(payload.options).toEqual({ privacy: 'unlisted' });
  });

  it('keeps an empty `options` as an object rather than dropping the key', () => {
    const payload = buildPublicationPayload({ ...base, options: {} });
    expect(payload).toHaveProperty('options');
    expect(payload.options).toEqual({});
  });

  it('preserves media ORDER — it is the order the platform receives them in', () => {
    const payload = buildPublicationPayload({ ...base, media: ['third', 'first', 'second'] });
    expect(payload.media).toEqual(['third', 'first', 'second']);
  });

  it('copies the media array so a later edit cannot reach into a sent payload', () => {
    const media = ['a'];
    const payload = buildPublicationPayload({ ...base, media });
    media.push('b');
    expect(payload.media).toEqual(['a']);
  });

  it('sends the moment it was given, and null only when there is none', () => {
    expect(buildPublicationPayload(base).scheduled_at).toBe('2026-09-10T09:00');
    expect(buildPublicationPayload({ ...base, moment: null }).scheduled_at).toBeNull();
    expect(buildPublicationPayload({ ...base, moment: '' }).scheduled_at).toBeNull();
  });

  it('always states the account, so a cleared picker DETACHES instead of being ignored', () => {
    const payload = buildPublicationPayload({ ...base, connectionId: null });
    expect(payload).toHaveProperty('platform_connection_id');
    expect(payload.platform_connection_id).toBeNull();
  });

  it('sends an absent body as null, not as an empty string', () => {
    expect(buildPublicationPayload({ ...base, body: '' }).body).toBeNull();
    expect(buildPublicationPayload({ ...base, body: 'x' }).body).toBe('x');
  });

  it('sends no key the request declares prohibited', () => {
    // `status`, `remote_id`, `remote_draft_id`, `published_at` and `attempts` are refused
    // rather than dropped: a client sending one believes it is setting something.
    const payload = buildPublicationPayload(base) as unknown as Record<string, unknown>;
    for (const forbidden of ['status', 'remote_id', 'remote_draft_id', 'published_at', 'attempts']) {
      expect(payload, forbidden).not.toHaveProperty(forbidden);
    }
  });
});
