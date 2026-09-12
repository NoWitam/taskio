// publicationPayload — the pure builder for a publication's create/update body.
//
// Extracted for the same reason `pages/tasks/taskPayload.ts` was: the trap it encodes is
// invisible in a template and can only be pinned by asserting on the PAYLOAD.
//
// ═════════════════════════════════════════════════════════════════════════════════════════
// A `PUT` IS A WHOLE-ROW WRITE. WHAT THE FORM OMITS, THE DATABASE NULLS.
// ═════════════════════════════════════════════════════════════════════════════════════════
// `PublicationService::attributesFrom()` assigns title, body, platform,
// platform_connection_id, scheduled_at, media AND options on every update. Three real
// consequences, in rising order of how quietly they happen:
//
//   • no `media`        → the publication loses every attachment;
//   • no `options`      → `{"privacy":"unlisted"}` disappears — and no form renders that
//                         field, so nobody would see it go (D13);
//   • no `scheduled_at` on a `scheduled` row → the moment becomes null while the status
//                         stays `scheduled`, and the sweep (`scheduled_at <= now()`) never
//                         selects it again. Armed forever, going nowhere, with no message
//                         anywhere. The quietest defect this module can produce.
//
// Hence: every key, every time. `options` arrives from the GET and leaves untouched.
import type { PublicationWritePayload, PublishingPlatform } from './types';

/** What the composer collects, plus the value it carries through without showing. */
export interface BuildPublicationPayloadInput {
  title: string;
  body: string;
  platform: PublishingPlatform;
  /** The chosen account, or null. Always emitted — omitting it would detach one. */
  connectionId: string | null;
  /** Local wall clock `yyyy-mm-ddTHH:mm` on the WORKSPACE's clock, or null. */
  moment: string | null;
  media: string[];
  /**
   * The row's `options` exactly as the GET handed them over. A create with no prior value
   * passes `{}`, which is what the server already stores for a publication with no options.
   */
  options: Record<string, unknown>;
}

export function buildPublicationPayload(
  input: BuildPublicationPayloadInput,
): PublicationWritePayload {
  return {
    title: input.title,
    // An empty body is an ABSENT body, not the empty string: the column is nullable and the
    // adapter asks "is there text", not "is the text long".
    body: input.body === '' ? null : input.body,
    platform: input.platform,
    platform_connection_id: input.connectionId,
    scheduled_at: input.moment || null,
    // A copy, so a later edit of the form's array cannot reach into a sent payload.
    media: [...input.media],
    options: input.options,
  };
}
