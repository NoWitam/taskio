// @vitest-environment happy-dom
// TemplateScenePlanPart.spec — LEAN scene authoring. Asserts adding a scene emits an ordered
// `{scenes:[{narration:{markdown}}]}` and that a scene's OPTIONAL image is opt-in (a toggle seeds the
// nested image plan). The nested body + image parts are stubbed.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import TemplateScenePlanPart from '../TemplateScenePlanPart.vue';
import Switch from '../../../ui/forms/Switch.vue';
import type { ScenePlanContent } from '../types';

const stubs = {
  TemplateBodyPart: { name: 'TemplateBodyPart', props: ['modelValue'], template: '<div class="body-stub" />' },
  TemplateImagePlanPart: { name: 'TemplateImagePlanPart', props: ['modelValue'], template: '<div class="image-stub" />' },
};

const variables = { variables: [], operationsCatalog: [], source: () => [], catalog: () => [] };

function mountPart(modelValue: ScenePlanContent | null) {
  return mount(TemplateScenePlanPart, {
    props: { modelValue, variables, aiText: { personas: [] }, fileSlots: [] },
    global: { stubs },
    attachTo: document.body,
  });
}

function lastEmit(wrapper: ReturnType<typeof mountPart>): ScenePlanContent {
  const events = wrapper.emitted('update:modelValue') as unknown[][] | undefined;
  return events![events!.length - 1][0] as ScenePlanContent;
}

describe('TemplateScenePlanPart', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('starts empty and adds a scene with an empty narration body', async () => {
    const wrapper = mountPart({ scenes: [] });
    expect(wrapper.text()).toContain('No scenes yet');

    await wrapper.findAll('button').find((b) => b.text().includes('Add scene'))!.trigger('click');
    expect(lastEmit(wrapper)).toEqual({ scenes: [{ narration: { markdown: '' } }] });

    wrapper.unmount();
  });

  it('opts a scene into an image plan via the toggle', async () => {
    const wrapper = mountPart({ scenes: [{ narration: { markdown: 'Hi' } }] });

    wrapper.findComponent(Switch).vm.$emit('update:modelValue', true);
    await nextTick();

    const plan = lastEmit(wrapper);
    expect(plan.scenes[0].image_plan).toEqual({ base: null, filters: [] });

    wrapper.unmount();
  });
});
