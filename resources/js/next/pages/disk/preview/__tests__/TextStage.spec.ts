// @vitest-environment happy-dom
// TextStage — the text file editor stage: loads the content via a blob fetch, tracks `dirty`
// against the loaded snapshot, and emits save/save-as payloads (the shell owns the calls). Plus the
// AI actions panel: presets + a free prompt call the store's aiEditText (mocked here) and REPLACE
// the buffer, single-flight `aiBusy` disables the controls, and a one-step Undo restores the pre-AI
// content.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale } from '../../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../../__tests__/helpers/dom';

vi.mock('../../../../app/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn() },
}));

// The AI call + the autosave draft actions live in the disk store — mock the whole store so the stage
// is drivable without Pinia. Hoisted spies keep stable references to assert on.
const { aiEditText, fetchDraft, saveDraft, deleteDraft, fetchDraftBase, setFileHasDraft } = vi.hoisted(() => ({
  aiEditText: vi.fn(),
  fetchDraft: vi.fn(),
  saveDraft: vi.fn(),
  deleteDraft: vi.fn(),
  fetchDraftBase: vi.fn(),
  setFileHasDraft: vi.fn(),
}));
vi.mock('../../../../app/stores/disk', () => ({
  useDiskStore: () => ({ aiEditText, fetchDraft, saveDraft, deleteDraft, fetchDraftBase, setFileHasDraft }),
}));

import { api } from '../../../../app/lib/api';
import TextStage from '../stages/TextStage.vue';
import type { DiskFile } from '../../types';

const apiMock = api as unknown as { get: ReturnType<typeof vi.fn> };

/** Find a button by its visible (trimmed) label. */
function btn(wrapper: ReturnType<typeof mount>, label: string) {
  return wrapper.findAll('button').find((b) => b.text().trim() === label);
}
const textareaEl = (wrapper: ReturnType<typeof mount>) =>
  wrapper.get('textarea').element as HTMLTextAreaElement;

function file(over: Partial<DiskFile> = {}): DiskFile {
  return {
    id: 'f1', name: 'notatka.txt', path: '/api/disk/f1', type: 'text', size: 5, size_human: '5 B',
    created_at: '2026-07-19 10:00', description: null, mime_type: 'text/plain', folder_id: null,
    source: 'disk', created_at_iso: null, updated_at_iso: null, disk_trashed_at: null,
    can_be_updated: true, can_be_moved: true, can_be_deleted: true,
    can_be_restored: false, can_be_force_deleted: false, has_draft: false, ...over,
  };
}

