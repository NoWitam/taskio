// @vitest-environment happy-dom
// FormPreviewView.spec — the Forms sub-view header contract. The three per-form
// sub-views (Preview / Submissions / Reports) share one header shape: a
// full-size PageHeader (uniform scale app-wide) whose h1 is the SECTION label
// (the form's identity lives in the module aside's selected block + the Navbar
// breadcrumb), with the sub-view's actions in the #actions cluster. Preview is
// the lightest host, so it pins the contract. FormViewer is stubbed; the REAL
// i18n renders the copy.
import { describe, it, expect, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { ref } from 'vue';
import FormPreviewView from '../FormPreviewView.vue';
import { FORM_MODULE_CTX } from '../formContext';
import { en } from '../../../app/i18n/en';
import type { FormDetail } from '../types';

const routerPush = vi.fn();
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push: routerPush }),
}));

function mountPreview(form: Partial<FormDetail> | null) {
  return mount(FormPreviewView, {
    global: {
      stubs: { FormViewer: true },
      provide: {
        [FORM_MODULE_CTX as symbol]: {
          form: ref(form),
          loading: ref(false),
        },
      },
    },
  });
}

afterEach(() => {
  document.body.innerHTML = '';
  routerPush.mockReset();
});

describe('FormPreviewView — sub-view PageHeader (Batch 4+6)', () => {
  it('renders a single h1 with the SECTION label at the uniform full header scale', () => {
    const wrapper = mountPreview({
      id: 'f-1',
      name: 'Contact form',
      can_be_edited: true,
      can_be_filled: true,
      content: [],
    });

    const headings = wrapper.findAll('h1');
    expect(headings).toHaveLength(1);
    expect(headings[0].text()).toBe(en.forms.preview);
    // Default (md) scale — the same header size as every other page.
    expect(headings[0].classes()).toContain('text-next-2xl');
    expect(headings[0].classes()).not.toContain('text-next-xl');
    // The form name is NOT the page title here (it lives in the aside/breadcrumb).
    expect(headings[0].text()).not.toContain('Contact form');
  });

  it('keeps the sub-view actions in the header actions cluster', async () => {
    const wrapper = mountPreview({
      id: 'f-1',
      name: 'Contact form',
      can_be_edited: true,
      can_be_filled: true,
      content: [],
    });

    expect(wrapper.text()).toContain(en.forms.actions.edit);
    expect(wrapper.text()).toContain(en.forms.actions.fill);

    const fill = wrapper.findAll('button').find((b) => b.text().includes(en.forms.actions.fill))!;
    await fill.trigger('click');
    expect(routerPush).toHaveBeenCalledWith({ query: { fill: 'f-1' } });
  });
});
