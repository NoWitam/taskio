// Helpers for mapping the Comments API URLs onto the `next` api singleton.
//
// `entity.comments_url` is an ABSOLUTE URL built server-side via
// `route('comments.index', ...)` (e.g. `http://host/api/tasks/{id}/comments`).
// The api singleton already prefixes `/api`, so we reduce the URL to a path
// relative to that root, and derive the single-comment `/comments/{id}` path
// (PATCH/DELETE) under the same root.

/**
 * Reduce an absolute (or already-relative) comments URL to a path relative to the
 * api singleton's `/api` baseURL. Strips a leading `/api` segment; tolerates a
 * value that is already a path. Same-origin only by construction (the api root is
 * relative), so it can never be coerced to a foreign origin.
 */
export function toApiPath(absoluteOrPath: string): string {
  let path = absoluteOrPath;
  try {
    path = new URL(absoluteOrPath, window.location.origin).pathname;
  } catch {
    // already a path
  }
  return path.replace(/^\/api(?=\/|$)/, '') || '/';
}

/** The `/comments/{id}` path that hosts single-comment PATCH/DELETE. */
export function singleCommentPath(id: string | number): string {
  return `/comments/${id}`;
}
