// Shared presentation for bot TOOLS (next, Batch 5).
//
// Maps a tool id → an i18n label/description KEY (resolved via t() at the call
// site) and a `next` IconName, and builds a `tool_used` action's description text
// from its (free-form, possibly-missing) payload. Kept as a pure module so the
// editor options, the bot-detail chips, and BOTH action timelines render tools
// IDENTICALLY, and the mapping is unit-testable.
import type { IconName } from '../../ui/primitives/icons';
import type { BotAction, BotToolId } from './types';

interface ToolMeta {
  /** i18n key for the short label (resolve via t()). */
  labelKey: string;
  /** i18n key for the one-line description (resolve via t()). */
  descriptionKey: string;
  icon: IconName;
}

// Icons are the closest available `next` registry glyphs (no globe/paperclip):
//   fetch_url → link, web_search → search, generate_file → file-text,
//   read_attachments → download. An unknown tool falls back to `settings`.
const TOOL_META: Record<BotToolId, ToolMeta> = {
  fetch_url: {
    labelKey: 'bots.tools.fetch_url.label',
    descriptionKey: 'bots.tools.fetch_url.description',
    icon: 'link',
  },
  web_search: {
    labelKey: 'bots.tools.web_search.label',
    descriptionKey: 'bots.tools.web_search.description',
    icon: 'search',
  },
  generate_file: {
    labelKey: 'bots.tools.generate_file.label',
    descriptionKey: 'bots.tools.generate_file.description',
    icon: 'file-text',
  },
  read_attachments: {
    labelKey: 'bots.tools.read_attachments.label',
    descriptionKey: 'bots.tools.read_attachments.description',
    icon: 'download',
  },
};

const FALLBACK_ICON: IconName = 'settings';

/** True when the id is one of the four known tools. */
export function isKnownTool(id: string): id is BotToolId {
  return Object.prototype.hasOwnProperty.call(TOOL_META, id);
}

/**
 * Recombine a tools selection for the write payload: the AVAILABLE ids the user
 * picked in the multiselect PLUS the UNAVAILABLE ids already saved on the bot
 * (preserved untouched so removed-tool config is never silently dropped). Order
 * is: picked-available first, then preserved-unavailable. `isAvailable` decides
 * which currently-saved ids are unavailable.
 */
export function combineToolSelection(
  pickedAvailable: string[],
  currentlySaved: string[],
  isAvailable: (id: string) => boolean,
): string[] {
  const preservedUnavailable = currentlySaved.filter((id) => !isAvailable(id));
  return [...pickedAvailable, ...preservedUnavailable];
}

/** The icon for a tool id (falls back to a generic `settings` glyph). */
export function toolIcon(id: string): IconName {
  return isKnownTool(id) ? TOOL_META[id].icon : FALLBACK_ICON;
}

/** A minimal translator signature (matches `useI18n().t`). */
type Translate = (key: string, fallback?: string, params?: Record<string, string | number>) => string;

/**
 * The localized label for a tool id. An UNKNOWN id (a tool removed server-side but
 * still in a bot's saved config) returns the raw id so nothing is silently lost.
 */
export function toolLabel(id: string, t: Translate): string {
  return isKnownTool(id) ? t(TOOL_META[id].labelKey, id) : id;
}

/** The localized one-line description for a tool id (empty for an unknown id). */
export function toolDescription(id: string, t: Translate): string {
  return isKnownTool(id) ? t(TOOL_META[id].descriptionKey, '') : '';
}

// --- `tool_used` action payload accessors (all tolerate a missing payload) ----

/** Read `payload.tool` (the tool id) for a tool_used action, or null. */
export function toolUsedId(action: Pick<BotAction, 'payload'>): string | null {
  const tool = action.payload?.tool;
  return typeof tool === 'string' && tool !== '' ? tool : null;
}

/**
 * Build a `tool_used` action's DESCRIPTION from its payload, per tool. All fields
 * are read defensively (a missing/foreign payload yields an empty description):
 *   • fetch_url        → the host (PLAIN text — never a clickable link).
 *   • web_search       → the query + "{n} results".
 *   • generate_file    → the file name.
 *   • read_attachments → list/read + count or file.
 */
export function toolUsedDescription(
  action: Pick<BotAction, 'payload'>,
  t: Translate,
): string | undefined {
  const p = action.payload;
  if (!p) return undefined;
  const tool = toolUsedId(action);
  const str = (v: unknown): string | null =>
    typeof v === 'string' && v.trim() !== '' ? v : null;
  const num = (v: unknown): number | null => (typeof v === 'number' ? v : null);

  switch (tool) {
    case 'fetch_url': {
      const host = str(p.host) ?? str(p.url);
      return host ?? undefined;
    }
    case 'web_search': {
      const query = str(p.query);
      const results = num(p.results);
      if (query && results != null) {
        return t('bots.tools.toolUsed.searchWithResults', '{query} · {count} results', {
          query,
          count: results,
        });
      }
      if (query) return query;
      if (results != null) {
        return t('bots.tools.toolUsed.resultsOnly', '{count} results', { count: results });
      }
      return undefined;
    }
    case 'generate_file': {
      return str(p.file) ?? undefined;
    }
    case 'read_attachments': {
      const action_ = str(p.action);
      const file = str(p.file);
      const count = num(p.count);
      const verb =
        action_ === 'read'
          ? t('bots.tools.toolUsed.actionRead', 'read')
          : action_ === 'list'
            ? t('bots.tools.toolUsed.actionList', 'list')
            : action_;
      const detail = file ?? (count != null ? String(count) : null);
      if (verb && detail) return `${verb} · ${detail}`;
      return verb ?? detail ?? undefined;
    }
    default:
      return undefined;
  }
}
