// @vitest-environment happy-dom
// PublicationMediaField.spec — the two things this field must never do quietly.
//
// ═════════════════════════════════════════════════════════════════════════════════════════
// 1. A FAILED LOOKUP MUST NOT EDIT SOMEBODY'S PUBLICATION
// ═════════════════════════════════════════════════════════════════════════════════════════
// `publications.media` is a list of Disk uuids with NO foreign key behind it, so a file can
// genuinely vanish between drafting and publishing. This field says so EARLY, while something
// can still be done about it — and the tempting "tidy-up" is to drop the dead id from the
// array while showing the warning. That is a write nobody asked for, on the strength of one
// failed GET: the next "Save draft" is a whole-row PUT, and the attachment is gone for good.
// So the assertion is on the ABSENCE of an `update:modelValue` — the array is reported on,
// never trimmed (§16.4 pkt 10).
//
// And 404 IS NOT 500. A 404/403 is a statement about the FILE; anything else is a statement
// about the network, and the file is fine. Collapsing the two makes the application accuse
// the Disk of losing something it still has, in a warning that tells the reader to remove a
// perfectly good attachment.
//
// ═════════════════════════════════════════════════════════════════════════════════════════
// 2. ORDER IS DATA, AND A REORDER THAT IS NOT ANNOUNCED IS A REORDER NOBODY CAN VERIFY
// ═════════════════════════════════════════════════════════════════════════════════════════
// A carousel whose first image changed is a different post. There is no drag-and-drop here on
// purpose, so ▲/▼ are the ONLY way to express that intent — and for somebody who cannot see
// the tiles move, the polite announcement is the whole feedback loop (§16.4 pkt 11).
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

