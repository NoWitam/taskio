// English catalog for the isolated "next" frontend i18n.
//
// A nested, typed object. The `pl.ts` catalog MUST keep 1:1 key parity with this
// file (asserted by a unit test). The `common` namespace reuses the legacy
// `resources/js/locales/en.json` values so content stays consistent across the
// two frontends — but this file is self-contained (NO legacy import).
//
// Every USER-FACING string in `resources/js/next/ui/**` should resolve through a
// key here via `t('namespace.key', 'Fallback')`. Add new keys to BOTH catalogs.

export const en = {
  // Shared, generic UI words (mirrors legacy locales/en.json `common`).
  common: {
    save: 'Save',
    cancel: 'Cancel',
    delete: 'Delete',
    edit: 'Edit',
    create: 'Create',
    update: 'Update',
    add: 'Add',
    remove: 'Remove',
    close: 'Close',
    submit: 'Submit',
    confirm: 'Confirm',
    yes: 'Yes',
    no: 'No',
    clear: 'Clear',
    reset: 'Reset',
    search: 'Search',
    apply: 'Apply',
    select: 'Select',
    back: 'Back',
    next: 'Next',
    previous: 'Previous',
    loading: 'Loading…',
    error: 'Error',
    success: 'Success',
    warning: 'Warning',
    info: 'Info',
    retry: 'Retry',
    done: 'Done',
    open: 'Open',
    optional: 'Optional',
    required: 'Required',
  },

  // Language switcher.
  language: {
    label: 'Language',
    switch: 'Change language',
    polish: 'Polski',
    english: 'English',
  },

  // App branding / theme.
  app: {
    name: 'Taskio',
    tagline: 'Forms, tasks & approvals',
    themeToLight: 'Switch to light theme',
    themeToDark: 'Switch to dark theme',
    openNavigation: 'Open navigation',
    closeNavigation: 'Close navigation',
    skipToContent: 'Skip to main content',
  },

  // Authentication / login.
  auth: {
    title: 'Sign in',
    subtitle: 'Sign in to your Taskio workspace',
    email: 'Email',
    emailPlaceholder: 'you@example.com',
    password: 'Password',
    passwordPlaceholder: 'Your password',
    remember: 'Remember me',
    submit: 'Sign in',
    submitting: 'Signing in…',
    showPassword: 'Show password',
    hidePassword: 'Hide password',
    errorTitle: 'Sign in failed',
    invalidCredentials: 'The email or password is incorrect.',
    validationFailed: 'Please check the form and try again.',
    genericError: 'Something went wrong. Please try again.',
    tooManyAttempts: 'Too many attempts. Please wait and try again.',
  },

  // Primary navigation (sidebar).
  nav: {
    sectionWorkspace: 'Workspace',
    sectionComingSoon: 'Coming soon',
    dashboard: 'Dashboard',
    tasks: 'Tasks',
    forms: 'Forms',
    approvals: 'Approvals',
    labels: 'Labels',
    comingSoon: 'Coming soon',
  },

  // User menu (navbar avatar dropdown).
  userMenu: {
    label: 'Account menu',
    open: 'Open account menu',
    account: 'Account',
    workspace: 'Workspace',
    switchWorkspace: 'Switch workspace',
    currentWorkspace: 'Current workspace',
    logout: 'Sign out',
    signedInAs: 'Signed in as',
  },

  // Dashboard page.
  dashboard: {
    title: 'Dashboard',
    welcome: 'Welcome back, {name}',
    welcomeAnon: 'Welcome back',
    subtitle: 'Here’s an overview of your workspace.',
    statsLabel: 'Workspace overview',
    statWorkspaces: 'Workspaces',
    statWorkspacesHelper: 'Workspaces you can access',
    statPermissions: 'Permissions',
    statPermissionsHelper: 'Granted in this workspace',
    statModules: 'Modules',
    statModulesHelper: 'Available in Taskio',
    modulesTitle: 'Explore modules',
    open: 'Open',
    comingSoon: 'Coming soon',
    moduleDashboardDesc: 'Your workspace overview and quick links.',
    moduleTasksDesc: 'Track and assign work across your workspace.',
    moduleFormsDesc: 'Build and publish dynamic forms.',
    moduleApprovalsDesc: 'Review and approve submissions.',
    moduleLabelsDesc: 'Organise everything with labels.',
    recentActivityTitle: 'Recent activity',
    recentActivityEmptyTitle: 'No activity yet',
    recentActivityEmptyDesc: 'Activity will appear here as you work. This area is a placeholder until the activity feed is available.',
    placeholderBadge: 'Placeholder',
  },

  // Tasks module (BROWSE: list / board / filters).
  tasks: {
    title: 'Tasks',
    subtitle: 'Track and assign work across your workspace.',
    newTask: 'New task',
    newTaskSoon: 'Creating tasks is coming soon.',
    resultsCount: '{count} tasks',
    open: 'Open task: {title}',
    statuses: {
      to_do: 'To do',
      in_progress: 'In progress',
      in_test: 'In testing',
      done: 'Done',
      archive: 'Archive',
      trash: 'Trash',
    },
    priorities: {
      urgent: 'Urgent',
      high: 'High',
      medium: 'Medium',
      low: 'Low',
    },
    priorityLabel: 'Priority: {label}',
    tabs: {
      label: 'Board view',
      active: 'Active',
      archive: 'Archive',
      trash: 'Trash',
    },
    autoArchive: {
      title: 'Automatic archiving',
      body: 'Completed tasks are automatically archived after 30 days.',
    },
    trashInfo: {
      title: 'Trash',
      body: 'Deleted tasks can be restored. Permanent deletion cannot be undone.',
    },
    filters: {
      search: 'Search tasks…',
      priority: 'Priority',
      priorityAll: 'All priorities',
      assignee: 'Assignee',
      assigneeSearch: 'Search people…',
      labels: 'Labels',
      labelsSearch: 'Search labels…',
      labelOperator: 'Match labels',
      and: 'All',
      or: 'Any',
      andHelp: 'Tasks that have all selected labels.',
      orHelp: 'Tasks with at least one selected label.',
      dateRange: 'Deadline range',
      datePreset: 'Deadline',
      datePresetAll: 'Any time',
      hideWithoutDeadline: 'Hide tasks without a deadline',
      clearAll: 'Clear all',
      chip: {
        search: 'Search: {value}',
        priority: 'Priority: {value}',
        assignee: 'Assignee: {count}',
        labels: 'Labels: {count}',
        dateRange: 'Deadline: {from} – {to}',
        datePreset: 'Deadline: {value}',
        hideWithoutDeadline: 'Without deadline hidden',
      },
    },
    datePresets: {
      today: 'Today',
      this_week: 'This week',
      last_week: 'Last week',
      this_month: 'This month',
    },
    deadline: {
      overdue: 'Overdue',
      atRisk: 'At risk',
      label: 'Deadline {date}',
    },
    comments: '{count} comments',
    inApproval: 'In approval',
    assignedTo: 'Assigned to {name}',
    moreLabels: '{count} more labels',
    loadingMore: 'Loading more tasks…',
    empty: {
      title: 'No tasks here',
      description: 'Tasks in this status will appear here.',
      searchTitle: 'No tasks match your filters',
      searchDescription: 'Try adjusting or clearing the filters.',
      columnTitle: 'No tasks',
    },
    error: {
      title: 'Couldn’t load tasks',
      description: 'Something went wrong while loading. Please try again.',
      fetch: 'Failed to load tasks.',
      retry: 'Retry',
    },

    // Detail Drawer.
    detail: {
      title: 'Task details',
      loadErrorTitle: 'Couldn’t load this task',
      loadErrorDescription: 'Something went wrong. Please try again.',
      retry: 'Retry',
      metadata: 'Details',
      assignee: 'Assignee',
      creator: 'Created by',
      deadline: 'Deadline',
      noDeadline: 'No deadline',
      priority: 'Priority',
      status: 'Status',
      labels: 'Labels',
      noLabels: 'No labels',
      created: 'Created',
      updated: 'Updated',
      description: 'Description',
      noDescription: 'No description provided.',
      attachments: 'Attachments',
      noAttachments: 'No attachments.',
      download: 'Download {name}',
      editTitle: 'Edit title',
      statusActions: 'Status',
      changeStatus: 'Move to {status}',
      changeStatusLabel: 'Change status',
      startTask: 'Start',
      sendToTest: 'Send to tests',
      complete: 'Complete',
      backToProgress: 'Back to progress',
      edit: 'Edit',
      delete: 'Delete',
      deleteTitle: 'Delete this task?',
      deleteConfirm: 'This moves the task to trash. You can restore it later.',
      restore: 'Restore',
      forceDelete: 'Delete permanently',
      forceDeleteTitle: 'Delete permanently?',
      forceDeleteConfirm: 'This permanently deletes the task. This cannot be undone.',
      inApproval: 'In approval',
      inApprovalHint: 'Status changes are locked while this task is in an approval process.',
      tabDetails: 'Details',
      tabComments: 'Comments',
      tabActivity: 'Activity',
      tabForm: 'Form',
      tabChecklist: 'Checklist',
      tabApproval: 'Approval',
      paneProperties: 'Properties',
      paneContent: 'Content',
      paneComments: 'Comments',
      comingSoon: 'Coming soon',
      comingSoonDescription: 'This section is being prepared.',
    },

    // Create / edit form Modal.
    form: {
      createTitle: 'New task',
      editTitle: 'Edit task',
      titleLabel: 'Title',
      titlePlaceholder: 'What needs to be done?',
      description: 'Description',
      descriptionPlaceholder: 'Add more detail…',
      priority: 'Priority',
      deadline: 'Deadline',
      assignee: 'Assignee',
      assigneePlaceholder: 'Select a person',
      assigneeSearch: 'Search people…',
      labels: 'Labels',
      labelsPlaceholder: 'Select labels',
      labelsSearch: 'Search labels…',
      create: 'Create task',
      save: 'Save changes',
      cancel: 'Cancel',
      required: 'This field is required.',
      errorTitle: 'Please fix the errors below',
      errorDescription: 'Some fields need your attention before saving.',
    },

    // Comments section.
    commentsPanel: {
      title: 'Comments',
      add: 'Add comment',
      placeholder: 'Write a comment…',
      submit: 'Comment',
      submitting: 'Posting…',
      empty: 'No comments yet',
      emptyDescription: 'Be the first to add a comment.',
      loadMore: 'Load more',
      edited: 'edited',
      edit: 'Edit',
      delete: 'Delete',
      save: 'Save',
      cancel: 'Cancel',
      deleteTitle: 'Delete this comment?',
      deleteConfirm: 'This permanently removes the comment.',
      loadError: 'Couldn’t load comments.',
    },

    // Changelog / activity.
    changelog: {
      title: 'Activity',
      empty: 'No activity yet',
      emptyDescription: 'Changes to this task will appear here.',
      loadError: 'Couldn’t load activity.',
      retry: 'Retry',
      system: 'System',
    },

    // Toast feedback.
    toasts: {
      created: 'Task created',
      updated: 'Task updated',
      deleted: 'Task moved to trash',
      forceDeleted: 'Task permanently deleted',
      restored: 'Task restored',
      statusChanged: 'Status updated',
      commentAdded: 'Comment added',
      commentUpdated: 'Comment updated',
      commentDeleted: 'Comment deleted',
      error: 'Something went wrong',
      saveError: 'Couldn’t save the task',
      statusError: 'Couldn’t change the status',
      deleteError: 'Couldn’t delete the task',
      commentError: 'Couldn’t post the comment',
    },
  },

  // Select / combobox.
  select: {
    placeholder: 'Select…',
    searchPlaceholder: 'Search…',
    searchLabel: 'Search options',
    empty: 'No results',
    loading: 'Loading options…',
    loadingMore: 'Loading more options…',
    loadError: 'Couldn’t load options.',
    loadMoreError: 'Failed to load more.',
    retry: 'Retry',
    clear: 'Clear selection',
    summaryCount: '{count} selected',
    removeItem: 'Remove {label}',
  },

  // UserSelect (global people picker).
  userSelect: {
    placeholder: 'Select a person',
    placeholderMultiple: 'Select people',
    search: 'Search people…',
    ariaLabel: 'Select people',
  },

  // LabelSelect (global label picker with in-dropdown AND/OR operator).
  labelSelect: {
    placeholder: 'Select labels',
    search: 'Search labels…',
    ariaLabel: 'Select labels',
    operatorLabel: 'Match labels',
    operatorAll: 'All',
    operatorAny: 'Any',
    operatorAllHelp: 'Match tasks that have all selected labels.',
    operatorAnyHelp: 'Match tasks with at least one selected label.',
    create: 'New label',
    createTitle: 'Create label',
    nameLabel: 'Name',
    namePlaceholder: 'Enter a name…',
    colorLabel: 'Color (optional)',
    iconLabel: 'Icon (optional)',
    cancel: 'Cancel',
    submit: 'Create',
    nameRequired: 'Label name is required.',
    createError: 'Could not create the label.',
  },

  // Attachments (TaskAttachmentsField + file dropzone).
  attachments: {
    title: 'Attachments',
    hint: 'Up to {max} files',
    download: 'Download {name}',
    remove: 'Remove {name}',
    removeError: 'Could not remove the attachment.',
    uploadError: 'Could not upload the file.',
    downloadError: 'Could not download the file.',
    limitReached: 'Attachment limit reached.',
    preview: 'Preview {name}',
    previewHint: 'Click to enlarge',
    previewTitle: 'Attachment preview',
  },

  // Pagination.
  pagination: {
    previous: 'Previous',
    next: 'Next',
    page: 'Page {page}',
    pageSize: 'Per page',
    pageSizeLabel: 'Items per page',
    summary: '{from}–{to} of {total}',
  },

  // Data table.
  table: {
    empty: 'No data',
    error: 'Something went wrong',
    retry: 'Try again',
    selectAll: 'Select all rows',
    selectRow: 'Select row {index}',
    actions: 'Actions',
    sortBy: 'Sort by {label}',
  },

  // EmptyState defaults.
  emptyState: {
    title: 'Nothing here yet',
    searchTitle: 'No results found',
    errorTitle: 'Something went wrong',
    clearFilters: 'Clear filters',
  },

  // ConfirmDialog defaults.
  confirm: {
    title: 'Are you sure?',
    confirm: 'Confirm',
    cancel: 'Cancel',
  },

  // Modal / dialog.
  modal: {
    close: 'Close dialog',
  },

  // Keyboard key hints (Kbd) — spoken names + short labels for non-glyph keys.
  kbd: {
    command: 'Command',
    control: 'Control',
    ctrl: 'Ctrl',
    shift: 'Shift',
    option: 'Option',
    alt: 'Alt',
    enter: 'Enter',
    esc: 'Esc',
    escape: 'Escape',
    tab: 'Tab',
    space: 'Space',
    backspace: 'Backspace',
    arrowUp: 'Arrow up',
    arrowDown: 'Arrow down',
    arrowLeft: 'Arrow left',
    arrowRight: 'Arrow right',
  },

  // Command palette (⌘K / Ctrl+K launcher).
  commandPalette: {
    label: 'Command palette',
    searchLabel: 'Search commands',
    placeholder: 'Type a command or search…',
    empty: 'No results',
    loading: 'Loading commands…',
  },

  // Tree (hierarchical view).
  tree: {
    label: 'Tree',
    expand: 'Expand',
    collapse: 'Collapse',
    loading: 'Loading…',
  },

  // Drawer / sheet (side panel).
  drawer: {
    close: 'Close panel',
  },

  // Inline Alert message block.
  alert: {
    dismiss: 'Dismiss',
  },

  // Page/app-level Banner.
  banner: {
    dismiss: 'Dismiss',
  },

  // Stepper (multi-step / wizard).
  stepper: {
    statusComplete: 'completed',
    statusCurrent: 'current',
    statusUpcoming: 'upcoming',
    statusError: 'error',
    stepLabel: '{label}, step {index}: {status}',
  },

  // Toast notifications.
  toast: {
    dismiss: 'Dismiss notification',
  },

  // FilterBar.
  filterBar: {
    searchPlaceholder: 'Search…',
    searchLabel: 'Search and filter',
    barLabel: 'Filters',
    clearAll: 'Clear all',
    noFilters: 'No active filters',
    removeFilter: 'Remove filter: {label}',
  },

  // Shared overflow "+N" pill (Select / PillGroupInput / FilterBar).
  chipOverflow: {
    more: '{count} more: {items}',
    hiddenItems: 'Hidden items',
    remove: 'Remove {label}',
  },

  // Color picker.
  colorInput: {
    placeholder: 'Select a color…',
    triggerLabel: 'Choose a color',
    valueLabel: 'Color {value}',
    emptyLabel: 'No color selected',
    clear: 'Clear color',
    hexLabel: 'Hex color value',
    hexInvalid: 'Enter a valid #rgb or #rrggbb hex.',
    saturation: 'Saturation and brightness',
    saturationValue: 'Saturation {s}%, brightness {v}%',
    hue: 'Hue',
    presets: 'Presets',
    presetColors: 'Preset colors',
    done: 'Done',
  },

  // Icon picker.
  iconInput: {
    placeholder: 'Select an icon…',
    triggerLabel: 'Choose an icon',
    valueLabel: 'Icon {name}',
    emptyLabel: 'No icon selected',
    clear: 'Clear icon',
    searchPlaceholder: 'Search icons…',
    searchLabel: 'Search icons',
    gridLabel: 'Icons',
    noResults: 'No icons found',
    noMatch: 'Nothing matches “{query}”.',
  },

  // File dropzone.
  fileDropzone: {
    label: 'Upload files',
    titleMultiple: 'Drop files here or click to browse',
    titleSingle: 'Drop a file here or click to browse',
    remove: 'Remove {name}',
    uploading: 'Uploading {name}',
    fileAddedOne: '1 file added',
    filesAddedMany: '{count} files added',
    rejected: '{count} rejected: {reasons}',
    fileRemoved: '{name} removed',
    typeNotAllowed: '{name}: type not allowed',
    exceedsSize: '{name}: exceeds {size}',
    maxFiles: '{name}: max {count} files',
    maxSizeEach: 'max {size} each',
    upToFiles: 'up to {count} files',
  },

  // Tag / pill group input.
  pillGroup: {
    placeholder: 'Add a tag…',
    inputLabel: 'Add tags',
    suggestionsLabel: 'Tag suggestions',
    remove: 'Remove {label}',
  },

  // Date / time pickers (calendar + presets).
  pickers: {
    calendar: 'Calendar',
    openCalendar: 'Open calendar',
    rangeLabel: 'Date range',
    rangePickerLabel: 'Date range selection',
    clearDate: 'Clear date',
    clearRange: 'Clear range',
    clear: 'Clear',
    startDate: 'Start date',
    endDate: 'End date',
    prevMonth: 'Previous month',
    nextMonth: 'Next month',
    selectMonth: 'Select month',
    selectYear: 'Select year',
    apply: 'Apply',
    cancel: 'Cancel',
    // Month picker.
    monthLabel: 'Month selection',
    chooseMonth: 'Choose a month',
    clearMonth: 'Clear month',
    prevYear: 'Previous year',
    nextYear: 'Next year',
    prevYears: 'Previous years',
    nextYears: 'Next years',
    backToMonths: 'Back to months',
    monthsOfYear: 'Months of {year}',
    yearSelection: 'Year selection',
    // Time picker.
    timeLabel: 'Time selection',
    openTime: 'Open time selection',
    clearTime: 'Clear time',
    timePlaceholder: 'hh:mm',
    timePlaceholderSeconds: 'hh:mm:ss',
    hour: 'Hour',
    minute: 'Minute',
    second: 'Second',
    hourUp: 'Hour up',
    hourDown: 'Hour down',
    minuteUp: 'Minute up',
    minuteDown: 'Minute down',
    secondUp: 'Second up',
    secondDown: 'Second down',
    // DateTimePicker.
    dateTimeLabel: 'Date and time selection',
    presets: {
      today: 'Today',
      yesterday: 'Yesterday',
      last7: 'Last 7 days',
      last30: 'Last 30 days',
      thisMonth: 'This month',
      lastMonth: 'Last month',
    },
  },

  // Markdown editor toolbar + panels.
  editor: {
    toolbar: {
      label: 'Formatting',
      undo: 'Undo',
      redo: 'Redo',
      blockType: 'Block type',
      paragraphHeadings: 'Paragraph & headings',
      paragraph: 'Paragraph',
      heading1: 'Heading 1',
      heading2: 'Heading 2',
      heading3: 'Heading 3',
      bold: 'Bold',
      italic: 'Italic',
      underline: 'Underline',
      strikethrough: 'Strikethrough',
      inlineCode: 'Inline code',
      bulletList: 'Bullet list',
      orderedList: 'Ordered list',
      blockquote: 'Blockquote',
      codeBlock: 'Code block',
      link: 'Link',
      addEditLink: 'Add or edit link',
      editLink: 'Edit link',
      removeLink: 'Remove link',
      linkUrl: 'Link URL',
      linkInvalid: 'Use an http(s):// or mailto: link.',
      apply: 'Apply',
      remove: 'Remove',
      insertTable: 'Insert table',
      tableOptions: 'Table options',
      addColumnBefore: 'Add column before',
      addColumnAfter: 'Add column after',
      deleteColumn: 'Delete column',
      addRowBefore: 'Add row before',
      addRowAfter: 'Add row after',
      deleteRow: 'Delete row',
      toggleHeaderRow: 'Toggle header row',
      deleteTable: 'Delete table',
      insertIfBlock: 'Insert conditional block',
      insertAiText: 'Insert AI text',
      horizontalRule: 'Horizontal rule',
      clearFormatting: 'Clear formatting',
    },
    variable: {
      editTitle: 'Edit variable',
      displayName: 'Display name',
      namePlaceholder: 'Variable name',
      lockName: 'Lock name',
      unlockName: 'Unlock name',
      sourceVariable: 'Source variable',
      save: 'Save',
      cancel: 'Cancel',
      remove: 'Delete',
    },
    pipeline: {
      title: 'Operations pipeline',
      addOperation: 'Add operation',
      availableForType: 'Available for type',
      noOperationsForType: 'No operations for this type.',
      empty: 'No operations. Add the first transformation to reshape the value.',
      step: 'Step {index}',
      done: 'Done',
      removeStep: 'Remove step',
      selectOperation: 'Select an operation',
      operation: 'Operation',
      editStep: 'Edit step {index}: {label}',
      resultType: 'Result type:',
      booleanYes: 'yes',
      booleanNo: 'no',
    },
    types: {
      text: 'Text',
      number: 'Number',
      boolean: 'Condition',
      typeLabel: 'Type: {type}',
      noArguments: 'No arguments',
    },
    ifBlock: {
      title: 'Conditional block',
      delete: 'Delete conditional block',
      addElseIf: 'Else if',
      addElse: 'Else',
      removeSection: 'Remove section',
      setCondition: 'Set condition…',
      condition: 'Condition',
      conditionValid: 'Condition is valid',
      conditionInvalid: 'Condition is invalid (must be a boolean)',
      operationsCount: '{count} op.',
      kindIf: 'IF',
      kindElseIf: 'ELSE IF',
      kindElse: 'ELSE',
    },
    ifCondition: {
      editTitle: 'Edit condition',
      conditionVariable: 'Condition variable',
      selectVariable: 'Select a variable',
      noVariables: 'Add variables to build conditions.',
      mustBeBoolean: 'The condition must end with a boolean type.',
      valid: 'The condition returns a boolean.',
      invalid: 'Reach a boolean result by choosing a variable and operations.',
      saveCondition: 'Save condition',
      cancel: 'Cancel',
    },
    aiText: {
      chipFallback: 'AI text',
      editTitle: 'Edit AI text',
      persona: 'Persona',
      selectPersona: 'Select a persona',
      personaHint: 'Personas help shape the AI’s tone of voice.',
      prompt: 'Prompt',
      promptPlaceholder: 'Describe what the AI should generate…',
      promptHint: 'You can use full Markdown, variables and IF blocks.',
      knowledgeLabels: 'Knowledge labels',
      selectLabels: 'Select labels',
      save: 'Save',
      cancel: 'Cancel',
      remove: 'Delete',
    },
  },
} as const;

/**
 * Recursively widen string leaves to `string` while preserving nested KEY
 * structure. Used to derive `MessageSchema` from the `en` catalog.
 */
type WidenLeaves<T> = {
  [K in keyof T]: T[K] extends string ? string : WidenLeaves<T[K]>;
};

/**
 * The translation schema: the `en` catalog's exact KEY structure with every
 * string leaf widened to `string`. `pl` is typed as `MessageSchema`, so it must
 * provide EVERY key (a missing/extra key fails `vue-tsc`) but is free to supply
 * its own translated values. This is the compile-time parity guard the
 * styleguide rule depends on.
 */
export type MessageSchema = WidenLeaves<typeof en>;

/** The active-catalog shape (literal types) — used internally for lookups. */
export type Catalog = typeof en;
