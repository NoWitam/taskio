// Form-builder element model + tree operations for the "next" frontend.
//
// Pure, framework-free helpers the builder UI composes: the per-type element
// factory (default `config` exactly mirroring the legacy FormBuilder templates +
// ValidFormContent), the palette metadata (category → types with next icons +
// i18n label keys), recursive tree mutations (add / move / remove / duplicate /
// update + grid-column ops), and a client-side validity mirror of
// `ValidFormContent` (server stays authoritative — this only gates the Save
// button and surfaces inline hints).
//
// IDs assigned here are CLIENT TEMP ids (`el_*`); the backend NORMALIZES
// input/section/repeater ids to snake_case keys on save, so the builder must
// always re-read `content` from the response. Self-contained: NO legacy import.
import type { IconName } from '../../../ui/primitives/icons';
import type {
  FormElement,
  FormElementConfig,
  FormElementOption,
  FormElementType,
} from '../types';

/** The three element categories (mirrors backend `FormElementCategory`). */
export type FormElementCategory = 'layout' | 'content' | 'input';

/** Input field types — the only elements a Grid column may hold. */
export const INPUT_TYPES: FormElementType[] = [
  'short_text',
  'long_text',
  'select',
  'image',
  'checkbox',
  'number',
  'date',
  'time',
  'url',
  'checklist',
];

export function isInputType(type: FormElementType): boolean {
  return INPUT_TYPES.includes(type);
}

export function categoryOf(type: FormElementType): FormElementCategory {
  if (type === 'section' || type === 'grid' || type === 'repeater') return 'layout';
  if (type === 'heading' || type === 'text_block' || type === 'divider') return 'content';
  return 'input';
}

/** Palette entry: a type + its `next` icon + the i18n key for its label. */
export interface PaletteItem {
  type: FormElementType;
  icon: IconName;
  /** i18n key under `forms.elementTypes.*`. */
  labelKey: string;
}

/** Palette grouped by category (drives ElementPalette + the tree icons). */
export const PALETTE: Array<{ category: FormElementCategory; items: PaletteItem[] }> = [
  {
    category: 'layout',
    items: [
      { type: 'section', icon: 'layout-dashboard', labelKey: 'forms.elementTypes.section' },
      { type: 'grid', icon: 'table', labelKey: 'forms.elementTypes.grid' },
      { type: 'repeater', icon: 'list-ordered', labelKey: 'forms.elementTypes.repeater' },
    ],
  },
  {
    category: 'content',
    items: [
      { type: 'heading', icon: 'heading', labelKey: 'forms.elementTypes.heading' },
      { type: 'text_block', icon: 'type', labelKey: 'forms.elementTypes.text_block' },
      { type: 'divider', icon: 'minus', labelKey: 'forms.elementTypes.divider' },
    ],
  },
  {
    category: 'input',
    items: [
      { type: 'short_text', icon: 'type', labelKey: 'forms.elementTypes.short_text' },
      { type: 'long_text', icon: 'file-text', labelKey: 'forms.elementTypes.long_text' },
      { type: 'select', icon: 'list', labelKey: 'forms.elementTypes.select' },
      { type: 'checklist', icon: 'list-checks', labelKey: 'forms.elementTypes.checklist' },
      { type: 'number', icon: 'hash', labelKey: 'forms.elementTypes.number' },
      { type: 'date', icon: 'calendar', labelKey: 'forms.elementTypes.date' },
      { type: 'time', icon: 'clock', labelKey: 'forms.elementTypes.time' },
      { type: 'url', icon: 'link', labelKey: 'forms.elementTypes.url' },
      { type: 'image', icon: 'upload', labelKey: 'forms.elementTypes.image' },
      { type: 'checkbox', icon: 'check', labelKey: 'forms.elementTypes.checkbox' },
    ],
  },
];

const ICON_BY_TYPE: Record<FormElementType, IconName> = PALETTE.reduce(
  (acc, group) => {
    for (const item of group.items) acc[item.type] = item.icon;
    return acc;
  },
  {} as Record<FormElementType, IconName>,
);

export function iconOf(type: FormElementType): IconName {
  return ICON_BY_TYPE[type] ?? 'file-text';
}

/** Generate a client-side temporary element id (server normalizes on save). */
export function genTempId(): string {
  return `el_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 9)}`;
}

/**
 * Create a new element with its default `config`, mirroring the legacy
 * FormBuilder templates 1:1. `label` (the localized type name) seeds the
 * name/label/text where the type uses one.
 */
