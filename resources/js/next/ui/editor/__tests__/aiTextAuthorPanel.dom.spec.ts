// @vitest-environment happy-dom
// aiTextAuthorPanel.dom.spec — the `@[ai-text]` edit panel after the per-block AUTHOR landed.
//
// What this pins:
//   • ESC DISCIPLINE (the most fragile thing here): the author list lives in a popover inside a
//     Modal inside a Drawer. The FIRST Escape must close only the LIST — closing the modal would
//     throw away every unsaved edit in the panel.
//   • the two EMPTY states must read differently: "this workspace has no bots" is an invitation to
//     create one; "your search matched nothing" is an invitation to clear the query. Select alone
//     cannot tell them apart, which is why it grew an `#empty` slot.
//   • a 404 on the author lookup means GONE (say so, offer to clear); a network failure means we
//     could NOT CHECK (stay neutral, offer a retry). Never conflate the two.
//   • an INACTIVE author is informational, never a blocker — delegation does not filter by status
//     either, so filtering here would lie about the system.
//   • clearing the legacy tone is LOCAL until Save, exactly like every other field in this panel.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { defineComponent, nextTick } from 'vue';

// The nested prompt editor is irrelevant here (and expensive) — stub the async import.
// `__esModule` matters: without it Vue's defineAsyncComponent treats the whole module
// namespace as the component instead of unwrapping `default`.
vi.mock('../MarkdownEditor.vue', () => ({
  __esModule: true,
  default: defineComponent({
    name: 'MarkdownEditorStub',
    props: ['modelValue'],
    template: '<div data-stub-editor />',
  }),
}));

// Partial mock: the botDirectory store reads the active workspace from the auth store, which imports
// more than `api` from this module (WORKSPACE_KEY) — keep the real exports, stub only the HTTP surface.
vi.mock('../../../app/lib/api', async (importOriginal) => {
  const actual = await importOriginal<Record<string, unknown>>();
  return {
    ...actual,
    api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
  };
});

import AiTextPanel from '../extensions/AiTextPanel.vue';
import { api } from '../../../app/lib/api';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { AiTextNodeAttrs } from '../extensions/types';

const apiMock = api as unknown as { get: ReturnType<typeof vi.fn> };

// Author ids are real uuids: the botDirectory resolver refuses anything else WITHOUT a request
// (mirroring the backend's `Str::isUuid` filter), so a placeholder id could never resolve here.
const BOT_ID = '3f2a1c64-9f1e-4a7b-8c3d-2b6e5f0a1d94';
const GONE_ID = 'c1e77a52-4d3b-4f10-9a86-71b0c9d2e345';

const PERSONAS = [
  { id: 'neutral', label: 'Neutral' },
  { id: 'friendly', label: 'Friendly' },
];

function attrs(overrides: Partial<AiTextNodeAttrs> = {}): AiTextNodeAttrs {
  return {
    id: 'ai_1',
    personaId: null,
    authorId: null,
    authorName: null,
    prompt: 'Write something.',
    labels: [],
    ...overrides,
  };
}

/** Default: an empty bot list + no bot resolves (each test overrides what it needs). */
function stubApi(): void {
  apiMock.get.mockImplementation((url: string) => {
    if (url.startsWith('/bots/')) return Promise.reject({ response: { status: 404 } });
    return Promise.resolve({ data: [], meta: { next_cursor: null } });
  });
}

/**
 * Mount CLOSED, then open — exactly how the chip drives the panel. (Modal registers with the
 * overlay stack from a non-immediate `watch(open)`, so a modal mounted already-open never
 * registers and would silently not react to Escape.)
 */
function mountPanel(state: AiTextNodeAttrs = attrs()) {
  const wrapper = mount(AiTextPanel, {
    attachTo: document.body,
    props: {
      open: false,
      state,
      personas: PERSONAS,
      labelsEnabled: false,
      labelsCatalog: [],
    },
  });
  void wrapper.setProps({ open: true });
  return wrapper;
}

/** Settle the async chain: BotSelect's fetch, the directory lookup, and re-renders. */
async function settle(times = 4): Promise<void> {
  for (let i = 0; i < times; i += 1) {
    await Promise.resolve();
    await nextTick();
  }
}

