// @vitest-environment happy-dom
// DiskTile — the tile shapes render their label/meta, gate actions on capability flags,
// and emit their events.
import { describe, it, expect, beforeAll, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { setLocale } from '../../../app/i18n';
import DiskTile from '../DiskTile.vue';
import type { DiskFile, DiskFolder } from '../types';

beforeAll(() => {
  setLocale('en');
  // A never-firing IntersectionObserver: the file tile's DiskThumbnail stays deferred, so these
  // tile tests never trigger a (real) thumbnail fetch — they only assert the tile's own chrome.
  vi.stubGlobal(
    'IntersectionObserver',
    class {
      observe(): void {}
      disconnect(): void {}
      unobserve(): void {}
    },
  );
});

const folder: DiskFolder = {
  id: 'a', name: 'Campaigns', parent_id: null, depth: 0, ancestor_ids: [],
  files_count: 3, children_count: 0, created_at: null, updated_at: null, deleted_at: null,
  can_be_updated: true, can_be_moved: true, can_be_deleted: true, can_be_restored: true,
};
const file: DiskFile = {
  id: 'f1', name: 'hero.png', path: '/api/disk/f1', type: 'image', size: 2048, size_human: '2 KB',
  created_at: '2026-07-17 10:00', description: null, mime_type: 'image/png', folder_id: null,
  source: 'disk', created_at_iso: null, updated_at_iso: null, disk_trashed_at: null,
  can_be_updated: true, can_be_moved: true, can_be_deleted: true,
  can_be_restored: true, can_be_force_deleted: true, has_draft: false,
};

describe('DiskTile', () => {
  it('a folder tile shows its name + item count', () => {
    const wrapper = mount(DiskTile, { props: { kind: 'folder', folder } });
    expect(wrapper.text()).toContain('Campaigns');
    expect(wrapper.text()).toContain('3'); // items count
  });

  it('a file tile shows its name + human size', () => {
    const wrapper = mount(DiskTile, { props: { kind: 'file', file } });
    expect(wrapper.text()).toContain('hero.png');
    expect(wrapper.text()).toContain('2 KB');
  });

  it('emits activate on click', async () => {
    const wrapper = mount(DiskTile, { props: { kind: 'folder', folder } });
    await wrapper.find('button').trigger('click');
    expect(wrapper.emitted('activate')).toHaveLength(1);
  });

  it('the up tile renders without a folder/file', () => {
    const wrapper = mount(DiskTile, { props: { kind: 'up' } });
    expect(wrapper.find('button').exists()).toBe(true);
  });

  it('counts subfolders + files in the item hint', () => {
    const withBoth = { ...folder, files_count: 2, children_count: 3 };
    const wrapper = mount(DiskTile, { props: { kind: 'folder', folder: withBoth } });
    expect(wrapper.text()).toContain('5'); // 3 subfolders + 2 files, not 2
  });

  it('trash actions are gated on server capability flags (no kebab when nothing is allowed)', () => {
    const forbidden = { ...file, can_be_restored: false, can_be_force_deleted: false };
    const wrapper = mount(DiskTile, { props: { kind: 'file', file: forbidden, menu: 'trash' } });
    // The kebab menu (aria-label "Actions") must be absent when the member may do nothing.
    expect(wrapper.findAll('button').some((b) => b.attributes('aria-label') === 'Actions')).toBe(false);
  });

  it('a restorable trashed file DOES expose the kebab', () => {
    const wrapper = mount(DiskTile, {
      props: { kind: 'file', file: { ...file, can_be_restored: true, can_be_force_deleted: false }, menu: 'trash' },
    });
    expect(wrapper.findAll('button').some((b) => b.attributes('aria-label') === 'Actions')).toBe(true);
  });

  // --- draft indicator (has_draft) -----------------------------------------
  it('a file with has_draft shows the draft indicator with its accessible label + title', () => {
    const wrapper = mount(DiskTile, { props: { kind: 'file', file: { ...file, has_draft: true } } });
    const badge = wrapper.find('[aria-label="Unsaved draft"]');
    expect(badge.exists()).toBe(true);
    expect(badge.attributes('role')).toBe('img'); // an image role, not color-alone
    expect(badge.attributes('title')).toBe('Unsaved draft'); // native hover tooltip
  });

  it('a file WITHOUT a draft shows no indicator', () => {
    const wrapper = mount(DiskTile, { props: { kind: 'file', file: { ...file, has_draft: false } } });
    expect(wrapper.find('[aria-label="Unsaved draft"]').exists()).toBe(false);
  });

  it('a folder never shows the draft indicator (folders have no drafts)', () => {
    const wrapper = mount(DiskTile, { props: { kind: 'folder', folder } });
    expect(wrapper.find('[aria-label="Unsaved draft"]').exists()).toBe(false);
  });
});
