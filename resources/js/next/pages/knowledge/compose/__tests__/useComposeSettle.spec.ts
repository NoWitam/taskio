// @vitest-environment happy-dom
// useComposeSettle.spec — the NO-POLLING contract (spec DC1 / R15).
//
// This is a standing user requirement, not a style preference: generation settles on the websocket
// push, never on a loop. The brief for this batch said "polling"; the project convention is
// stronger and wins. So the assertions here are mostly about what does NOT happen — how many
// fetches each path costs — because a regression to `setInterval` would still pass a naive
// "does it eventually resolve" test.
import { describe, it, expect, beforeEach, vi } from 'vitest';

const h = vi.hoisted(() => ({
  /** The listeners the composable registered on the (fake) channel. */
  handlers: {} as Record<string, (payload: unknown) => void>,
  errorHandlers: [] as Array<(err: unknown) => void>,
  stopListening: vi.fn(),
  /** null → simulate "Reverb is not configured". */
  channelAvailable: true,
  subscribed: [] as string[],
  fetchDraftSession: vi.fn(),
  session: null as unknown,
}));

vi.mock('../../../../app/lib/echo', () => ({
  subscribePrivate: (name: string) => {
    h.subscribed.push(name);
    if (!h.channelAvailable) return null;
    const channel = {
      listen(event: string, cb: (payload: unknown) => void) {
        h.handlers[event] = cb;
        return channel;
      },
      error(cb: (err: unknown) => void) {
        h.errorHandlers.push(cb);
        return channel;
      },
      stopListening: h.stopListening,
    };
    return channel;
  },
}));
vi.mock('../../../../app/lib/api', () => ({ WORKSPACE_KEY: 'next-workspace' }));
vi.mock('../../../../app/stores/knowledge', () => ({
  useKnowledgeStore: () => ({
    fetchDraftSession: h.fetchDraftSession,
    get session() {
      return h.session;
    },
  }),
}));

import { useComposeSettle } from '../useComposeSettle';

const EVENT = '.knowledge-draft-session.updated';

function session(status: string) {
  return { id: 's1', status };
}

describe('useComposeSettle', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    h.handlers = {};
    h.errorHandlers = [];
    h.subscribed = [];
    h.channelAvailable = true;
    h.session = null;
    localStorage.setItem('next-workspace', 'w1');
  });

  it('subscribes to the WORKSPACE channel the backend broadcasts on', async () => {
    h.fetchDraftSession.mockResolvedValue(session('generating'));
    const settle = useComposeSettle();

    void settle.waitForSettle('s1');
    await Promise.resolve();

    expect(h.subscribed).toEqual(['knowledge.workspace.w1']);
    expect(Object.keys(h.handlers)).toEqual([EVENT]);
    settle.dispose();
  });

  it('settles on the EVENT with exactly ONE further fetch', async () => {
    // The race-safe check on subscribe sees `generating`; the event then brings the real answer.
    h.fetchDraftSession.mockResolvedValueOnce(session('generating'));
    const settle = useComposeSettle();

    const pending = settle.waitForSettle('s1');
    await Promise.resolve();
    await Promise.resolve();

    h.fetchDraftSession.mockResolvedValue(session('ready'));
    h.handlers[EVENT]({ id: 's1' });

    const outcome = await pending;
    expect(outcome.settled).toBe(true);
    expect(outcome.session).toMatchObject({ status: 'ready' });
    // One race-safe check + one on the event. Nothing periodic.
    expect(h.fetchDraftSession).toHaveBeenCalledTimes(2);
    settle.dispose();
  });

  it('IGNORES an event for another session sharing the workspace channel', async () => {
    h.fetchDraftSession.mockResolvedValue(session('generating'));
    const settle = useComposeSettle();

    const pending = settle.waitForSettle('s1');
    await Promise.resolve();
    const callsAfterSubscribe = h.fetchDraftSession.mock.calls.length;

    h.handlers[EVENT]({ id: 'someone-elses-session' });
    await Promise.resolve();

    expect(h.fetchDraftSession).toHaveBeenCalledTimes(callsAfterSubscribe);

    // Let the wait finish so the test does not leak a timer.
    h.fetchDraftSession.mockResolvedValue(session('ready'));
    h.handlers[EVENT]({ id: 's1' });
    await pending;
    settle.dispose();
  });

  it('does NOT resolve while the session is still generating — it keeps waiting, not fetching', async () => {
    h.fetchDraftSession.mockResolvedValue(session('generating'));
    const settle = useComposeSettle({ timeoutMs: 10_000 });

    let resolved = false;
    void settle.waitForSettle('s1').then(() => (resolved = true));
    await Promise.resolve();
    await Promise.resolve();

    // A non-terminal event does not settle, and does not start a loop either.
    h.handlers[EVENT]({ id: 's1' });
    await Promise.resolve();
    await Promise.resolve();

    expect(resolved).toBe(false);
    expect(h.fetchDraftSession.mock.calls.length).toBeLessThanOrEqual(3);
    settle.dispose();
  });

  it('gives up ONCE on the safety timeout, reporting "not settled"', async () => {
    vi.useFakeTimers();
    h.fetchDraftSession.mockResolvedValue(session('generating'));
    const settle = useComposeSettle({ timeoutMs: 50 });

    const pending = settle.waitForSettle('s1');
    await vi.advanceTimersByTimeAsync(60);
    const outcome = await pending;

    // Resolved, but honestly: the caller shows a "refresh" affordance rather than spinning.
    expect(outcome.settled).toBe(false);
    vi.useRealTimers();
    settle.dispose();
  });

  it('with NO Reverb does exactly one delayed fetch and stops', async () => {
    vi.useFakeTimers();
    h.channelAvailable = false;
    h.fetchDraftSession.mockResolvedValue(session('ready'));
    const settle = useComposeSettle({ reverbAbsentMs: 20 });

    const pending = settle.waitForSettle('s1');
    await vi.advanceTimersByTimeAsync(30);
    const outcome = await pending;

    expect(outcome.settled).toBe(true);
    expect(h.fetchDraftSession).toHaveBeenCalledTimes(1);
    vi.useRealTimers();
    settle.dispose();
  });

  it('treats a subscription ERROR as "no events will ever arrive" — one fetch, not a hang', async () => {
    h.fetchDraftSession.mockResolvedValue(session('generating'));
    const settle = useComposeSettle({ timeoutMs: 999_999 });

    const pending = settle.waitForSettle('s1');
    await Promise.resolve();

    for (const cb of h.errorHandlers) cb(new Error('auth failed'));
    const outcome = await pending;

    expect(outcome.settled).toBe(false);
    settle.dispose();
  });

  it('tears the channel down on dispose', async () => {
    h.fetchDraftSession.mockResolvedValue(session('ready'));
    const settle = useComposeSettle();
    await settle.waitForSettle('s1');

    settle.dispose();
    expect(h.stopListening).toHaveBeenCalledWith(EVENT);
  });
});