describe('TextStage', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('en');
    apiMock.get.mockReset();
    apiMock.get.mockResolvedValue(new Blob(['hello'], { type: 'text/plain' }));
    aiEditText.mockReset();
    // Autosave draft actions: default to "no pending draft" so the banner stays hidden.
    fetchDraft.mockReset().mockResolvedValue(null);
    saveDraft.mockReset().mockResolvedValue({ base_ids: [], updated_at: 'x' });
    deleteDraft.mockReset().mockResolvedValue(undefined);
    fetchDraftBase.mockReset();
  });
  afterEach(() => restoreBrowserMocks());

  it('loads the content, tracks dirty against it, and emits save with the edited blob', async () => {
    const wrapper = mount(TextStage, {
      props: { file: file(), canOverwrite: true, saving: false },
    });
    await flushPromises();

    const textarea = wrapper.get('textarea');
    expect((textarea.element as HTMLTextAreaElement).value).toBe('hello');
    const dirtyEvents = () => wrapper.emitted('update:dirty') ?? [];
    expect(dirtyEvents()[dirtyEvents().length - 1]).toEqual([false]);

    await textarea.setValue('hello world');
    expect(dirtyEvents()[dirtyEvents().length - 1]).toEqual([true]);

    // "Zapisz" — the main split-button segment (the view toggle now renders buttons first).
    await btn(wrapper, 'Save')!.trigger('click');
    const save = wrapper.emitted('save');
    expect(save).toHaveLength(1);
    const payload = save![0][0] as { blob: Blob; filename: string };
    expect(payload.filename).toBe('notatka.txt');
    expect(await payload.blob.text()).toBe('hello world');
  });

  it('offers ONLY "Save as…" when overwrite is not allowed (resource-owned file)', async () => {
    const wrapper = mount(TextStage, {
      props: { file: file({ source: 'task', can_be_updated: true }), canOverwrite: false, saving: false },
    });
    await flushPromises();

    // No split button (no chevron), a single Save-as action.
    expect(wrapper.find('[aria-haspopup="menu"]').exists()).toBe(false);
    await btn(wrapper, 'Save as…')!.trigger('click');
    expect(wrapper.emitted('save')).toBeUndefined();
    expect(wrapper.emitted('save-as')).toHaveLength(1);
    const payload = wrapper.emitted('save-as')![0][0] as { defaultName: string };
    expect(payload.defaultName).toBe('notatka-edited.txt');
  });

  it('applies an AI preset: posts to the store, replaces the buffer, and flips dirty (Save enabled)', async () => {
    aiEditText.mockResolvedValue('Hello, corrected.');
    const wrapper = mount(TextStage, { props: { file: file(), canOverwrite: true, saving: false } });
    await flushPromises();

    // Save starts disabled (not dirty). Target it by label — the view toggle renders buttons too.
    const save = () => btn(wrapper, 'Save')!;
    expect(save().attributes('disabled')).toBeDefined();

    await btn(wrapper, 'Improve writing')!.trigger('click');
    await flushPromises();

    // Posted the LOADED content + the preset's fixed English instruction, and replaced the buffer.
    expect(aiEditText).toHaveBeenCalledWith('hello', expect.stringContaining('Improve the writing'));
    expect(textareaEl(wrapper).value).toBe('Hello, corrected.');

    // Dirty flipped → Save enabled.
    const dirtyEvents = wrapper.emitted('update:dirty') ?? [];
    expect(dirtyEvents[dirtyEvents.length - 1]).toEqual([true]);
    expect(save().attributes('disabled')).toBeUndefined();
  });

  it('applies the free-text prompt and clears the input on success', async () => {
    aiEditText.mockResolvedValue('rewritten body');
    const wrapper = mount(TextStage, { props: { file: file(), canOverwrite: true, saving: false } });
    await flushPromises();

    await wrapper.get('input').setValue('make it more formal');
    await btn(wrapper, 'Apply')!.trigger('click');
    await flushPromises();

    expect(aiEditText).toHaveBeenCalledWith('hello', 'make it more formal');
    expect(textareaEl(wrapper).value).toBe('rewritten body');
    expect((wrapper.get('input').element as HTMLInputElement).value).toBe(''); // cleared
  });

  it('disables the textarea + the free-prompt input and shows a status while a request is in flight', async () => {
    let resolveAi!: (v: string) => void;
    aiEditText.mockReturnValue(new Promise<string>((res) => { resolveAi = res; }));
    const wrapper = mount(TextStage, { props: { file: file(), canOverwrite: true, saving: false } });
    await flushPromises();

    await btn(wrapper, 'Shorten')!.trigger('click');
    await nextTick();

    expect(textareaEl(wrapper).disabled).toBe(true);
    expect((wrapper.get('input').element as HTMLInputElement).disabled).toBe(true);
    expect(wrapper.text()).toContain('Editing…');

    resolveAi('done');
    await flushPromises();
    expect(textareaEl(wrapper).disabled).toBe(false);
  });

  it('offers a one-step Undo that restores the pre-AI content and then disappears', async () => {
    aiEditText.mockResolvedValue('AI VERSION');
    const wrapper = mount(TextStage, { props: { file: file(), canOverwrite: true, saving: false } });
    await flushPromises();

    // No Undo before any AI edit.
    expect(btn(wrapper, 'Undo AI change')).toBeUndefined();

    await btn(wrapper, 'Shorten')!.trigger('click');
    await flushPromises();
    expect(textareaEl(wrapper).value).toBe('AI VERSION');

    // Undo appears → restores the loaded content in one step, then removes itself.
    await btn(wrapper, 'Undo AI change')!.trigger('click');
    await nextTick();
    expect(textareaEl(wrapper).value).toBe('hello');
    expect(btn(wrapper, 'Undo AI change')).toBeUndefined();
  });

  it('surfaces the server message as a toast and keeps the content on an AI failure', async () => {
    aiEditText.mockRejectedValue({ response: { data: { message: 'AI provider unavailable.' } } });
    const wrapper = mount(TextStage, { props: { file: file(), canOverwrite: true, saving: false } });
    await flushPromises();

    await btn(wrapper, 'Summarize')!.trigger('click');
    await flushPromises();

    // Content unchanged, no Undo offered, and the controls re-enable.
    expect(textareaEl(wrapper).value).toBe('hello');
    expect(btn(wrapper, 'Undo AI change')).toBeUndefined();
    expect(textareaEl(wrapper).disabled).toBe(false);
  });

  // --- Two-mode view: Edit (textarea) vs Review changes (diff) --------------
  it('toggles between the editable textarea and the read-only diff', async () => {
    const wrapper = mount(TextStage, { props: { file: file(), canOverwrite: true, saving: false } });
    await flushPromises();

    // Starts in Edit: the textarea is present and no diff is mounted.
    expect(wrapper.find('textarea').exists()).toBe(true);
    expect(wrapper.find('[role="group"]').exists()).toBe(false);

    // Make a change so the diff has something to show, then switch to Review changes.
    await wrapper.get('textarea').setValue('hello world');
    await btn(wrapper, 'Review changes')!.trigger('click');
    await nextTick();

    // The diff view is shown; the textarea stays MOUNTED (kept alive by v-show) so its content survives.
    expect(wrapper.find('[role="group"]').exists()).toBe(true);
    expect(wrapper.find('textarea').exists()).toBe(true);

    // Back to Edit unmounts the diff again.
    await btn(wrapper, 'Edit')!.trigger('click');
    await nextTick();
    expect(wrapper.find('[role="group"]').exists()).toBe(false);
  });

  it('renders removed (red) and added (green) content in the diff for a changed buffer', async () => {
    const wrapper = mount(TextStage, { props: { file: file(), canOverwrite: true, saving: false } });
    await flushPromises();

    await wrapper.get('textarea').setValue('hello world');
    await btn(wrapper, 'Review changes')!.trigger('click');
    await nextTick();

    const html = wrapper.html();
    expect(html).toContain('bg-next-danger'); // removed-line tint
    expect(html).toContain('bg-next-success'); // added-line tint
    // The added word shows in the diff (which now occupies the editor area).
    expect(wrapper.text()).toContain('world');
  });

  it('auto-switches to the diff after an AI action so the change is visible', async () => {
    aiEditText.mockResolvedValue('hello there');
    const wrapper = mount(TextStage, { props: { file: file(), canOverwrite: true, saving: false } });
    await flushPromises();

    expect(wrapper.find('[role="group"]').exists()).toBe(false); // starts in Edit (no diff)

    await btn(wrapper, 'Improve writing')!.trigger('click');
    await flushPromises();

    // The view flipped to the diff automatically; the AI result is visible there.
    expect(wrapper.find('[role="group"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('there');
    // Undo stays available in the diff so the change can be rejected straight from here.
    expect(btn(wrapper, 'Undo AI change')).toBeTruthy();
  });

  it('shows a "No changes" empty state when the buffer matches the saved file', async () => {
    const wrapper = mount(TextStage, { props: { file: file(), canOverwrite: true, saving: false } });
    await flushPromises();

    // Switch to Review changes without editing → content === loaded.
    await btn(wrapper, 'Review changes')!.trigger('click');
    await nextTick();
    expect(wrapper.text()).toContain('No changes');
  });

  // --- Autosave draft restore banner ----------------------------------------
  const textDraft = () => ({
    kind: 'text' as const,
    manifest: { kind: 'text', content: 'draft body' },
    base_ids: [] as number[],
    base_version: null,
    updated_at: '2026-07-21T09:00:00Z',
  });

  it('shows the restore banner when a pending text draft exists; Restore loads it and flips dirty', async () => {
    fetchDraft.mockResolvedValue(textDraft());
    // `has_draft` (from the list) gates the probe — a pending draft implies the flag.
    const wrapper = mount(TextStage, { props: { file: file({ has_draft: true }), canOverwrite: true, saving: false } });
    await flushPromises();

    // The banner names the recovered draft and offers Restore / Discard.
    expect(wrapper.text()).toContain('unsaved changes');
    const restore = btn(wrapper, 'Restore');
    expect(restore).toBeTruthy();

    await restore!.trigger('click');
    await flushPromises();

    // The draft content replaces the loaded buffer → dirty flips true (Save enables).
    expect(textareaEl(wrapper).value).toBe('draft body');
    const dirtyEvents = wrapper.emitted('update:dirty') ?? [];
    expect(dirtyEvents[dirtyEvents.length - 1]).toEqual([true]);
    // The banner is gone once restored.
    expect(btn(wrapper, 'Restore')).toBeUndefined();
  });

  it('Discard deletes the draft and hides the banner', async () => {
    fetchDraft.mockResolvedValue(textDraft());
    const wrapper = mount(TextStage, { props: { file: file({ has_draft: true }), canOverwrite: true, saving: false } });
    await flushPromises();

    await btn(wrapper, 'Discard')!.trigger('click');
    await flushPromises();

    expect(deleteDraft).toHaveBeenCalledWith('f1');
    expect(btn(wrapper, 'Restore')).toBeUndefined(); // banner gone
    // Discarding keeps the loaded content untouched.
    expect(textareaEl(wrapper).value).toBe('hello');
  });

  it('shows no banner — and sends NO probe request — when the file has no draft (has_draft false)', async () => {
    fetchDraft.mockResolvedValue(null);
    const wrapper = mount(TextStage, { props: { file: file(), canOverwrite: true, saving: false } });
    await flushPromises();
    expect(btn(wrapper, 'Restore')).toBeUndefined();
    // The list already said there is no draft → GET /{file}/draft is never fired.
    expect(fetchDraft).not.toHaveBeenCalled();
  });
});
