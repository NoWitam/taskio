// extensions/index.ts — the editor's Tiptap extension assembly.
//
// `createCoreExtensions(options)` returns the BASE set the "next" Markdown editor
// always needs: StarterKit (paragraph/heading/bold/italic/strike/code/lists/
// blockquote/code block/hr/history + the markdown input rules) plus Underline,
// Link, the Table family, and TaskList/TaskItem for GFM task lists. It is the
// single place the schema is defined, so `markdown.ts` (which maps to/from these
// exact node + mark names) and the toolbar stay in lock-step.
//
// ─────────────────────────────────────────────────────────────────────────────
// PART 2 EXTENSION POINT  — IMPLEMENTED
// ─────────────────────────────────────────────────────────────────────────────
// App-specific nodes (mention, variable, if-block, AI chip — see legacy
// FORMAT.md) are added WITHOUT touching the core: callers either pass
// `extensions?: Extension[]` to <MarkdownEditor> directly, or (preferred) enable
// features via the typed `MarkdownEditor` props, which call
// `buildPart2Extensions()` below and merge the result AFTER the core set via
// `mergeExtensions()`. Each node owns its own NodeView + its own markdown
// (de)serialization, registered through the `markdown.ts` registry hooks. The
// core itself never imports those nodes, so PART 1 stays dependency-free; the
// imports below are tree-shaken away unless a feature is enabled.
//
// Dependency note: we use ONLY the Tiptap packages already in package.json
// (starter-kit, underline, link, table family, pm). Task lists and Placeholder
// would each need a NEW package, which the project rules forbid — so the core
// matches the LEGACY schema (no task-list node) and ships a tiny local
// `Placeholder` extension (see ./placeholder.ts) instead of the npm one.
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import Link from '@tiptap/extension-link';
import Table from '@tiptap/extension-table';
import TableRow from '@tiptap/extension-table-row';
import TableHeader from '@tiptap/extension-table-header';
import TableCell from '@tiptap/extension-table-cell';
import type { AnyExtension, Extensions } from '@tiptap/core';
import { Placeholder } from './placeholder';

export interface CoreExtensionOptions {
  /** Placeholder shown when the document is empty. */
  placeholder?: string;
  /** Whether links open on click (false in the editor; true is for viewers). */
  linkOpenOnClick?: boolean;
}

/**
 * Build the always-present base extensions for the editor schema. The order is
 * intentional: StarterKit first (it owns the bulk of the schema + input rules),
 * then the marks/nodes that extend it.
 */
export function createCoreExtensions(
  options: CoreExtensionOptions = {},
): Extensions {
  const { linkOpenOnClick = false, placeholder } = options;

  return [
    StarterKit.configure({
      heading: { levels: [1, 2, 3] },
      // Underline ships separately (StarterKit has no underline); Link is
      // configured explicitly below so we control protocols + rel.
      codeBlock: {
        HTMLAttributes: { class: 'next-md-code-block' },
      },
    }),
    Underline,
    Link.configure({
      openOnClick: linkOpenOnClick,
      autolink: true,
      linkOnPaste: true,
      protocols: ['http', 'https', 'mailto'],
      HTMLAttributes: {
        rel: 'noopener noreferrer nofollow',
        class: 'next-md-link',
      },
    }),
    Table.configure({ resizable: true }),
    TableRow,
    TableHeader,
    TableCell,
    Placeholder.configure({ placeholder: placeholder ?? '' }),
  ];
}

/**
 * Append caller-provided PART 2 extensions to the core set. Kept as a tiny,
 * named helper so the merge order (core first, app nodes last) is a documented,
 * single-source contract rather than ad-hoc array spreading in the component.
 */
export function mergeExtensions(
  core: Extensions,
  extra?: AnyExtension[],
): Extensions {
  if (!extra || !extra.length) return core;
  return [...core, ...extra];
}

// ─────────────────────────────────────────────────────────────────────────────
// PART 2 feature assembly
// ─────────────────────────────────────────────────────────────────────────────
import { createMention, type MentionOptions } from './mention';
import { createVariable } from './variable';
import { createAiText } from './aiText';
import { createIfBlock, DEFAULT_MAX_DEPTH } from './ifBlock';
import { createWikilink, type WikilinkOptions } from './wikilink';
import type {
  AiTextFeatureConfig,
  IfBlockFeatureConfig,
  VariableFeatureConfig,
} from './types';

export type { MentionOptions };
export type { MentionItem } from './types';
export type { WikilinkOptions, WikilinkItem } from './wikilink';

/** Feature configs for the PART 2 app nodes (all OFF by default). */
export interface Part2Features {
  /** `@`-mentions with an async `fetch(query)` source. */
  mentions?: MentionOptions;
  /** Template variables: predefined list + operations catalog (+ trigger). */
  variables?: VariableFeatureConfig;
  /** Conditional `if-block` containers (`true` for defaults, or a config). */
  ifBlocks?: boolean | IfBlockFeatureConfig;
  /** AI-text chips (`true` for defaults, or a config). */
  aiText?: boolean | AiTextFeatureConfig;
  /**
   * `[[`-wikilink autocomplete (Knowledge module).
   *
   * The odd one out in this list: it adds NO node and NO schema. It is a bare suggestion plugin
   * that inserts plain `[[slug]]` text, so enabling it cannot change how a document serializes.
   */
  wikilinks?: WikilinkOptions;
}

/**
 * Build the enabled PART 2 extensions, in a stable order. Each node self-registers
 * its markdown (de)serialization on import (idempotent). Variable / mention / AI
 * nodes are part of the SAME schema, so they automatically work inside if-block
 * branch bodies (which are real editor regions, not nested markdown). The if-block
 * returns BOTH its container (`ifBlock`) and branch (`ifBranch`) node.
 */
export function buildPart2Extensions(features: Part2Features): {
  extensions: AnyExtension[];
} {
  const extensions: AnyExtension[] = [];

  if (features.mentions) extensions.push(createMention(features.mentions));

  if (features.variables) {
    extensions.push(
      createVariable({
        variables: features.variables.variables,
        operationsCatalog: features.variables.operationsCatalog,
        // The LIVE getters ride through untouched: the extension reads them at CALL time,
        // so an async / changing host feed reaches an already-open editor (see types.ts).
        source: features.variables.source,
        catalog: features.variables.catalog,
        trigger: features.variables.trigger,
        // The host's value-or-variable control for ONE pipeline argument (B4, optional).
        argVariableField: features.variables.argVariableField,
      }),
    );
  }

  if (features.aiText) {
    const cfg = typeof features.aiText === 'object' ? features.aiText : {};
    extensions.push(
      createAiText({
        personas: cfg.personas,
        labelsEnabled: cfg.labelsEnabled,
        labelsCatalog: cfg.labelsCatalog,
      }),
    );
  }

  if (features.ifBlocks) {
    const cfg = typeof features.ifBlocks === 'object' ? features.ifBlocks : {};
    extensions.push(
      ...createIfBlock({
        maxElseIf: cfg.maxElseIf,
        maxDepth: cfg.maxDepth ?? DEFAULT_MAX_DEPTH,
      }),
    );
  }

  if (features.wikilinks) extensions.push(createWikilink(features.wikilinks));

  return { extensions };
}
