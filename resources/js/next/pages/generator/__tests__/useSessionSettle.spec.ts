// @vitest-environment happy-dom
// Unit tests for `useSessionSettle` — the WEBSOCKET wait that replaced the store's `pollUntilSettled`.
// A generation-session run settles by WAITING for the backend's `.generation-session.updated` push on the
// per-workspace private channel and re-fetching once — it must NEVER poll. These cover the four resolution
// paths, all without a poll loop:
//   • the matching terminal event arrives → re-fetch → resolve settled,
//   • the race-safe SINGLE post-subscribe check finds it already terminal (event fired first) → resolve,
//   • the safety timeout fires with no event → ONE re-fetch → resolve (un-settled while still generating),
//   • Reverb is not configured → ONE delayed re-fetch → resolve.
// Echo is mocked (a fake channel we drive by hand); the api client is mocked so the store's fetchSession
// resolves deterministically. `@vue/test-utils`' flushPromises drains microtasks between steps.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { flushPromises } from '@vue/test-utils';

vi.mock('../../../app/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
  WORKSPACE_KEY: 'taskio_workspace',
}));

vi.mock('../../../app/lib/echo', () => ({
  subscribePrivate: vi.fn(),
}));

import { api, WORKSPACE_KEY } from '../../../app/lib/api';
import { subscribePrivate } from '../../../app/lib/echo';
import { useSessionSettle } from '../session/useSessionSettle';
import type { Session } from '../sessionTypes';

const EVENT = '.generation-session.updated';

const apiMock = api as unknown as { get: ReturnType<typeof vi.fn> };

function session(overrides: Partial<Session> = {}): Session {
  return {
    id: 's1',
    name: 'Spring promo',
    template_id: 't1',
    content_type: 'post_with_image',
    status: 'generating',
    slot_values: {},
    results: null,
    // A full-run CLAIM nulls the derived direction — it is re-derived per run and arrives with the settle.
    creative_direction: null,
    part_history: {},
    last_op_status: null,
    last_op_error: null,
    creator: null,
    is_owner: true,
    can_generate: true,
    can_edit: true,
    can_be_deleted: true,
    bot_author: null,
    is_delegated: false,
    can_delegate: true,
    can_undo_delegation: false,
    unfilled_required_slots: [],
    is_archived: false,
    can_archive: true,
    archived_at: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    ...overrides,
  };
}

/** A stand-in for the Echo private channel: capture the listeners, then emit / fail them by hand. */
function fakeChannel() {
  const handlers: Record<string, (p: unknown) => void> = {};
  let onError: (() => void) | null = null;
  const channel = {
    listen: vi.fn((event: string, cb: (p: unknown) => void) => {
      handlers[event] = cb;
      return channel;
    }),
    error: vi.fn((cb: () => void) => {
      onError = cb;
      return channel;
    }),
    stopListening: vi.fn(() => channel),
    emit(event: string, payload: unknown) {
      handlers[event]?.(payload);
    },
    fail() {
      onError?.();
    },
  };
  return channel;
}

