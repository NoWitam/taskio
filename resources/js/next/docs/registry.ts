// Styleguide story registry.
//
// The gallery nav is generated from this array. To document a new component,
// add a `{ section, name, component }` entry pointing at a story page under
// `docs/pages/`. Sections render in the fixed `SECTION_ORDER` below; within a
// section, stories keep their array order. See docs/README.md.
import type { Component } from 'vue';

export type StyleguideSection =
  | 'Foundations'
  | 'Primitives'
  | 'Layout'
  | 'Forms'
  | 'Date & time'
  | 'Overlay'
  | 'Feedback'
  | 'Disclosure'
  | 'Navigation'
  | 'Data'
  | 'Patterns'
  | 'Editor'
  | 'Modules';

export interface StyleguideStory {
  /** Nav grouping. */
  section: StyleguideSection;
  /** Display name + nav label (must be unique within a section). */
  name: string;
  /** Lazy story component. */
  component: () => Promise<Component> | Component;
}

/** Fixed nav order. Empty sections still render as "coming soon" placeholders. */
export const SECTION_ORDER: StyleguideSection[] = [
  'Foundations',
  'Primitives',
  'Layout',
  'Forms',
  'Date & time',
  'Overlay',
  'Feedback',
  'Disclosure',
  'Navigation',
  'Data',
  'Patterns',
  'Editor',
  'Modules',
];