export function createElement(type: FormElementType, label: string): FormElement {
  const id = genTempId();
  switch (type) {
    case 'section':
      return { id, type, config: { name: label, icon: '', description: '', children: [] } };
    case 'grid':
      return { id, type, config: { columns: [] } };
    case 'repeater':
      return { id, type, config: { name: label, icon: '', description: '', min: 1, max: 5, children: [] } };
    case 'heading':
      return { id, type, config: { level: 2, text: label } };
    case 'text_block':
      return { id, type, config: { content: '' } };
    case 'divider':
      return { id, type, config: {} };
    case 'short_text':
      return { id, type, config: { label, placeholder: '', hint: '', required: false } };
    case 'long_text':
      return { id, type, config: { label, placeholder: '', hint: '', required: false, rows: 4 } };
    case 'select':
      return { id, type, config: { label, placeholder: '', hint: '', required: false, multiple: false, options: [] } };
    case 'checklist':
      return { id, type, config: { label, hint: '', required: false, options: [] } };
    case 'number':
      return { id, type, config: { label, placeholder: '', hint: '', required: false, step: 1 } };
    case 'date':
      return { id, type, config: { label, placeholder: '', hint: '', required: false } };
    case 'time':
      return { id, type, config: { label, placeholder: '', hint: '', required: false } };
    case 'url':
      return { id, type, config: { label, placeholder: 'https://', hint: '', required: false } };
    case 'image':
      return {
        id,
        type,
        config: {
          label,
          hint: '',
          required: false,
          maxSize: 5,
          acceptedTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        },
      };
    case 'checkbox':
      return { id, type, config: { label, hint: '' } };
    default:
      return { id, type, config: {} };
  }
}

/** A blank Grid column (50% width, empty). */
export function createGridColumn(): NonNullable<FormElementConfig['columns']>[number] {
  return { width: 50, element: null };
}

/** A new option for select / checklist. */
export function createOption(): FormElementOption {
  return { value: `option_${Date.now().toString(36)}`, label: '', icon: '', hint: '' };
}

// --- Tree traversal --------------------------------------------------------

export function childrenOf(element: FormElement): FormElement[] {
  if (element.type === 'section' || element.type === 'repeater') {
    return element.config.children ?? [];
  }
  if (element.type === 'grid') {
    return (element.config.columns ?? []).map((c) => c.element).filter((e): e is FormElement => !!e);
  }
  return [];
}

export function canHaveChildren(type: FormElementType): boolean {
  return type === 'section' || type === 'repeater';
}

/** Find an element anywhere in the tree (depth-first). */
export function findElement(els: FormElement[], id: string): FormElement | null {
  for (const el of els) {
    if (el.id === id) return el;
    const found = findElement(childrenOf(el), id);
    if (found) return found;
  }
  return null;
}

/** Update one element's `config` in place (returns true when applied). */
export function updateElementConfig(
  els: FormElement[],
  id: string,
  config: FormElementConfig,
): boolean {
  for (let i = 0; i < els.length; i += 1) {
    if (els[i].id === id) {
      els[i] = { ...els[i], config };
      return true;
    }
    const el = els[i];
    if (el.type === 'section' || el.type === 'repeater') {
      if (el.config.children && updateElementConfig(el.config.children, id, config)) return true;
    }
    if (el.type === 'grid') {
      for (const col of el.config.columns ?? []) {
        if (col.element && col.element.id === id) {
          col.element = { ...col.element, config };
          return true;
        }
        if (col.element && updateElementConfig([col.element], id, config)) return true;
      }
    }
  }
  return false;
}

/** Remove an element (incl. clearing a grid column) in place. */
export function removeElement(els: FormElement[], id: string): boolean {
  const idx = els.findIndex((el) => el.id === id);
  if (idx !== -1) {
    els.splice(idx, 1);
    return true;
  }
  for (const el of els) {
    if (el.type === 'section' || el.type === 'repeater') {
      if (el.config.children && removeElement(el.config.children, id)) return true;
    }
    if (el.type === 'grid') {
      const columns = el.config.columns ?? [];
      for (const col of columns) {
        if (col.element?.id === id) {
          col.element = null;
          return true;
        }
        if (col.element && removeElement([col.element], id)) return true;
      }
    }
  }
  return false;
}

/** Recursively assign fresh temp ids (used when duplicating). */
export function regenerateIds(element: FormElement): void {
  element.id = genTempId();
  if (element.type === 'section' || element.type === 'repeater') {
    (element.config.children ?? []).forEach(regenerateIds);
  }
  if (element.type === 'grid') {
    (element.config.columns ?? []).forEach((col) => {
      if (col.element) regenerateIds(col.element);
    });
  }
}

/**
 * Deep-clone PLAIN data via JSON. Element configs are JSON-safe (strings /
 * numbers / booleans / arrays / nested objects), and JSON reads THROUGH Vue
 * reactive proxies — unlike `structuredClone`, which throws `DataCloneError` on a
 * proxy. Use this anywhere builder state (reactive) must be copied.
 */
export function clonePlain<T>(value: T): T {
  return JSON.parse(JSON.stringify(value)) as T;
}

/** Duplicate an element right after itself (with regenerated ids). */
export function duplicateElement(els: FormElement[], id: string): FormElement | null {
  const idx = els.findIndex((el) => el.id === id);
  if (idx !== -1) {
    const copy = clonePlain(els[idx]);
    regenerateIds(copy);
    els.splice(idx + 1, 0, copy);
    return copy;
  }
  for (const el of els) {
    if (el.type === 'section' || el.type === 'repeater') {
      if (el.config.children) {
        const copy = duplicateElement(el.config.children, id);
        if (copy) return copy;
      }
    }
    if (el.type === 'grid') {
      for (const col of el.config.columns ?? []) {
        if (col.element && col.element.id !== id) {
          const copy = duplicateElement([col.element], id);
          if (copy) return copy; // nested inside a column element
        }
      }
    }
  }
  return null;
}

