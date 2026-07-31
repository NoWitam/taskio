// @vitest-environment happy-dom
// aiTextChipSummary.dom.spec — what the inline `@[ai-text]` chip SAYS about its block.
//
// Priority: AUTHOR → LEGACY TONE → prompt fragment → fallback. Knowledge labels dropped OUT of that
// chain along with their (now hidden) field, so a block that only carried labels shows its prompt
// fragment — an intentional behaviour change, pinned here.
//
// The chip must also never issue a request of its own (a document can hold many chips): it renders
// the stored `authorName` snapshot, plus the fresher directory entry when one already exists.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { defineComponent, nextTick } from 'vue';

vi.mock('../MarkdownEditor.vue', () => ({
  __esModule: true,
  default: defineComponent({ name: 'MarkdownEditorStub', template: '<div />' }),
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

import AiTextChip from '../extensions/AiTextChip.vue';
import { api } from '../../../app/lib/api';
import { useBotDirectoryStore } from '../../../app/stores/botDirectory';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { AiTextNodeAttrs } from '../extensions/types';

const apiMock = api as unknown as { get: ReturnType<typeof vi.fn> };

const PERSONAS = [{ id: 'friendly', label: 'Friendly' }];

// Author ids are real uuids: the botDirectory resolver refuses anything else WITHOUT a request
// (mirroring the backend's `Str::isUuid` filter), so the 404-means-gone path below only gets
// exercised with a well-formed id.
const BOT_ID = '3f2a1c64-9f1e-4a7b-8c3d-2b6e5f0a1d94';
const GONE_ID = 'c1e77a52-4d3b-4f10-9a86-71b0c9d2e345';

function attrs(overrides: Partial<AiTextNodeAttrs> = {}): AiTextNodeAttrs {
  return {
    id: 'ai_1',
    personaId: null,
    authorId: null,
    authorName: null,
    prompt: '',
    labels: [],
    ...overrides,
  };
}

function mountChip(state: AiTextNodeAttrs) {
  return mount(AiTextChip, {
    attachTo: document.body,
    props: {
      editor: {
        storage: {
          aiText: { personas: PERSONAS, labelsEnabled: false, labelsCatalog: [] },
        },
      } as never,
      node: { attrs: state },
      updateAttributes: () => {},
      deleteNode: () => {},
    },
  });
}

function chip(): HTMLElement {
  return document.body.querySelector('.next-ai-chip') as HTMLElement;
}

describe('AiTextChip — the block summary', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('shows the AUTHOR name first and carries the "bot" surface', async () => {
    const wrapper = mountChip(attrs({ authorId: BOT_ID, authorName: 'Marketing Maven', personaId: 'friendly', prompt: 'Write it.' }));
    await nextTick();

    expect(chip().textContent).toContain('Marketing Maven');
    expect(chip().textContent).not.toContain('Friendly');
    expect(chip().classList.contains('has-author')).toBe(true);
    expect(chip().getAttribute('aria-label')).toContain('author: Marketing Maven');

    wrapper.unmount();
  });

  it('truncates a long author name to 18 characters', async () => {
    const wrapper = mountChip(
      attrs({ authorId: BOT_ID, authorName: 'A very long bot name indeed' }),
    );
    await nextTick();
    expect(chip().textContent).toContain('A very long bot na…');
    wrapper.unmount();
  });

  it('falls back to the LEGACY TONE when there is no author', async () => {
    const wrapper = mountChip(attrs({ personaId: 'friendly', prompt: 'Write it.' }));
    await nextTick();

    expect(chip().textContent).toContain('Friendly');
    expect(chip().classList.contains('has-author')).toBe(false);
    expect(chip().getAttribute('aria-label')).toContain('legacy tone: Friendly');

    wrapper.unmount();
  });

  it('falls back to the PROMPT fragment with neither author nor tone — including a labels-only block', async () => {
    // labels used to win this slot; the field is hidden now, so the prompt speaks instead.
    const wrapper = mountChip(attrs({ labels: ['summary'], prompt: 'Write a friendly summary.' }));
    await nextTick();

    expect(chip().textContent).toContain('Write a friendly s…');
    expect(chip().textContent).not.toContain('summary]');
    expect(chip().getAttribute('aria-label')).toBe('AI text. Open editor.');

    wrapper.unmount();
  });

  it('falls back to a generic label when the block is completely empty', async () => {
    const wrapper = mountChip(attrs());
    await nextTick();
    expect(chip().textContent).toContain('AI text');
    wrapper.unmount();
  });

  it('a MISSING author keeps its name + the bot surface, and says so in the aria-label', async () => {
    const store = useBotDirectoryStore();
    apiMock.get.mockRejectedValue({ response: { status: 404 } });
    await store.resolve(GONE_ID);

    const wrapper = mountChip(attrs({ authorId: GONE_ID, authorName: 'Retired Bot' }));
    await nextTick();

    // Degraded, not broken: the surface stays, the name stays, a glyph + words add the warning.
    expect(chip().classList.contains('has-author')).toBe(true);
    expect(chip().textContent).toContain('Retired Bot');
    expect(chip().getAttribute('aria-label')).toContain('no longer exists');

    wrapper.unmount();
  });

  it('never fetches on its own — a chip only reads what the directory already knows', async () => {
    const wrapper = mountChip(attrs({ authorId: BOT_ID, authorName: 'Snapshot Name' }));
    await nextTick();
    await Promise.resolve();

    expect(apiMock.get).not.toHaveBeenCalled();
    expect(chip().textContent).toContain('Snapshot Name');

    wrapper.unmount();
  });

  it('prefers the RESOLVED name over the stored snapshot', async () => {
    const store = useBotDirectoryStore();
    store.prime({ id: BOT_ID, name: 'Renamed Bot', status: 'active' });

    const wrapper = mountChip(attrs({ authorId: BOT_ID, authorName: 'Stale Name' }));
    await nextTick();

    expect(chip().textContent).toContain('Renamed Bot');
    expect(chip().textContent).not.toContain('Stale Name');

    wrapper.unmount();
  });
});
