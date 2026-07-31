// @vitest-environment happy-dom
// useAiImageJob.spec — the SHARED wait for a queued AI image job (`/disk/ai/image/{id}`), extracted from
// the Disk editor so the bot's visual module rides the same machinery.
//
// The behaviours worth pinning are the ones a caller depends on and a refactor could silently drop:
//   • `keepImage` DEFAULTS FALSE — a `done` outcome must NOT carry the multi-megabyte base64 image;
//   • with `keepImage` the image IS returned (the Disk editor commits it as the new canvas base);
//   • a `failed` job resolves as an OUTCOME (not a rejection) and surfaces `error` + `error_code`, which
//     is how "the provider's moderation refused this" is told apart from "the provider broke";
//   • `cancel()` stops the wait (the queued job keeps running server-side);
//   • the deadline gives up rather than polling forever.
// Reverb is not configured in the test env, so every case exercises the HTTP-poll fallback.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('../../lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn() },
  WORKSPACE_KEY: 'taskio_workspace',
}));

import { api } from '../../lib/api';
import { useAiImageJob, AI_IMAGE_JOB_DEADLINE } from '../useAiImageJob';

const apiMock = api as unknown as { get: ReturnType<typeof vi.fn> };

/** A big-ish stand-in for the real payload — the point is that it must not ride an outcome by default. */
const IMAGE_B64 = 'QUJD'.repeat(64);

describe('useAiImageJob', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.useFakeTimers();
    // No workspace id → no Reverb channel → the HTTP-poll path (the deterministic one).
    localStorage.clear();
  });
  afterEach(() => vi.useRealTimers());

  it('polls until done and, by DEFAULT, does not carry the image', async () => {
    apiMock.get
      .mockResolvedValueOnce({ data: { status: 'processing' } })
      .mockResolvedValueOnce({ data: { status: 'done', image: IMAGE_B64 } });

    const job = useAiImageJob();
    const promise = job.start('job-1');
    expect(job.status.value).toBe('queued');

    await vi.runAllTimersAsync();
    const outcome = await promise;

    expect(apiMock.get).toHaveBeenCalledWith('/disk/ai/image/job-1');
    expect(outcome.status).toBe('done');
    // THE POINT: no image unless asked for.
    expect(outcome.image).toBeUndefined();
    expect(job.status.value).toBe('done');
    expect(job.busy.value).toBe(false);
  });

  it('returns the image when the caller opts in with keepImage', async () => {
    apiMock.get.mockResolvedValue({ data: { status: 'done', image: IMAGE_B64 } });

    const job = useAiImageJob();
    const promise = job.start('job-2', { keepImage: true });
    await vi.runAllTimersAsync();

    expect((await promise).image).toBe(IMAGE_B64);
  });

  it('keeps waiting on a done WITHOUT an image when the caller needs the bytes', async () => {
    // The row can flip to done a beat before the blob is readable; a caller that wants the bytes must
    // not settle on that. (The Disk editor has always relied on this.)
    apiMock.get
      .mockResolvedValueOnce({ data: { status: 'done' } })
      .mockResolvedValueOnce({ data: { status: 'done', image: IMAGE_B64 } });

    const job = useAiImageJob();
    const promise = job.start('job-3', { keepImage: true });
    await vi.runAllTimersAsync();

    expect((await promise).image).toBe(IMAGE_B64);
    expect(apiMock.get).toHaveBeenCalledTimes(2);
  });

  it('reports a failed job as an OUTCOME carrying the message AND the machine-readable code', async () => {
    apiMock.get.mockResolvedValue({
      data: { status: 'failed', error: 'Refused.', error_code: 'safety_rejected' },
    });

    const job = useAiImageJob();
    const promise = job.start('job-4');
    await vi.runAllTimersAsync();
    const outcome = await promise;

    expect(outcome).toMatchObject({
      status: 'failed',
      error: 'Refused.',
      errorCode: 'safety_rejected',
      reason: 'failed',
    });
    expect(job.status.value).toBe('failed');
    expect(job.errorCode.value).toBe('safety_rejected');
  });

  it('tracks `processing` so a caller can say more than "queued"', async () => {
    apiMock.get
      .mockResolvedValueOnce({ data: { status: 'processing' } })
      .mockResolvedValue({ data: { status: 'done' } });

    const job = useAiImageJob();
    const promise = job.start('job-5');

    await vi.advanceTimersByTimeAsync(2_100);
    expect(job.status.value).toBe('processing');

    await vi.runAllTimersAsync();
    await promise;
  });

  it('cancel() stops the wait and leaves the composable idle (the job keeps running server-side)', async () => {
    apiMock.get.mockResolvedValue({ data: { status: 'processing' } });

    const job = useAiImageJob();
    const promise = job.start('job-6');
    await vi.advanceTimersByTimeAsync(2_100);

    job.cancel();
    await vi.advanceTimersByTimeAsync(2_100);
    const outcome = await promise;

    expect(outcome.reason).toBe('cancelled');
    expect(job.status.value).toBe('idle');
  });

  it('gives up at the deadline instead of polling forever', async () => {
    apiMock.get.mockResolvedValue({ data: { status: 'processing' } });

    const job = useAiImageJob();
    const promise = job.start('job-7');
    await vi.advanceTimersByTimeAsync(AI_IMAGE_JOB_DEADLINE + 5_000);
    const outcome = await promise;

    expect(outcome).toMatchObject({ status: 'failed', reason: 'timeout' });
  });

  it('propagates a transport error so callers can read the server message off it', async () => {
    apiMock.get.mockRejectedValue({ response: { status: 500, data: { message: 'boom' } } });

    const job = useAiImageJob();
    const promise = job.start('job-8');
    const assertion = expect(promise).rejects.toMatchObject({ response: { status: 500 } });
    await vi.runAllTimersAsync();
    await assertion;
    expect(job.status.value).toBe('failed');
  });
});
