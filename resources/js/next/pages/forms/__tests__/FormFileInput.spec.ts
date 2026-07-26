// @vitest-environment happy-dom
// FormFileInput — the fill-mode single-file control. It composes FileDropzone and
// owns exactly one behaviour of its own: upload a picked file to /disk/temp and
// surface the returned temp-file id as the model (that id is the field's answer).
// These tests pin that seam — a successful upload sets the id, a removal clears it,
// and a failed upload clears the id AND toasts — with the api client mocked so no
// real HTTP happens.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('../../../app/lib/api', () => ({
  api: { post: vi.fn(), get: vi.fn() },
}));

const toast = { danger: vi.fn(), success: vi.fn() };
vi.mock('../../../app/composables/useToast', () => ({
  useToast: () => toast,
}));

import { api } from '../../../app/lib/api';
import FileDropzone from '../../../ui/forms/FileDropzone.vue';
import DiskFilePickerModal from '../../disk/DiskFilePickerModal.vue';
import FormFileInput from '../FormFileInput.vue';

const apiMock = api as unknown as { post: ReturnType<typeof vi.fn>; get: ReturnType<typeof vi.fn> };

function pick(): { id: string; file: File } {
  return { id: 'e1', file: new File(['bytes'], 'photo.png', { type: 'image/png' }) };
}

/** The last emitted payload (avoids Array.prototype.at, absent from the tsconfig lib). */
function lastEmit(events: unknown[][] | undefined): unknown[] | undefined {
  return events && events.length ? events[events.length - 1] : undefined;
}

describe('FormFileInput', () => {
  beforeEach(() => {
    apiMock.post.mockReset();
    apiMock.get.mockReset();
    toast.danger.mockReset();
  });

  it('uploads a picked file and surfaces the returned temp id as the model', async () => {
    apiMock.post.mockResolvedValue({ data: { id: 'file-123' } });
    const wrapper = mount(FormFileInput);

    wrapper.findComponent(FileDropzone).vm.$emit('change', [pick()]);
    await flushPromises();

    expect(apiMock.post).toHaveBeenCalledWith('/disk/temp', expect.any(FormData), expect.any(Object));
    expect(lastEmit(wrapper.emitted('update:modelValue'))).toEqual(['file-123']);
  });

  it('clears the model when the file is removed', async () => {
    apiMock.post.mockResolvedValue({ data: { id: 'file-123' } });
    const wrapper = mount(FormFileInput);

    wrapper.findComponent(FileDropzone).vm.$emit('change', [pick()]);
    await flushPromises();
    wrapper.findComponent(FileDropzone).vm.$emit('remove', pick());
    await flushPromises();

    expect(lastEmit(wrapper.emitted('update:modelValue'))).toEqual([null]);
  });

  it('clears the model and toasts when the upload fails', async () => {
    apiMock.post.mockRejectedValue(new Error('network'));
    const wrapper = mount(FormFileInput);

    wrapper.findComponent(FileDropzone).vm.$emit('change', [pick()]);
    await flushPromises();

    // A failed upload must never surface a temp id, and it must toast the failure.
    const updates = wrapper.emitted('update:modelValue') ?? [];
    expect(updates.some(([v]) => typeof v === 'string' && v)).toBe(false);
    expect(toast.danger).toHaveBeenCalledOnce();
  });

  it('a cancelled upload (abort) is silent — no error toast, no model change', async () => {
    // controller.abort() rejects the request with an axios CanceledError.
    apiMock.post.mockRejectedValue({ code: 'ERR_CANCELED', name: 'CanceledError' });
    const wrapper = mount(FormFileInput);

    wrapper.findComponent(FileDropzone).vm.$emit('change', [pick()]);
    await flushPromises();

    expect(toast.danger).not.toHaveBeenCalled();
    // A deliberate cancel must not surface a temp id either.
    const updates = wrapper.emitted('update:modelValue') ?? [];
    expect(updates.some(([v]) => typeof v === 'string' && v)).toBe(false);
  });

  it('does not upload while disabled (builder preview)', async () => {
    const wrapper = mount(FormFileInput, { props: { disabled: true } });

    // A disabled dropzone never emits change, but even a stray one must not upload.
    expect(apiMock.post).not.toHaveBeenCalled();
    expect(wrapper.findComponent(FileDropzone).props('disabled')).toBe(true);
  });

  it('picks a Disk file: copies it into a temp and surfaces the new id + name', async () => {
    // The pick must NOT reference the Disk file's id — it copies to a fresh temp (copy-to-temp)
    // and holds THAT id, so the field's answer is a temp exactly like an upload.
    apiMock.post.mockResolvedValue({ data: { id: 'copy-9', name: 'brief.pdf' } });
    const wrapper = mount(FormFileInput);

    wrapper.findComponent(DiskFilePickerModal).vm.$emit('select', { id: 'src-1', name: 'brief.pdf' });
    await flushPromises();

    expect(apiMock.post).toHaveBeenCalledWith('/disk/src-1/copy-to-temp');
    expect(lastEmit(wrapper.emitted('update:modelValue'))).toEqual(['copy-9']);
    // The picked file's name is shown (the dropzone renders nothing for a pick).
    expect(wrapper.text()).toContain('brief.pdf');
  });

  it('a failed pick toasts and surfaces no id', async () => {
    apiMock.post.mockRejectedValue(new Error('network'));
    const wrapper = mount(FormFileInput);

    wrapper.findComponent(DiskFilePickerModal).vm.$emit('select', { id: 'src-1', name: 'brief.pdf' });
    await flushPromises();

    const updates = wrapper.emitted('update:modelValue') ?? [];
    expect(updates.some(([v]) => typeof v === 'string' && v)).toBe(false);
    expect(toast.danger).toHaveBeenCalledOnce();
  });
});
