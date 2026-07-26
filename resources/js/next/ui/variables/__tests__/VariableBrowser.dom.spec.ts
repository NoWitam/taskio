// @vitest-environment happy-dom
// VariableBrowser.dom.spec — the SHARED variable browser's behaviour.
//
// Pins the INLINE TREE model (B3: expanding a container reveals its children directly
// BENEATH it, indented — the miller-column experiment and its `layout` modes are gone),
// the WAI-ARIA tree keyboard contract (→ expand / step in, ← collapse / step out,
// Enter = pick-or-toggle, Home/End, type-ahead), the chevron as a separate non-selecting
// target, the query → flat-results switch and back, and the empty / no-match states.
// Virtual focus is asserted through `aria-activedescendant`: the body is the ONE focusable
// element (so the shell's search input can drive the same cursor), which is why rows carry
// `aria-posinset`/`aria-setsize` — the DOM is flattened, not nested.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import VariableBrowser from '../VariableBrowser.vue';
import { buildVariableTree } from '../variableTree';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { VariableSourceVar } from '../types';

// A catalog with all three interesting shapes: a plain leaf, an object GLOBAL (expand-only)
// with a nested object, and a FILE composite (expandable AND selectable).
const VARS: VariableSourceVar[] = [
  { source: 'trigger', path: 'trigger.title', name: 'Title', type: 'text' },
  {
    source: 'globals',
    path: 'globals.company',
    name: 'Company',
    type: 'text',
    descriptor: {
      base: 'object',
      nullable: false,
      array: false,
      fields: [
        { key: 'name', label: 'Name', descriptor: { base: 'text', nullable: false, array: false } },
        {
          key: 'address',
          label: 'Address',
          descriptor: {
            base: 'object',
            nullable: false,
            array: false,
            fields: [{ key: 'city', label: 'City', descriptor: { base: 'text', nullable: false, array: false } }],
          },
        },
      ],
    },
  },
  {
    source: 'trigger',
    path: 'trigger.fields.attachment',
    name: 'Attachment',
    type: 'file',
    descriptor: {
      base: 'file',
      nullable: false,
      array: false,
      fields: [{ key: 'name', label: 'name', descriptor: { base: 'text', nullable: false, array: false } }],
    },
  },
];

const TREE = buildVariableTree(VARS, {});

function mountBrowser(props: Record<string, unknown> = {}) {
  return mount(VariableBrowser, {
    attachTo: document.body,
    props: { nodes: TREE, rootLabel: 'Variables', ...props },
  });
}

type Wrapper = ReturnType<typeof mountBrowser>;

function body(wrapper: Wrapper): HTMLElement {
  return wrapper.get('[data-variable-browser]').element as HTMLElement;
}
/** Every VISIBLE row: tree items while browsing, options while querying. */
function rows(wrapper: Wrapper): HTMLElement[] {
  return wrapper.findAll('[role="treeitem"], [role="option"]').map((w) => w.element as HTMLElement);
}
/** A row's NAME (the type glyph + its markers carry sr-only text, so read the label span). */
function rowLabel(el: HTMLElement | null | undefined): string {
  return el?.querySelector('span.truncate')?.textContent?.trim() ?? '';
}
function rowLabels(wrapper: Wrapper): string[] {
  return rows(wrapper).map(rowLabel);
}
function rowByText(wrapper: Wrapper, text: string): HTMLElement | undefined {
  return rows(wrapper).find((el) => rowLabel(el) === text);
}
/** The 1-based depth a row announces (the indentation is derived from the same number). */
function level(el: HTMLElement | undefined): string | null {
  return el?.getAttribute('aria-level') ?? null;
}
/** The virtually-focused row (aria-activedescendant → the row element). */
function activeRow(wrapper: Wrapper): HTMLElement | null {
  const id = body(wrapper).getAttribute('aria-activedescendant');
  return id ? (wrapper.element.querySelector(`#${id}`) as HTMLElement | null) : null;
}
function activeLabel(wrapper: Wrapper): string {
  return rowLabel(activeRow(wrapper));
}
async function press(wrapper: Wrapper, key: string): Promise<void> {
  body(wrapper).dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));
  await nextTick();
}

