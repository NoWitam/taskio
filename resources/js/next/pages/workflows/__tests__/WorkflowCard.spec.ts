// @vitest-environment happy-dom
// WorkflowCard.spec — the Active/Deleted `trashed` prop behaviour (the Kosz batch).
// The card's job in the Deleted tab is to (a) swap its kebab down to a single
// Restore action (ownership-gated, like every other row action), (b) show a
// "Deleted" status badge instead of the live active/inactive one, and (c) stay
// inert as a whole-card link (only the kebab acts). The DropdownMenu/Item pair is
// stubbed so the menu items render inline — no Popover teleport / positioning needed
// (the menu internals are covered by the overlay specs). The i18n singleton is pinned
// to `en`.
import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, type VNode } from 'vue';
import WorkflowCard from '../WorkflowCard.vue';
import { setLocale } from '../../../app/i18n';
import type { WorkflowListItem } from '../types';

function listItem(overrides: Partial<WorkflowListItem> = {}): WorkflowListItem {
  return {
    id: 'w1',
    name: 'Publish digest',
    status: 'inactive',
    description: null,
    icon: null,
    trigger_type: 'form_submitted',
    step_count: 1,
    next_due_at: null,
    is_owner: true,
    created_at: '2026-01-01T00:00:00Z',
    ...overrides,
  };
}

// Render the trigger + the default slot (the menu items) inline, skipping Popover.
const DropdownMenuStub = {
  name: 'DropdownMenu',
  setup(_props: unknown, { slots }: { slots: Record<string, ((arg?: unknown) => VNode[]) | undefined> }) {
    return () =>
      h('div', { class: 'dm' }, [
        slots.trigger ? slots.trigger({ props: {} }) : null,
        h('ul', { class: 'dm-list' }, slots.default ? slots.default() : []),
      ]);
  },
};

// A menuitem stub: a button that echoes its `label` + `disabled` and emits `select`
// on click (so the card's own `@select` gating is what's under test).
const DropdownMenuItemStub = {
  name: 'DropdownMenuItem',
  props: ['icon', 'label', 'disabled', 'destructive'],
  emits: ['select'],
  setup(
    props: Record<string, unknown>,
    { slots, emit }: { slots: Record<string, (() => VNode[]) | undefined>; emit: (e: string) => void },
  ) {
    return () =>
      h(
        'button',
        {
          class: 'dm-item',
          'data-label': props.label,
          disabled: props.disabled ? true : undefined,
          onClick: () => emit('select'),
        },
        slots.default ? slots.default() : [],
      );
  },
};

function mountCard(props: Record<string, unknown> = {}) {
  return mount(WorkflowCard, {
    props: { workflow: listItem(), ...props },
    global: { stubs: { DropdownMenu: DropdownMenuStub, DropdownMenuItem: DropdownMenuItemStub } },
  });
}

function itemLabels(wrapper: ReturnType<typeof mountCard>): (string | undefined)[] {
  return wrapper.findAll('.dm-item').map((b) => b.attributes('data-label'));
}

beforeEach(() => {
  setLocale('en');
});

describe('WorkflowCard — Deleted tab (trashed prop)', () => {
  it('shows ONLY a Restore action in the Deleted tab', () => {
    expect(itemLabels(mountCard({ trashed: true }))).toEqual(['Restore']);
  });

  it('shows the full active-tab action set (never Restore) when not trashed', () => {
    const labels = itemLabels(mountCard({ trashed: false }));
    expect(labels).toEqual(['Run now', 'Activate', 'Edit', 'Delete']);
    expect(labels).not.toContain('Restore');
  });

  it('emits `restore` with the workflow when the owner selects Restore', async () => {
    const wrapper = mountCard({ trashed: true });
    await wrapper.find('.dm-item').trigger('click');
    expect(wrapper.emitted('restore')?.[0]?.[0]).toMatchObject({ id: 'w1' });
  });

  it('gates Restore on ownership — a non-owner sees it disabled and cannot restore', async () => {
    const wrapper = mountCard({ trashed: true, workflow: listItem({ is_owner: false }) });
    const item = wrapper.find('.dm-item');
    expect(item.attributes('disabled')).toBeDefined();
    await item.trigger('click');
    expect(wrapper.emitted('restore')).toBeUndefined();
  });

  it('swaps the live status for a Deleted badge in the Deleted tab', () => {
    expect(mountCard({ trashed: true }).text()).toContain('Deleted');
    // The active card never shows a "Deleted" badge (its Delete action is "Delete").
    expect(mountCard({ trashed: false }).text()).not.toContain('Deleted');
  });

  it('opens an active card on click but keeps a Deleted card inert', async () => {
    const active = mountCard({ trashed: false });
    await active.find('.next-entity-card__action').trigger('click');
    expect(active.emitted('open')?.[0]?.[0]).toMatchObject({ id: 'w1' });

    const trashed = mountCard({ trashed: true });
    await trashed.find('.next-entity-card__action').trigger('click');
    expect(trashed.emitted('open')).toBeUndefined();
  });
});