export const stories: StyleguideStory[] = [
  {
    section: 'Foundations',
    name: 'Design Tokens',
    component: () => import('./pages/TokensPage.vue'),
  },
  {
    section: 'Foundations',
    name: 'Internationalization',
    component: () => import('./pages/I18nPage.vue'),
  },
  {
    section: 'Primitives',
    name: 'Icon',
    component: () => import('./pages/IconPage.vue'),
  },
  {
    section: 'Primitives',
    name: 'Button',
    component: () => import('./pages/ButtonPage.vue'),
  },
  {
    section: 'Primitives',
    name: 'Link',
    component: () => import('./pages/LinkPage.vue'),
  },
  {
    section: 'Primitives',
    name: 'Badge',
    component: () => import('./pages/BadgePage.vue'),
  },
  {
    section: 'Primitives',
    name: 'Avatar',
    component: () => import('./pages/AvatarPage.vue'),
  },
  {
    section: 'Primitives',
    name: 'Spinner',
    component: () => import('./pages/SpinnerPage.vue'),
  },
  {
    section: 'Primitives',
    name: 'Text & Heading',
    component: () => import('./pages/TypographyPage.vue'),
  },
  {
    section: 'Primitives',
    name: 'Kbd',
    component: () => import('./pages/KbdPage.vue'),
  },
  {
    section: 'Layout',
    name: 'Container',
    component: () => import('./pages/ContainerPage.vue'),
  },
  {
    section: 'Layout',
    name: 'Stack',
    component: () => import('./pages/StackPage.vue'),
  },
  {
    section: 'Layout',
    name: 'Grid',
    component: () => import('./pages/GridPage.vue'),
  },
  {
    section: 'Layout',
    name: 'Surface',
    component: () => import('./pages/SurfacePage.vue'),
  },
  {
    section: 'Layout',
    name: 'Card',
    component: () => import('./pages/CardPage.vue'),
  },
  {
    section: 'Layout',
    name: 'App Shell',
    component: () => import('./pages/AppShellPage.vue'),
  },
  {
    section: 'Forms',
    name: 'FormField',
    component: () => import('./pages/FormFieldPage.vue'),
  },
  {
    section: 'Forms',
    name: 'FieldShell',
    component: () => import('./pages/FieldShellPage.vue'),
  },
  {
    section: 'Forms',
    name: 'TextInput',
    component: () => import('./pages/TextInputPage.vue'),
  },
  {
    section: 'Forms',
    name: 'Textarea',
    component: () => import('./pages/TextareaPage.vue'),
  },
  {
    section: 'Forms',
    name: 'Select',
    component: () => import('./pages/SelectPage.vue'),
  },
  {
    section: 'Forms',
    name: 'UserSelect',
    component: () => import('./pages/UserSelectPage.vue'),
  },
  {
    section: 'Forms',
    name: 'LabelSelect',
    component: () => import('./pages/LabelSelectPage.vue'),
  },
  {
    section: 'Forms',
    name: 'Checkbox',
    component: () => import('./pages/CheckboxPage.vue'),
  },
  {
    section: 'Forms',
    name: 'Radio',
    component: () => import('./pages/RadioPage.vue'),
  },
  {
    section: 'Forms',
    name: 'Switch',
    component: () => import('./pages/SwitchPage.vue'),
  },
  {
    section: 'Forms',
    name: 'Slider',
    component: () => import('./pages/SliderPage.vue'),
  },
  {
    section: 'Forms',
    name: 'NumberInput',
    component: () => import('./pages/NumberInputPage.vue'),
  },
  {
    section: 'Forms',
    name: 'ColorInput',
    component: () => import('./pages/ColorInputPage.vue'),
  },
  {
    section: 'Forms',
    name: 'IconInput',
    component: () => import('./pages/IconInputPage.vue'),
  },
  {
    section: 'Forms',
    name: 'FileDropzone',
    component: () => import('./pages/FileDropzonePage.vue'),
  },
  {
    section: 'Forms',
    name: 'PillGroupInput',
    component: () => import('./pages/PillGroupInputPage.vue'),
  },
  {
    section: 'Forms',
    name: 'SegmentedControl',
    component: () => import('./pages/SegmentedControlPage.vue'),
  },
  {
    section: 'Forms',
    name: 'Form composition',
    component: () => import('./pages/FormCompositionPage.vue'),
  },
  {
    section: 'Date & time',
    name: 'DatePicker',
    component: () => import('./pages/DatePickerPage.vue'),
  },
  {
    section: 'Date & time',
    name: 'TimePicker',
    component: () => import('./pages/TimePickerPage.vue'),
  },
  {
    section: 'Date & time',
    name: 'DateTimePicker',
    component: () => import('./pages/DateTimePickerPage.vue'),
  },
  {
    section: 'Date & time',
    name: 'DateRangePicker',
    component: () => import('./pages/DateRangePickerPage.vue'),
  },
  {
    section: 'Date & time',
    name: 'MonthPicker',
    component: () => import('./pages/MonthPickerPage.vue'),
  },
  {
    section: 'Overlay',
    name: 'Tooltip',
    component: () => import('./pages/TooltipPage.vue'),
  },
  {
    section: 'Overlay',
    name: 'Popover',
    component: () => import('./pages/PopoverPage.vue'),
  },
  {
    section: 'Overlay',
    name: 'DropdownMenu',
    component: () => import('./pages/DropdownMenuPage.vue'),
  },
  {
    section: 'Overlay',
    name: 'Modal',
    component: () => import('./pages/ModalPage.vue'),
  },
  {
    section: 'Overlay',
    name: 'ConfirmDialog',
    component: () => import('./pages/ConfirmDialogPage.vue'),
  },
  {
    section: 'Overlay',
    name: 'Toast',
    component: () => import('./pages/ToastPage.vue'),
  },
  {
    section: 'Overlay',
    name: 'Drawer',
    component: () => import('./pages/DrawerPage.vue'),
  },
  {
    section: 'Overlay',
    name: 'Command palette',
    component: () => import('./pages/CommandPalettePage.vue'),
  },
  {
    section: 'Feedback',
    name: 'Alert',
    component: () => import('./pages/AlertPage.vue'),
  },
  {
    section: 'Feedback',
    name: 'Banner',
    component: () => import('./pages/BannerPage.vue'),
  },
  {
    section: 'Feedback',
    name: 'Progress',
    component: () => import('./pages/ProgressPage.vue'),
  },
  {
    section: 'Disclosure',
    name: 'Accordion',
    component: () => import('./pages/AccordionPage.vue'),
  },
  {
    section: 'Navigation',
    name: 'Tabs',
    component: () => import('./pages/TabsPage.vue'),
  },
  {
    section: 'Navigation',
    name: 'Breadcrumbs',
    component: () => import('./pages/BreadcrumbsPage.vue'),
  },
  {
    section: 'Navigation',
    name: 'Pagination',
    component: () => import('./pages/PaginationPage.vue'),
  },
  {
    section: 'Navigation',
    name: 'Stepper',
    component: () => import('./pages/StepperPage.vue'),
  },
  {
    section: 'Data',
    name: 'Skeleton',
    component: () => import('./pages/SkeletonPage.vue'),
  },
  {
    section: 'Data',
    name: 'Table',
    component: () => import('./pages/TablePage.vue'),
  },
  {
    section: 'Data',
    name: 'EmptyState',
    component: () => import('./pages/EmptyStatePage.vue'),
  },
  {
    section: 'Data',
    name: 'StatusBadge',
    component: () => import('./pages/StatusBadgePage.vue'),
  },
  {
    section: 'Data',
    name: 'Tree',
    component: () => import('./pages/TreePage.vue'),
  },
  {
    section: 'Data',
    name: 'DescriptionList',
    component: () => import('./pages/DescriptionListPage.vue'),
  },
  {
    section: 'Patterns',
    name: 'EntityCard',
    component: () => import('./pages/EntityCardPage.vue'),
  },
  {
    section: 'Patterns',
    name: 'StatCard & StatsGrid',
    component: () => import('./pages/StatCardPage.vue'),
  },
  {
    section: 'Patterns',
    name: 'Timeline',
    component: () => import('./pages/TimelinePage.vue'),
  },
  {
    section: 'Patterns',
    name: 'FilterBar',
    component: () => import('./pages/FilterBarPage.vue'),
  },
  {
    section: 'Patterns',
    name: 'Saved Views',
    component: () => import('./pages/SavedViewsPage.vue'),
  },
  {
    section: 'Patterns',
    name: 'PageHeader',
    component: () => import('./pages/PageHeaderPage.vue'),
  },
  {
    section: 'Editor',
    name: 'MarkdownEditor',
    component: () => import('./pages/MarkdownEditorPage.vue'),
  },
  {
    section: 'Modules',
    name: 'Approvals',
    component: () => import('./pages/ApprovalsPage.vue'),
  },
  {
    section: 'Modules',
    name: 'Bots',
    component: () => import('./pages/BotsPage.vue'),
  },
  {
    section: 'Modules',
    name: 'Workflows',
    component: () => import('./pages/WorkflowsPage.vue'),
  },
];

export interface StyleguideNavSection {
  section: StyleguideSection;
  stories: StyleguideStory[];
}

/** Group stories by section, preserving `SECTION_ORDER`. */
export function groupedStories(): StyleguideNavSection[] {
  return SECTION_ORDER.map((section) => ({
    section,
    stories: stories.filter((s) => s.section === section),
  }));
}

/** Unique slug for routing/anchors within the gallery. */
export function storyId(story: Pick<StyleguideStory, 'section' | 'name'>): string {
  return `${story.section}--${story.name}`
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/(^-|-$)/g, '');
}
