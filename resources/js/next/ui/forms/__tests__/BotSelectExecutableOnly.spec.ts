// @vitest-environment happy-dom
// BotSelectExecutableOnly.spec.ts — the `executableOnly` opt-in. A bot that cannot
// execute tasks is refused server-side as a task assignee, so the pickers that choose
// an EXECUTOR must not offer it: the loader asks `/bots` for `can_execute_tasks=1`, and
// an empty result says WHY (no bot has the module on) instead of a bare "no results".
// Default (no prop) must keep the plain `/bots` request every other consumer relies on.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const get = vi.fn();

vi.mock('../../../app/lib/api', () => ({
  api: { get: (...args: unknown[]) => get(...args) },
}));

import BotSelect from '../BotSelect.vue';

/** Mount, open the dropdown and let the async loader settle. */
async function openPicker(props: Record<string, unknown>) {
  const wrapper = mount(BotSelect, { attachTo: document.body, props });
  await wrapper.find('[role="combobox"]').trigger('click');
  await nextTick();
  await Promise.resolve();
  await nextTick();

  return wrapper;
}

describe('BotSelect executableOnly', () => {
  beforeEach(() => {
    installBrowserMocks();
    get.mockReset();
    get.mockResolvedValue({ data: [], meta: { next_cursor: null } });
  });
  afterEach(() => restoreBrowserMocks());

  it('asks the backend for task-executing bots only', async () => {
    await openPicker({ executableOnly: true });

    expect(get).toHaveBeenCalled();
    expect(String(get.mock.calls[0][0])).toContain('can_execute_tasks=1');
  });

  it('does NOT narrow the list by default', async () => {
    await openPicker({});

    expect(get).toHaveBeenCalled();
    expect(String(get.mock.calls[0][0])).not.toContain('can_execute_tasks');
  });

  it('explains an empty executable list instead of showing a bare "no results"', async () => {
    await openPicker({ executableOnly: true });

    expect(document.body.textContent).toContain('module');
    expect(document.body.textContent).not.toBe('No results');
  });
});