describe('VariableBrowser — inline tree', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('starts collapsed at the roots, cursor on the first row, as a real ARIA tree', () => {
    const wrapper = mountBrowser();

    expect(rowLabels(wrapper)).toEqual(['Title', 'Company', 'Attachment']);
    expect(activeLabel(wrapper)).toBe('Title');

    // The a11y model: ONE tree, treeitem rows, virtual focus on the single focusable body.
    expect(body(wrapper).getAttribute('role')).toBe('tree');
    expect(body(wrapper).getAttribute('tabindex')).toBe('0');
    expect(wrapper.findAll('[role="listbox"]')).toHaveLength(0);
    expect(wrapper.findAll('[role="treeitem"]')).toHaveLength(3);
    expect(rows(wrapper).map(level)).toEqual(['1', '1', '1']);
    // The DOM is flattened, so each row states its own position among its siblings.
    expect(rowByText(wrapper, 'Company')?.getAttribute('aria-posinset')).toBe('2');
    expect(rowByText(wrapper, 'Company')?.getAttribute('aria-setsize')).toBe('3');
    expect(rowByText(wrapper, 'Company')?.getAttribute('aria-expanded')).toBe('false');
    expect(rowByText(wrapper, 'Title')?.getAttribute('aria-expanded')).toBeNull(); // a leaf

    wrapper.unmount();
  });

  it('→ expands INLINE: the children appear directly BENEATH the parent, one level deeper', async () => {
    const wrapper = mountBrowser();

    await press(wrapper, 'ArrowDown'); // Company (the object global)
    expect(activeLabel(wrapper)).toBe('Company');

    await press(wrapper, 'ArrowRight');

    // The parent stays where it was; its children were inserted right after it.
    expect(rowLabels(wrapper)).toEqual(['Title', 'Company', 'Name', 'Address', 'Attachment']);
    expect(rowByText(wrapper, 'Company')?.getAttribute('aria-expanded')).toBe('true');
    expect(level(rowByText(wrapper, 'Company'))).toBe('1');
    expect(level(rowByText(wrapper, 'Name'))).toBe('2');
    // A child is INDENTED (the depth is visible, not only announced).
    expect(rowByText(wrapper, 'Name')?.style.paddingInlineStart).not.toBe(
      rowByText(wrapper, 'Company')?.style.paddingInlineStart,
    );
    // The cursor stays on the container (WAI-ARIA: → opens, a second → steps in).
    expect(activeLabel(wrapper)).toBe('Company');

    await press(wrapper, 'ArrowRight');
    expect(activeLabel(wrapper)).toBe('Name');

    wrapper.unmount();
  });

  it('→ is a no-op on a leaf', async () => {
    const wrapper = mountBrowser();

    expect(activeLabel(wrapper)).toBe('Title'); // a plain scalar
    await press(wrapper, 'ArrowRight');

    expect(rowLabels(wrapper)).toEqual(['Title', 'Company', 'Attachment']);
    expect(wrapper.emitted('select')).toBeUndefined();

    wrapper.unmount();
  });

  it('← collapses an open container, and from a leaf moves to its PARENT', async () => {
    const wrapper = mountBrowser();

    await press(wrapper, 'ArrowDown'); // Company
    await press(wrapper, 'ArrowRight'); // expand
    await press(wrapper, 'ArrowRight'); // → Name (a child leaf)
    expect(activeLabel(wrapper)).toBe('Name');

    // From a leaf, ← steps OUT to the parent (the tree stays open).
    await press(wrapper, 'ArrowLeft');
    expect(activeLabel(wrapper)).toBe('Company');
    expect(rowByText(wrapper, 'Name')).toBeTruthy();

    // From the open container, ← closes it: the children leave the DOM entirely.
    await press(wrapper, 'ArrowLeft');
    expect(rowLabels(wrapper)).toEqual(['Title', 'Company', 'Attachment']);
    expect(rowByText(wrapper, 'Company')?.getAttribute('aria-expanded')).toBe('false');
    expect(activeLabel(wrapper)).toBe('Company');

    wrapper.unmount();
  });

  it('collapsing the branch the cursor is INSIDE brings the cursor back to the container', async () => {
    const wrapper = mountBrowser();

    await press(wrapper, 'ArrowDown'); // Company
    await press(wrapper, 'ArrowRight'); // expand
    await press(wrapper, 'ArrowDown'); // Name (a child)
    expect(activeLabel(wrapper)).toBe('Name');

    // Toggle the container shut from its chevron — the cursor cannot stay on a gone row.
    rowByText(wrapper, 'Company')!.querySelector('button')!.click();
    await nextTick();

    expect(rowByText(wrapper, 'Name')).toBeUndefined();
    expect(activeLabel(wrapper)).toBe('Company');

    wrapper.unmount();
  });

  it('Home / End jump to the first / last VISIBLE row', async () => {
    const wrapper = mountBrowser();

    await press(wrapper, 'End');
    expect(activeLabel(wrapper)).toBe('Attachment');

    await press(wrapper, 'Home');
    expect(activeLabel(wrapper)).toBe('Title');

    wrapper.unmount();
  });

  it('Enter on a CONTAINER toggles it and emits nothing; Enter on a leaf emits the composed path', async () => {
    const wrapper = mountBrowser();

    await press(wrapper, 'ArrowDown'); // Company
    await press(wrapper, 'Enter');

    // A whole object resolves to a map, so it can only ever be opened.
    expect(wrapper.emitted('select')).toBeUndefined();
    expect(rowByText(wrapper, 'Name')).toBeTruthy();

    await press(wrapper, 'ArrowDown'); // Name
    await press(wrapper, 'ArrowDown'); // Address (a nested container)
    await press(wrapper, 'ArrowRight'); // open it
    await press(wrapper, 'ArrowRight'); // → City
    expect(activeLabel(wrapper)).toBe('City');
    expect(level(rowByText(wrapper, 'City'))).toBe('3'); // nesting keeps going down

    await press(wrapper, 'Enter');

    const emitted = wrapper.emitted('select');
    expect(emitted).toHaveLength(1);
    expect(emitted?.[0][0]).toMatchObject({
      source: 'globals',
      path: 'globals.company.address.city',
      type: 'text',
    });

    wrapper.unmount();
  });

  it('the chevron expands WITHOUT selecting, while the row itself picks a selectable file composite', async () => {
    const wrapper = mountBrowser();

    // Attachment is BOTH: its chevron opens the subfields, its row emits the whole file.
    const attachment = rowByText(wrapper, 'Attachment')!;
    attachment.querySelector('button')!.click();
    await nextTick();

    expect(rowLabels(wrapper)).toEqual(['Title', 'Company', 'Attachment', 'Name']);
    expect(wrapper.emitted('select')).toBeUndefined();

    rowByText(wrapper, 'Attachment')!.click();
    await nextTick();
    expect(wrapper.emitted('select')?.[0][0]).toMatchObject({
      path: 'trigger.fields.attachment',
      type: 'file',
    });

    wrapper.unmount();
  });

  it('clicking an object container anywhere expands it (it is never selectable)', async () => {
    const wrapper = mountBrowser();

    rowByText(wrapper, 'Company')!.click();
    await nextTick();

    expect(rowByText(wrapper, 'Name')).toBeTruthy();
    expect(wrapper.emitted('select')).toBeUndefined();

    wrapper.unmount();
  });

  it('has NO breadcrumb strip (each host surface echoes the chosen variable itself)', async () => {
    const wrapper = mountBrowser();

    await press(wrapper, 'ArrowDown');
    await press(wrapper, 'ArrowRight'); // deepest state the old strip would have echoed

    expect(wrapper.find('[data-variable-browser-path]').exists()).toBe(false);
    expect(wrapper.find('.next-vbrowse__crumbs').exists()).toBe(false);

    wrapper.unmount();
  });

  it('opens the tree down to an already-CHOSEN deep path and puts the cursor on it', () => {
    const wrapper = mountBrowser({ selectedPath: 'globals.company.address.city' });

    // Every container on the way is open, so the chosen row is actually visible + marked.
    expect(rowLabels(wrapper)).toEqual(['Title', 'Company', 'Name', 'Address', 'City', 'Attachment']);
    expect(activeLabel(wrapper)).toBe('City');
    expect(rowByText(wrapper, 'City')?.getAttribute('aria-selected')).toBe('true');

    wrapper.unmount();
  });
});

