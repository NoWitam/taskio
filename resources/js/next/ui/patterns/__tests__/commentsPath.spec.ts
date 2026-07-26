// @vitest-environment happy-dom
// Unit tests for the Comments URL normalization. `entity.comments_url` is an
// absolute, server-built route() URL; the api singleton prefixes `/api`, so it must
// be reduced to a path relative to that root (and never coerced to a foreign origin).
import { describe, expect, it } from 'vitest';
import { toApiPath, singleCommentPath } from '../commentsPath';

describe('toApiPath', () => {
  it('reduces an absolute same-origin /api URL to a relative path', () => {
    expect(toApiPath('http://localhost/api/tasks/abc/comments')).toBe('/tasks/abc/comments');
  });

  it('strips the leading /api from an already-relative path', () => {
    expect(toApiPath('/api/tasks/abc/comments')).toBe('/tasks/abc/comments');
  });

  it('passes through a relative path that has no /api prefix', () => {
    expect(toApiPath('/tasks/abc/comments')).toBe('/tasks/abc/comments');
  });

  it('only strips /api as a whole segment (not a prefix like /apiary)', () => {
    expect(toApiPath('/apiary/comments')).toBe('/apiary/comments');
  });

  it('drops the query string (only the pathname is used)', () => {
    expect(toApiPath('http://localhost/api/tasks/abc/comments?cursor=x')).toBe('/tasks/abc/comments');
  });

  it('never targets a foreign origin — only the pathname survives', () => {
    expect(toApiPath('http://evil.example.com/api/tasks/abc/comments')).toBe('/tasks/abc/comments');
  });

  it('returns "/" when the path is exactly /api', () => {
    expect(toApiPath('/api')).toBe('/');
  });
});

describe('singleCommentPath', () => {
  it('builds the /comments/{id} path for string and numeric ids', () => {
    expect(singleCommentPath('c1')).toBe('/comments/c1');
    expect(singleCommentPath(42)).toBe('/comments/42');
  });
});
