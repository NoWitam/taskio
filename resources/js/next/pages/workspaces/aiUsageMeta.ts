// Pure presentation helpers for the AI-usage surface (R2 sub-stage 4).
//
// Kept OUT of the components so the $-formatting, the localized channel / actor labels, and the meter
// state derivation are unit-testable in isolation (and shared by the usage page + the generator budget
// chip/banner). No Vue, no store — just the summary shapes + a `t` function.
import type { IconName } from '../../ui/primitives/Icon.vue';
import type { AiUsageActor, AiUsageSummary } from '../../app/stores/aiUsage';

/** A `t(key, default?, params?)`-shaped translator (matches the next i18n `translate`). */
type Translate = (key: string, defaultValue?: string, params?: Record<string, string | number>) => string;

/**
 * Format an ESTIMATED dollar amount, $-first. 2 decimals normally; up to 4 for sub-$1 values so a tiny but
 * real spend never collapses to "$0.00". Falls back to a plain `$x.xx` if Intl currency formatting is
 * unavailable. The "estimated" caveat is carried by the surrounding copy, never baked into the number.
 */
export function formatMoney(amount: number, currency = 'USD'): string {
  const value = Number.isFinite(amount) ? amount : 0;
  const abs = Math.abs(value);
  const maximumFractionDigits = abs > 0 && abs < 1 ? 4 : 2;
  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency,
      minimumFractionDigits: 2,
      maximumFractionDigits,
    }).format(value);
  } catch {
    return `$${value.toFixed(2)}`;
  }
}

/** Compact token count for the SECONDARY (muted) token display. */
export function formatTokens(tokens: number): string {
  const value = Number.isFinite(tokens) ? Math.round(tokens) : 0;
  try {
    return new Intl.NumberFormat(undefined).format(value);
  } catch {
    return String(value);
  }
}

/**
 * A workspace is UNCAPPED when the cap source is 'unlimited' OR the effective cap is 0/absent — the clean
 * "no limit" state (no scary empty meter). The single source of truth both the page + the chip read.
 */
export function isUncapped(summary: AiUsageSummary): boolean {
  return summary.cap_source === 'unlimited' || !(summary.cost_cap > 0);
}

/** The four meter states, driving BOTH the meter tone AND a paired StatusBadge/text (never color-only). */
export type MeterState = 'ok' | 'warn' | 'blocked' | 'unlimited';

/** Derive the meter state: uncapped → unlimited; over cap → blocked; past warn ratio → warn; else ok. */
export function meterState(summary: AiUsageSummary): MeterState {
  if (isUncapped(summary)) return 'unlimited';
  if (summary.blocked) return 'blocked';
  if (summary.warn_reached) return 'warn';
  return 'ok';
}

/** Progress tone for a meter state (paired with an icon + text so it is never color-only). */
export function meterTone(state: MeterState): 'primary' | 'success' | 'warning' | 'danger' {
  switch (state) {
    case 'blocked':
      return 'danger';
    case 'warn':
      return 'warning';
    default:
      return 'success';
  }
}

/** The percentage of the cap used (0 when uncapped / cap is 0), clamped to [0, 100] for the meter fill. */
export function usedPercent(summary: AiUsageSummary): number {
  if (!(summary.cost_cap > 0)) return 0;
  return Math.min(100, Math.max(0, Math.round((summary.cost_used / summary.cost_cap) * 100)));
}

/** Localized channel label (falls back to the raw key so an unknown channel still renders). */
export function channelLabel(channel: string, t: Translate): string {
  return t(`workspaces.aiUsage.channel.${channel}`, channel);
}

/** A stable, in-set glyph per channel (text → document; edit → crop; generate → image; else sparkles). */
export function channelIcon(channel: string): IconName {
  switch (channel) {
    case 'ai_text':
      return 'file-text';
    case 'ai_image_edit':
      return 'crop';
    case 'ai_image_generate':
      return 'image';
    default:
      return 'sparkles';
  }
}

/**
 * The display name for an actor row: the resolved `display_name` when present, else a LOCALIZED per-type
 * fallback ("You/User", "Bot", "Automation", "Others", or a generic "Unattributed"). Never blank.
 */
export function actorLabel(actor: AiUsageActor, t: Translate): string {
  const name = actor.display_name?.trim();
  if (name) return name;
  switch (actor.actor_type) {
    case 'user':
      return t('workspaces.aiUsage.actor.user', 'Member');
    case 'bot':
      return t('workspaces.aiUsage.actor.bot', 'Bot');
    case 'workflow_run':
      return t('workspaces.aiUsage.actor.workflow_run', 'Automation');
    case 'others':
      return t('workspaces.aiUsage.actor.others', 'Others');
    default:
      return t('workspaces.aiUsage.actor.unknown', 'Unattributed');
  }
}

/**
 * A TYPE-based glyph for the actor row (mirrors CreatorBadge: bot → sparkles, workflow_run → workflow,
 * others → users, user/unknown → user). The wire `icon` string is a raw model attribute — NOT trusted as
 * an icon name — so the glyph stays in the guaranteed next icon set.
 */
export function actorIcon(actor: AiUsageActor): IconName {
  switch (actor.actor_type) {
    case 'bot':
      return 'sparkles';
    case 'workflow_run':
      return 'workflow';
    case 'others':
      return 'users';
    default:
      return 'user';
  }
}

/** The subtle Badge tone families used for the actor glyph bubble (matches CreatorBadge's palette). */
export function actorGlyphTone(actor: AiUsageActor): 'primary' | 'info' | 'neutral' {
  switch (actor.actor_type) {
    case 'bot':
      return 'primary';
    case 'workflow_run':
      return 'info';
    default:
      return 'neutral';
  }
}
