// @vitest-environment happy-dom
// InputRenderer — value binding for select fields (regression).
//
// REGRESSION: a REQUIRED `multiple` select showed its chosen chip ("IT ×") but still
// failed validation with "Pole <label> jest wymagane". Root cause: `Select` splits its
// model into `modelValue` (single) and a NAMED `values` model (multiple), but the
// multiple branch here bound the DEFAULT `v-model`, so the selection never reached
// `formData[id]` (it stayed the seeded empty array). These pin that the multiple branch
// binds the `values` model, and the single branch still binds the default one.
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import InputRenderer from '../InputRenderer.vue';
import Select from '../../../ui/forms/Select.vue';
import type { FormElement } from '../types';

function mountField(element: FormElement, formData: Record<string, unknown>) {
  return mount(InputRenderer, {
    props: { element, mode: 'fill', formData, repeaterInstances: {}, getError: () => undefined },
  });
}

describe('InputRenderer — select value binding', () => {
  it('routes a MULTIPLE select selection into formData[id] as an array (via the `values` model)', async () => {
    const formData = reactive<Record<string, unknown>>({});
    const element = {
      id: 'dept',
      type: 'select',
      config: { label: 'Dział', required: true, multiple: true, options: [{ value: 'it', label: 'IT' }] },
    } as unknown as FormElement;

    const wrapper = mountField(element, formData);
    // Seeded to an empty array by InputRenderer; before the fix it stayed [] forever.
    expect(formData.dept).toEqual([]);

    // The multiple Select publishes its selection through the NAMED `values` model.
    await wrapper.findComponent(Select).vm.$emit('update:values', ['it']);

    expect(formData.dept).toEqual(['it']);
  });

  it('routes a SINGLE select selection into formData[id] as a string (default model, unchanged)', async () => {
    const formData = reactive<Record<string, unknown>>({});
    const element = {
      id: 'mode',
      type: 'select',
      config: { label: 'Tryb pracy', required: true, multiple: false, options: [{ value: 'hybrid', label: 'Hybryda' }] },
    } as unknown as FormElement;

    const wrapper = mountField(element, formData);
    await wrapper.findComponent(Select).vm.$emit('update:modelValue', 'hybrid');

    expect(formData.mode).toBe('hybrid');
  });
});
