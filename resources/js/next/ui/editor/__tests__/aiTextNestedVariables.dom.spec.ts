// @vitest-environment happy-dom
// aiTextNestedVariables.dom.spec — REGRESSION for the "@[ai-text] prompt shows only globals" bug.
//
// The `@[ai-text]` block edits its prompt in a NESTED MarkdownEditor. Its variable feature must be the
// host's LIVE feed — the same one the parent body editor offers — so the prompt's `{` suggestion lists
// the template's declared SLOTS, not just the workspace globals. Before the fix, `AiTextChip` sourced the
// nested config from `editor.storage.variable.definitions` (the FROZEN mount-time array), so a template —
// whose slots arrive live via `source()` while the frozen array holds only globals — showed only globals
// in the prompt. The fix forwards the storage's `getSource`/`getDefinitions`/`getCatalog` getters, exactly
// like `VariableChip` / `IfBranchView`. These assertions fail against the pre-fix (frozen) read.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { nextTick, ref } from 'vue';
import AiTextChip from '../extensions/AiTextChip.vue';
import AiTextPanel from '../extensions/AiTextPanel.vue';
import { createVariable } from '../extensions/variable';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { VariableSourceVar } from '../../variables/types';
import type { VariableFeatureConfig, VariableStorage } from '../extensions/types';

/** The only variable present when the editor is BUILT — the workspace global. */
const GLOBAL_VAR = { id: 'globals.brand', name: 'Brand', type: 'text' as const };
/** A template SLOT — arrives via the LIVE source(), like the async catalog does in the real app. */
const SLOT_VAR: VariableSourceVar = {
  source: 'slots',
  path: 'slots.produkt',
  name: 'Produkt',
  type: 'text',
  descriptor: { base: 'text', nullable: false, array: false },
};

/** A fake Tiptap editor exposing a REAL variable storage (frozen = the global; live source = the slots). */
function fakeEditor(source: () => VariableSourceVar[]): never {
  const node = createVariable({ variables: [GLOBAL_VAR], operationsCatalog: [], source });
  const variable = node.config.addStorage!.call({} as never) as VariableStorage;
  return {
    storage: { variable, aiText: { personas: [], labelsEnabled: false, labelsCatalog: [] } },
  } as never;
}

function mountChip(source: () => VariableSourceVar[]) {
  return mount(AiTextChip, {
    attachTo: document.body,
    props: {
      editor: fakeEditor(source),
      node: {
        attrs: {
          id: 'ai1',
          personaId: null,
          authorId: null,
          authorName: null,
          prompt: '',
          labels: [],
        },
      },
      updateAttributes: () => {},
      deleteNode: () => {},
    },
  });
}

describe('the @[ai-text] nested prompt editor gets the LIVE variable feed (slots), not the frozen array', () => {
  beforeEach(() => {
    // The panel resolves its author through the `botDirectory` Pinia store.
    setActivePinia(createPinia());
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('forwards the host source() so a template slot reaches the prompt though the frozen array is globals-only', async () => {
    const source = ref<VariableSourceVar[]>([SLOT_VAR]);
    const wrapper = mountChip(() => source.value);
    await nextTick();

    const variables = wrapper.findComponent(AiTextPanel).props('variables') as VariableFeatureConfig;
    // The LIVE source (the slot) is forwarded — not only the frozen global.
    expect(variables.source?.()).toEqual([SLOT_VAR]);
    // getDefinitions-derived list resolves to the slot (live wins over the frozen global).
    expect(variables.variables.map((v) => v.id)).toEqual(['slots.produkt']);

    wrapper.unmount();
  });

  it('a slot that arrives AFTER the chip mounted still reaches the prompt (live getter, not a snapshot)', async () => {
    const source = ref<VariableSourceVar[]>([]); // empty at mount — the async-catalog race
    const wrapper = mountChip(() => source.value);
    await nextTick();

    const variables = wrapper.findComponent(AiTextPanel).props('variables') as VariableFeatureConfig;
    expect(variables.source?.()).toEqual([]);

    // The catalog resolves later — the SAME forwarded getter now returns the slot.
    source.value = [SLOT_VAR];
    expect(variables.source?.()).toEqual([SLOT_VAR]);

    wrapper.unmount();
  });
});