describe('VariableBrowser — query mode', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('a query replaces the tree with a FLAT list of selectable hits + breadcrumbs, and clearing restores the tree', async () => {
    const wrapper = mountBrowser();

    // Open a container first, so we can prove the tree comes BACK as it was.
    await press(wrapper, 'ArrowDown');
    await press(wrapper, 'ArrowRight');
    expect(rowLabels(wrapper)).toContain('Name');

    await wrapper.setProps({ query: 'city' });

    // A flat result list is a listbox of options, not a tree.
    expect(wrapper.findAll('[role="treeitem"]')).toHaveLength(0);
    expect(body(wrapper).getAttribute('role')).toBe('listbox');
    const hits = rows(wrapper);
    expect(hits).toHaveLength(1);
    expect(hits[0].textContent).toContain('City');
    expect(hits[0].textContent).toContain('Company › Address'); // the ancestor breadcrumb

    // Enter picks the highlighted hit.
    await press(wrapper, 'Enter');
    expect(wrapper.emitted('select')?.[0][0]).toMatchObject({ path: 'globals.company.address.city' });

    await wrapper.setProps({ query: '' });
    expect(body(wrapper).getAttribute('role')).toBe('tree');
    expect(rowLabels(wrapper)).toEqual(['Title', 'Company', 'Name', 'Address', 'Attachment']);

    wrapper.unmount();
  });

  it('never offers a non-selectable container as a result, and shows the no-match state', async () => {
    const wrapper = mountBrowser({ query: 'company' });

    // "Company" matches the container's own label, but picking it would be a dead end —
    // only its selectable descendants are offered.
    const labels = rowLabels(wrapper);
    expect(labels).not.toContain('Company');
    expect(labels).toContain('Name');

    await wrapper.setProps({ query: 'zzz-nothing' });
    expect(rows(wrapper)).toHaveLength(0);
    expect(wrapper.get('[data-variable-browser-nomatch]').text()).toContain('zzz-nothing');

    wrapper.unmount();
  });

  it('←/→ from the BODY hand editing back to the query input instead of walking the tree', async () => {
    const wrapper = mountBrowser({ query: 'city' });

    await press(wrapper, 'ArrowLeft');
    expect(wrapper.emitted('focus-query')).toHaveLength(1);

    await press(wrapper, 'ArrowRight');
    expect(wrapper.emitted('focus-query')).toHaveLength(2);

    wrapper.unmount();
  });
});

describe('VariableBrowser — states + keyboard extras', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('shows the global empty state (with the still-loading hint) for an empty tree', () => {
    const wrapper = mountBrowser({ nodes: [] });

    expect(wrapper.find('[data-variable-browser]').exists()).toBe(false);
    const empty = wrapper.get('[data-variable-browser-empty]').text();
    expect(empty).toContain('No variables are available here');
    expect(empty).toContain('The catalog may still be loading.');

    wrapper.unmount();
  });

  it('Escape and Tab both ask the surface to close', async () => {
    const wrapper = mountBrowser();

    await press(wrapper, 'Escape');
    expect(wrapper.emitted('close')).toHaveLength(1);

    await press(wrapper, 'Tab');
    expect(wrapper.emitted('close')).toHaveLength(2);

    wrapper.unmount();
  });

  it('type-ahead jumps to the next VISIBLE row starting with the typed letters (tree mode only)', async () => {
    const wrapper = mountBrowser();

    await press(wrapper, 'a'); // Attachment
    expect(activeLabel(wrapper)).toBe('Attachment');

    wrapper.unmount();
  });
});
