// Unit tests for the pure Bot Inbox bucket presentation (Batch 7): each state maps
// to a label key / icon / tone, the render order is the contract order, and an
// unknown state falls back gracefully.
import { describe, expect, it } from 'vitest';
import { botInboxMeta, ALL_BUCKET_META } from '../botInboxMeta';
import { BOT_INBOX_STATES } from '../types';

describe('botInboxMeta', () => {
  it('maps each state to its label key + icon + tone (per the contract)', () => {
    expect(botInboxMeta('queued')).toMatchObject({ i18nKey: 'bots.inbox.states.queued', icon: 'clock', tone: 'neutral' });
    expect(botInboxMeta('running')).toMatchObject({ i18nKey: 'bots.inbox.states.running', icon: 'loader', tone: 'info' });
    expect(botInboxMeta('waiting')).toMatchObject({ i18nKey: 'bots.inbox.states.waiting', icon: 'help-circle', tone: 'warning' });
    expect(botInboxMeta('in_approval')).toMatchObject({ i18nKey: 'bots.inbox.states.in_approval', icon: 'lock', tone: 'info' });
    expect(botInboxMeta('revision')).toMatchObject({ i18nKey: 'bots.inbox.states.revision', icon: 'rotate-ccw', tone: 'warning' });
    expect(botInboxMeta('failed')).toMatchObject({ i18nKey: 'bots.inbox.states.failed', icon: 'alert-triangle', tone: 'danger' });
    expect(botInboxMeta('done')).toMatchObject({ i18nKey: 'bots.inbox.states.done', icon: 'check-circle', tone: 'success' });
  });

  it('exposes the buckets in the fixed contract order', () => {
    expect(BOT_INBOX_STATES).toEqual([
      'queued',
      'running',
      'waiting',
      'in_approval',
      'revision',
      'failed',
      'done',
    ]);
  });

  it('falls back gracefully for an unknown state', () => {
    expect(botInboxMeta('something_new')).toMatchObject({
      i18nKey: 'bots.inbox.states.unknown',
      icon: 'circle',
      tone: 'neutral',
    });
  });

  it('provides an "All" pseudo-bucket descriptor', () => {
    expect(ALL_BUCKET_META).toMatchObject({ i18nKey: 'bots.inbox.states.all', icon: 'inbox' });
  });
});