function buttonByText(text: string): HTMLButtonElement | undefined {
  return Array.from(document.body.querySelectorAll('button')).find(
    (b) => b.textContent?.trim() === text,
  ) as HTMLButtonElement | undefined;
}

/** The author combobox (the panel's only one). */
function combobox(): HTMLElement {
  return document.body.querySelector('[role="combobox"]') as HTMLElement;
}

async function openAuthorList(): Promise<void> {
  combobox().click();
  await settle();
}

/** The popover is open iff the combobox says so (with 0 options there is no listbox). */
function listOpen(): boolean {
  return combobox().getAttribute('aria-expanded') === 'true';
}

function pressEscape(target: HTMLElement): void {
  target.dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }),
  );
}

describe('AiTextPanel — the per-block author', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
    stubApi();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('shows the "no author" placeholder — an author-less block is CORRECT, not an error', async () => {
    const wrapper = mountPanel();
    await settle();

    expect(document.body.textContent).toContain('No author (neutral tone)');
    // Nothing alarming: an absent author is a valid configuration.
    expect(document.body.querySelector('[role="alert"]')).toBeNull();
    // The retired fields are gone from the UI.
    expect(document.body.textContent).not.toContain('Persona');
    expect(document.body.textContent).not.toContain('Knowledge labels');

    wrapper.unmount();
  });

  it('the FIRST Escape closes the author list, NOT the panel (unsaved work must survive)', async () => {
    apiMock.get.mockImplementation((url: string) => {
      if (url.startsWith('/bots/')) return Promise.reject({ response: { status: 404 } });
      return Promise.resolve({
        data: [{ id: BOT_ID, name: 'Marketing Maven', status: 'active' }],
        meta: { next_cursor: null },
      });
    });
    const wrapper = mountPanel();
    await settle();
    await openAuthorList();
    expect(listOpen()).toBe(true);

    // Escape from inside the open list (the search box has focus in a searchable Select).
    const search =
      (document.body.querySelector('[role="searchbox"]') as HTMLElement) ?? combobox();
    pressEscape(search);
    await settle();

    expect(listOpen()).toBe(false);
    // The panel is still open — no `update:open` false was emitted.
    expect((wrapper.emitted('update:open') ?? []).map((e) => e[0])).not.toContain(false);

    // The SECOND Escape then closes the panel.
    pressEscape(combobox());
    await settle();
    expect((wrapper.emitted('update:open') ?? []).map((e) => e[0])).toContain(false);

    wrapper.unmount();
  });

  it('an EMPTY workspace and a fruitless SEARCH read differently', async () => {
    const wrapper = mountPanel();
    await settle();
    await openAuthorList();

    // No bots at all → an invitation to create one, opening Bots in a NEW TAB (this panel
    // is a Modal inside a Drawer; navigating in place would discard unsaved work).
    expect(document.body.textContent).toContain('No bots in this workspace');
    const link = document.body.querySelector('a[href="/next/bots"]') as HTMLAnchorElement;
    expect(link).not.toBeNull();
    expect(link.getAttribute('target')).toBe('_blank');
    expect(document.body.textContent).not.toContain('No bots match');

    // Now type a query → different copy, naming the query, with a clear-search action.
    const search = document.body.querySelector('[role="searchbox"]') as HTMLInputElement;
    search.value = 'zzz';
    search.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();

    expect(document.body.textContent).toContain('No bots match');
    expect(document.body.textContent).toContain('zzz');
    expect(document.body.textContent).not.toContain('No bots in this workspace');
    expect(buttonByText('Clear search')).toBeTruthy();

    wrapper.unmount();
  });

  it('a 404 on the author lookup reads as GONE, and offers to clear it', async () => {
    apiMock.get.mockImplementation((url: string) => {
      if (url.startsWith('/bots/')) return Promise.reject({ response: { status: 404 } });
      return Promise.resolve({ data: [], meta: { next_cursor: null } });
    });
    const wrapper = mountPanel(attrs({ authorId: GONE_ID, authorName: 'Retired Bot' }));
    await settle();

    expect(document.body.textContent).toContain('This author no longer exists');
    expect(document.body.textContent).toContain('Author unavailable');
    // The stored snapshot still NAMES the author — never a bare id, never struck through.
    expect(document.body.textContent).toContain('Retired Bot');
    expect(document.body.textContent).not.toContain('We couldn’t check this author');

    // Clearing is local; saving commits it.
    buttonByText('Clear author')!.click();
    await settle();
    buttonByText('Save')!.click();
    await settle();
    const saved = wrapper.emitted('save')?.[0]?.[0] as AiTextNodeAttrs;
    expect(saved.authorId).toBeNull();
    expect(saved.authorName).toBeNull();

    wrapper.unmount();
  });

  it('a NETWORK failure reads as "couldn’t check", never as deleted', async () => {
    apiMock.get.mockImplementation((url: string) => {
      if (url.startsWith('/bots/')) return Promise.reject(new Error('Network Error'));
      return Promise.resolve({ data: [], meta: { next_cursor: null } });
    });
    const wrapper = mountPanel(attrs({ authorId: BOT_ID, authorName: 'Marketing Maven' }));
    await settle();

    expect(document.body.textContent).toContain('We couldn’t check this author');
    expect(document.body.textContent).toContain('Try again');
    expect(document.body.textContent).not.toContain('This author no longer exists');
    expect(document.body.textContent).not.toContain('Author unavailable');
    expect(document.body.textContent).toContain('Marketing Maven');

    wrapper.unmount();
  });

  it('an INACTIVE author is explained, never blocked', async () => {
    apiMock.get.mockImplementation((url: string) => {
      if (url.startsWith('/bots/')) {
        return Promise.resolve({ data: { id: BOT_ID, name: 'Sleepy Sam', status: 'inactive' } });
      }
      return Promise.resolve({ data: [], meta: { next_cursor: null } });
    });
    const wrapper = mountPanel(attrs({ authorId: BOT_ID, authorName: 'Sleepy Sam' }));
    await settle();

    expect(document.body.textContent).toContain('This bot is inactive');
    expect(document.body.textContent).toContain('Its voice will still be used');
    // Not an error state.
    expect(document.body.textContent).not.toContain('no longer exists');

    wrapper.unmount();
  });

  it('a legacy tone renders as a read-only bar; clearing it lands only on Save', async () => {
    const wrapper = mountPanel(attrs({ personaId: 'friendly' }));
    await settle();

    expect(document.body.textContent).toContain('Legacy tone');
    expect(document.body.textContent).toContain('Friendly');
    // Read-only: there is no tone picker anymore, only the panel's single (author) combobox.
    expect(document.body.querySelectorAll('[role="combobox"]').length).toBe(1);

    buttonByText('Clear tone')!.click();
    await settle();

    // Nothing committed yet — the effect lands on Save, like every other field here.
    expect(wrapper.emitted('save')).toBeUndefined();
    expect(document.body.textContent).toContain('Tone cleared');

    buttonByText('Save')!.click();
    await settle();
    expect((wrapper.emitted('save')?.[0]?.[0] as AiTextNodeAttrs).personaId).toBeNull();

    wrapper.unmount();
  });

  it('Cancel discards a cleared tone (no save is emitted at all)', async () => {
    const wrapper = mountPanel(attrs({ personaId: 'friendly' }));
    await settle();

    buttonByText('Clear tone')!.click();
    await settle();
    buttonByText('Cancel')!.click();
    await settle();

    expect(wrapper.emitted('save')).toBeUndefined();
    wrapper.unmount();
  });

  it('with BOTH an author and a legacy tone, it states which one wins', async () => {
    apiMock.get.mockImplementation((url: string) => {
      if (url.startsWith('/bots/')) {
        return Promise.resolve({ data: { id: BOT_ID, name: 'Marketing Maven', status: 'active' } });
      }
      return Promise.resolve({ data: [], meta: { next_cursor: null } });
    });
    const wrapper = mountPanel(
      attrs({ personaId: 'neutral', authorId: BOT_ID, authorName: 'Marketing Maven' }),
    );
    await settle();

    expect(document.body.textContent).toContain('The author takes precedence over the legacy tone');
    wrapper.unmount();
  });

  it('re-emits `labels` UNCHANGED although the field is no longer rendered', async () => {
    const wrapper = mountPanel(attrs({ labels: ['summary', 'brand'] }));
    await settle();

    buttonByText('Save')!.click();
    await settle();

    expect((wrapper.emitted('save')?.[0]?.[0] as AiTextNodeAttrs).labels).toEqual([
      'summary',
      'brand',
    ]);
    wrapper.unmount();
  });
});