// Inlined per factory: `vi.mock` is hoisted above every top-level binding, and the i18n
// singleton imports this module at load time — a hoisted reference to a `const` declared
// below would be read while still in its temporal dead zone.
vi.mock('../../../app/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

import PublicationMediaField from '../PublicationMediaField.vue';
import { api } from '../../../app/lib/api';
import { setLocale } from '../../../app/i18n';
import type { DiskFile } from '../../disk/types';

const apiGet = vi.mocked(api.get);

function diskFile(id: string, name: string): DiskFile {
  return {
    id,
    name,
    path: `/disk/${id}`,
    type: 'image',
    size: 1024,
    size_human: '1 KB',
    created_at: '2026-09-01 10:00',
    description: null,
    mime_type: 'image/png',
    folder_id: null,
    source: 'disk',
    created_at_iso: '2026-09-01T10:00:00.000000Z',
    updated_at_iso: '2026-09-01T10:00:00.000000Z',
    disk_trashed_at: null,
    can_be_updated: true,
    can_be_moved: true,
    can_be_deleted: true,
    can_be_restored: false,
    can_be_force_deleted: false,
  } as DiskFile;
}

/** An axios-shaped rejection: only `response.status` is read. */
function httpError(status: number): unknown {
  return { response: { status, data: {}, headers: {} } };
}

async function mountField(ids: string[]) {
  const wrapper = mount(PublicationMediaField, {
    props: { modelValue: ids },
    // The picker and the thumbnail both talk to the Disk on their own; their behaviour has
    // its own specs and here would only add ways for this file to fail for other reasons.
    global: { stubs: { DiskFilePickerModal: true, DiskThumbnail: true } },
  });
  await flushPromises();
  return wrapper;
}

type Wrapper = Awaited<ReturnType<typeof mountField>>;

/** The polite live region — the only thing this field ever announces. */
function announcement(wrapper: Wrapper): string {
  return wrapper.find('[aria-live="polite"]').text();
}

/** A tile's mover, addressed by the accessible name a real reader would hear. */
function moverFor(wrapper: Wrapper, label: string) {
  return wrapper.findAll('button').find((b) => b.attributes('aria-label') === label);
}

beforeEach(() => {
  setLocale('en');
  apiGet.mockReset();
});

describe('a file that is no longer on the Disk (404)', () => {
  beforeEach(() => {
    apiGet.mockImplementation((url: string) =>
      url.includes('gone')
        ? Promise.reject(httpError(404))
        : Promise.resolve({ data: diskFile('here', 'autumn.png') }),
    );
  });

  it('warns ABOVE the section and marks the tile, and NEVER trims `media`', async () => {
    const wrapper = await mountField(['here', 'gone']);

    // The warning that stands over the whole section — this is what makes the failure
    // actionable before the publish attempt turns it into a failure code.
    expect(wrapper.text()).toContain('A publication with a missing file WILL NOT be published');
    // And the tile itself, so the reader knows WHICH one.
    expect(wrapper.text()).toContain('This file is no longer on the Disk');

    // THE ASSERTION THIS BLOCK EXISTS FOR. One failed GET must not rewrite the record: both
    // ids are still on screen and nothing was emitted upward.
    expect(wrapper.findAll('li')).toHaveLength(2);
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
  });

  it('keeps the missing tile in ITS place, not sorted to the end', async () => {
    // The position of a missing file is still the position the platform would receive it in;
    // moving it would silently change the post the moment the file is replaced.
    const wrapper = await mountField(['gone', 'here']);
    const tiles = wrapper.findAll('li');
    expect(tiles[0].text()).toContain('This file is no longer on the Disk');
    expect(tiles[1].text()).toContain('autumn.png');
  });
});

describe('a lookup that failed for reasons that are not about the file', () => {
  it('says the PREVIEWS failed — never that the file is gone', async () => {
    apiGet.mockRejectedValue(httpError(500));
    const wrapper = await mountField(['a']);

    expect(wrapper.text()).toContain('The media previews could not be loaded');
    expect(wrapper.text()).toContain('The files themselves are untouched');

    // Neither sentence about a lost file may appear: one of them tells the reader to delete
    // an attachment that is perfectly fine.
    expect(wrapper.text()).not.toContain('no longer on the Disk');
    expect(wrapper.text()).not.toContain('WILL NOT be published');
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
  });

  it('treats a 403 as a statement about the FILE, like a 404', async () => {
    // Not ours any more is, for this screen, the same fact as gone: either way it will not
    // publish, and the remedy is the same.
    apiGet.mockRejectedValue(httpError(403));
    const wrapper = await mountField(['a']);

    expect(wrapper.text()).toContain('no longer on the Disk');
    expect(wrapper.text()).not.toContain('The media previews could not be loaded');
  });
});

describe('reordering with the keyboard', () => {
  beforeEach(() => {
    apiGet.mockImplementation((url: string) =>
      Promise.resolve({
        data: url.includes('one') ? diskFile('one', 'first.png') : diskFile('two', 'second.png'),
      }),
    );
  });

  it('▲ moves the file UP in the array and announces its new position', async () => {
    const wrapper = await mountField(['one', 'two']);

    await moverFor(wrapper, 'Move up: second.png')!.trigger('click');

    // The array itself — the thing the platform receives, in order.
    expect(wrapper.emitted('update:modelValue')?.[0]?.[0]).toEqual(['two', 'one']);
    // And the sentence somebody who cannot see the tiles move depends on entirely.
    expect(announcement(wrapper)).toBe('second.png: position 1 of 2');
  });

  it('▼ moves it DOWN, with the position counted from one', async () => {
    const wrapper = await mountField(['one', 'two']);

    await moverFor(wrapper, 'Move down: first.png')!.trigger('click');

    expect(wrapper.emitted('update:modelValue')?.[0]?.[0]).toEqual(['two', 'one']);
    expect(announcement(wrapper)).toBe('first.png: position 2 of 2');
  });

  it('offers no ▲ on the first tile and no ▼ on the last — the ends have nowhere to go', async () => {
    const wrapper = await mountField(['one', 'two']);

    expect(moverFor(wrapper, 'Move up: first.png')).toBeUndefined();
    expect(moverFor(wrapper, 'Move down: second.png')).toBeUndefined();
    // The permanent control is still there on both, so nothing shifts sideways between tiles.
    expect(moverFor(wrapper, 'Remove: first.png')).toBeDefined();
    expect(moverFor(wrapper, 'Remove: second.png')).toBeDefined();
  });

  it('announces nothing at all before anything is moved', async () => {
    const wrapper = await mountField(['one', 'two']);
    expect(announcement(wrapper)).toBe('');
  });
});