describe('useSessionSettle', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
    localStorage.setItem(WORKSPACE_KEY, 'ws1');
  });

  afterEach(() => {
    vi.useRealTimers();
    localStorage.clear();
  });

  it('subscribes to the per-workspace channel and resolves settled on the matching terminal event', async () => {
    const channel = fakeChannel();
    vi.mocked(subscribePrivate).mockReturnValue(channel as never);
    apiMock.get
      .mockResolvedValueOnce({ data: session({ status: 'generating' }) }) // race-safe check → still running
      .mockResolvedValueOnce({ data: session({ status: 'ready', last_op_status: 'ok' }) }); // event re-fetch

    const settler = useSessionSettle();
    const pending = settler.waitForSettle('s1');
    await flushPromises(); // the race-safe check resolves `generating` → ignored, still waiting

    channel.emit(EVENT, { id: 's1', status: 'ready' });
    const outcome = await pending;

    expect(subscribePrivate).toHaveBeenCalledWith('generator.workspace.ws1');
    expect(channel.listen).toHaveBeenCalledWith(EVENT, expect.any(Function));
    expect(outcome.settled).toBe(true);
    expect(outcome.session?.status).toBe('ready');
    expect(outcome.session?.last_op_status).toBe('ok');

    settler.dispose();
    expect(channel.stopListening).toHaveBeenCalledWith(EVENT);
  });

  it('ignores an event for a different session id (one shared workspace channel, filtered by id)', async () => {
    const channel = fakeChannel();
    vi.mocked(subscribePrivate).mockReturnValue(channel as never);
    apiMock.get
      .mockResolvedValueOnce({ data: session({ status: 'generating' }) }) // race-safe check
      .mockResolvedValueOnce({ data: session({ status: 'ready' }) }); // the correct-id event's re-fetch

    const settler = useSessionSettle({ timeoutMs: 60_000 });
    const pending = settler.waitForSettle('s1');
    await flushPromises();

    channel.emit(EVENT, { id: 'someone-else', status: 'ready' }); // must NOT trigger a fetch or resolve
    await flushPromises();
    const stillPending = await Promise.race([pending.then(() => 'resolved'), Promise.resolve('pending')]);
    expect(stillPending).toBe('pending');
    expect(apiMock.get).toHaveBeenCalledTimes(1); // only the race-safe check ran — the foreign event was skipped

    channel.emit(EVENT, { id: 's1', status: 'ready' }); // the real one settles it (and clears the timer)
    const outcome = await pending;
    expect(outcome.settled).toBe(true);
    settler.dispose();
  });

  it('race-safe: resolves on the single post-subscribe check when the event fired first (no event needed)', async () => {
    const channel = fakeChannel();
    vi.mocked(subscribePrivate).mockReturnValue(channel as never);
    apiMock.get.mockResolvedValueOnce({ data: session({ status: 'ready' }) }); // already terminal on first check

    const settler = useSessionSettle();
    const outcome = await settler.waitForSettle('s1');

    expect(outcome.settled).toBe(true);
    expect(apiMock.get).toHaveBeenCalledTimes(1); // ONE check — not a poll loop
    settler.dispose();
  });

  it('safety timeout: with no event it re-fetches ONCE and resolves un-settled (never a poll loop)', async () => {
    vi.useFakeTimers();
    const channel = fakeChannel();
    vi.mocked(subscribePrivate).mockReturnValue(channel as never);
    apiMock.get.mockResolvedValue({ data: session({ status: 'generating' }) }); // stays generating forever

    const settler = useSessionSettle({ timeoutMs: 1000 });
    const pending = settler.waitForSettle('s1');
    await vi.advanceTimersByTimeAsync(1000); // flush the race-check microtask, then fire the safety timer

    const outcome = await pending;
    expect(outcome.settled).toBe(false); // gave up: still generating, surface a "refresh" hint upstream
    expect(outcome.session?.status).toBe('generating');
    expect(apiMock.get).toHaveBeenCalledTimes(2); // race-safe check + the single timeout re-fetch — NOT a loop
    settler.dispose();
  });

  it('subscription error gives up via a single re-fetch instead of hanging (still no poll)', async () => {
    const channel = fakeChannel();
    vi.mocked(subscribePrivate).mockReturnValue(channel as never);
    apiMock.get
      .mockResolvedValueOnce({ data: session({ status: 'generating' }) }) // race-safe check
      .mockResolvedValueOnce({ data: session({ status: 'ready' }) }); // the give-up re-fetch finds it done

    const settler = useSessionSettle({ timeoutMs: 60_000 });
    const pending = settler.waitForSettle('s1');
    await flushPromises();

    channel.fail(); // pusher:subscription_error → definitive "no events" → give up (one re-fetch)
    const outcome = await pending;
    expect(outcome.settled).toBe(true);
    expect(apiMock.get).toHaveBeenCalledTimes(2); // race check + give-up re-fetch — bounded, not a loop
    settler.dispose();
  });

  it('Reverb absent: does ONE delayed re-fetch and never wires a listener or polls', async () => {
    vi.useFakeTimers();
    vi.mocked(subscribePrivate).mockReturnValue(null); // Echo not configured (e.g. local dev)
    apiMock.get.mockResolvedValueOnce({ data: session({ status: 'ready' }) });

    const settler = useSessionSettle({ reverbAbsentMs: 500 });
    const pending = settler.waitForSettle('s1');
    await vi.advanceTimersByTimeAsync(500); // the single delayed re-fetch

    const outcome = await pending;
    expect(outcome.settled).toBe(true);
    expect(apiMock.get).toHaveBeenCalledTimes(1); // one re-fetch — NOT a poll loop
    settler.dispose();
  });

  it('Reverb absent + still running after the delayed re-fetch resolves un-settled (no poll fallback)', async () => {
    vi.useFakeTimers();
    vi.mocked(subscribePrivate).mockReturnValue(null);
    apiMock.get.mockResolvedValue({ data: session({ status: 'generating' }) });

    const settler = useSessionSettle({ reverbAbsentMs: 500 });
    const pending = settler.waitForSettle('s1');
    await vi.advanceTimersByTimeAsync(500);

    const outcome = await pending;
    expect(outcome.settled).toBe(false);
    expect(apiMock.get).toHaveBeenCalledTimes(1); // still just one re-fetch — the spinner falls back to refresh
    settler.dispose();
  });
});