/** Append a child to a section/repeater (returns true when applied). */
export function addChild(els: FormElement[], parentId: string, child: FormElement): boolean {
  for (const el of els) {
    if (el.id === parentId && (el.type === 'section' || el.type === 'repeater')) {
      el.config.children = [...(el.config.children ?? []), child];
      return true;
    }
    if ((el.type === 'section' || el.type === 'repeater') && el.config.children) {
      if (addChild(el.config.children, parentId, child)) return true;
    }
    if (el.type === 'grid') {
      for (const col of el.config.columns ?? []) {
        if (col.element && addChild([col.element], parentId, child)) return true;
      }
    }
  }
  return false;
}

/** Find the sibling array that directly contains `id` (root or children). */
function findSiblings(els: FormElement[], id: string): FormElement[] | null {
  if (els.some((el) => el.id === id)) return els;
  for (const el of els) {
    if ((el.type === 'section' || el.type === 'repeater') && el.config.children) {
      const found = findSiblings(el.config.children, id);
      if (found) return found;
    }
  }
  return null;
}

/** Move an element up/down within its immediate sibling list. */
export function moveElement(els: FormElement[], id: string, dir: -1 | 1): boolean {
  const siblings = findSiblings(els, id);
  if (!siblings) return false;
  const idx = siblings.findIndex((el) => el.id === id);
  const target = idx + dir;
  if (idx === -1 || target < 0 || target >= siblings.length) return false;
  [siblings[idx], siblings[target]] = [siblings[target], siblings[idx]];
  return true;
}

// --- Grid column ops -------------------------------------------------------

export function findGrid(els: FormElement[], gridId: string): FormElement | null {
  const el = findElement(els, gridId);
  return el && el.type === 'grid' ? el : null;
}

// --- Validity mirror (ValidFormContent) ------------------------------------

/** True when the tree contains at least one input field (recursive). */
export function hasInputFields(els: FormElement[]): boolean {
  for (const el of els) {
    if (isInputType(el.type)) return true;
    if ((el.type === 'section' || el.type === 'repeater') && el.config.children) {
      if (hasInputFields(el.config.children)) return true;
    }
    if (el.type === 'grid') {
      for (const col of el.config.columns ?? []) {
        if (col.element && hasInputFields([col.element])) return true;
      }
    }
  }
  return false;
}

/** A structural issue surfaced inline (server remains authoritative). */
export interface ContentIssue {
  id: string;
  /** i18n key under `forms.builder.issues.*`. */
  key: string;
}

const VALID_WIDTHS = [25, 50, 75, 100];

/**
 * Collect structural issues mirroring `ValidFormContent` (non-exhaustive — the
 * server validates fully): missing names/labels, empty select/checklist options,
 * grid width rules, repeater min/max.
 */
export function collectIssues(els: FormElement[]): ContentIssue[] {
  const issues: ContentIssue[] = [];

  const walk = (list: FormElement[]): void => {
    for (const el of list) {
      const c = el.config;
      switch (el.type) {
        case 'section':
          if (!c.name?.trim()) issues.push({ id: el.id, key: 'forms.builder.issues.sectionName' });
          walk(c.children ?? []);
          break;
        case 'repeater':
          if (!c.name?.trim()) issues.push({ id: el.id, key: 'forms.builder.issues.repeaterName' });
          if ((c.min ?? 0) > (c.max ?? 1)) issues.push({ id: el.id, key: 'forms.builder.issues.repeaterRange' });
          walk(c.children ?? []);
          break;
        case 'grid': {
          const cols = c.columns ?? [];
          if (cols.some((col) => !VALID_WIDTHS.includes(col.width))) {
            issues.push({ id: el.id, key: 'forms.builder.issues.gridWidth' });
          }
          if (cols.reduce((sum, col) => sum + col.width, 0) > 100) {
            issues.push({ id: el.id, key: 'forms.builder.issues.gridTotal' });
          }
          cols.forEach((col) => {
            if (col.element) walk([col.element]);
          });
          break;
        }
        case 'heading':
          if (!c.text?.trim()) issues.push({ id: el.id, key: 'forms.builder.issues.headingText' });
          break;
        case 'select':
        case 'checklist':
          if (!c.label?.trim()) issues.push({ id: el.id, key: 'forms.builder.issues.label' });
          if (!c.options || c.options.length === 0) {
            issues.push({ id: el.id, key: 'forms.builder.issues.options' });
          } else if (c.options.some((o) => !o.value?.trim() || !o.label?.trim())) {
            issues.push({ id: el.id, key: 'forms.builder.issues.optionValue' });
          }
          break;
        case 'checkbox':
          break; // checkbox label is optional per ValidFormContent
        case 'text_block':
        case 'divider':
          break;
        default:
          if (!c.label?.trim()) issues.push({ id: el.id, key: 'forms.builder.issues.label' });
      }
    }
  };

  walk(els);
  return issues;
}
