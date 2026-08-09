// Unit tests for the AI-usage presentation helpers (R2 sub-stage 4): $ formatting, the meter state
// derivation (ok / warn / blocked / unlimited incl. cost_cap 0 = no limit), and the localized channel /
// actor labels with the display_name:null per-type fallback + the "others" bucket.
import { describe, expect, it } from 'vitest';
import { setLocale, translate } from '../../../app/i18n';
import {
  actorIcon,
  actorLabel,
  channelIcon,
  channelLabel,
  formatMoney,
  isUncapped,
  meterState,
  meterTone,
  usedPercent,
} from '../aiUsageMeta';
import type { AiUsageActor, AiUsageSummary } from '../../../app/stores/aiUsage';

function summary(overrides: Partial<AiUsageSummary> = {}): AiUsageSummary {
  return {
    currency: 'USD',
    estimated: true,
    cost_used: 4,
    cost_cap: 10,
    cost_remaining: 6,
    cap_source: 'workspace',
    warn_ratio: 0.8,
    warn_reached: false,
    blocked: false,
    period: { month: '2026-07', resets_at: '2026-08-01T00:00:00Z' },
    tokens_used: 0,
    per_channel: [],
    per_actor: [],
    can_manage: false,
    ...overrides,
  };
}

function actor(overrides: Partial<AiUsageActor> = {}): AiUsageActor {
  return { actor_type: 'user', actor_id: 'u1', display_name: null, icon: null, cost: 1, tokens: 10, ...overrides };
}

describe('aiUsageMeta', () => {
  describe('formatMoney', () => {
    it('formats whole/2-dp dollars', () => {
      expect(formatMoney(12.5)).toBe('$12.50');
      expect(formatMoney(0)).toBe('$0.00');
    });

    it('keeps sub-$1 precision so a tiny spend does not collapse to $0.00', () => {
      expect(formatMoney(0.0123)).toBe('$0.0123');
    });
  });

  describe('meter state (never color-only — pairs with a status)', () => {
    it('is unlimited when cap_source is unlimited OR the cap is 0', () => {
      expect(isUncapped(summary({ cap_source: 'unlimited', cost_cap: 0, cost_remaining: null }))).toBe(true);
      expect(isUncapped(summary({ cap_source: 'unlimited', cost_cap: 0 }))).toBe(true);
      expect(meterState(summary({ cap_source: 'unlimited', cost_cap: 0 }))).toBe('unlimited');
    });

    it('is ok / warn / blocked as spend climbs', () => {
      expect(meterState(summary({ cost_used: 4, warn_reached: false, blocked: false }))).toBe('ok');
      expect(meterState(summary({ cost_used: 8, warn_reached: true, blocked: false }))).toBe('warn');
      expect(meterState(summary({ cost_used: 10, warn_reached: true, blocked: true }))).toBe('blocked');
    });

    it('maps state to a tone (danger/warning/success)', () => {
      expect(meterTone('blocked')).toBe('danger');
      expect(meterTone('warn')).toBe('warning');
      expect(meterTone('ok')).toBe('success');
      expect(meterTone('unlimited')).toBe('success');
    });

    it('clamps the used percent to [0,100]', () => {
      expect(usedPercent(summary({ cost_used: 4, cost_cap: 10 }))).toBe(40);
      expect(usedPercent(summary({ cost_used: 99, cost_cap: 10 }))).toBe(100);
      expect(usedPercent(summary({ cost_cap: 0 }))).toBe(0);
    });
  });

  describe('labels + fallbacks', () => {
    it('channel label localizes and falls back to the raw key', () => {
      setLocale('en');
      expect(channelLabel('ai_text', translate)).toBe('Text');
      expect(channelLabel('ai_image_generate', translate)).toBe('Image generation');
      expect(channelLabel('unknown_channel', translate)).toBe('unknown_channel');
      expect(channelIcon('ai_text')).toBe('file-text');
    });

    it('names BOT WORK in both languages, so it is not read as more text spend', () => {
      // A bot run spends on somebody else's behalf and on a schedule nobody watches, which makes
      // it the line item most likely to grow on its own. It arrived after the meter existed, so
      // until now the usage screen printed the raw `ai_bot_task` key.
      setLocale('en');
      expect(channelLabel('ai_bot_task', translate)).toBe('Bot work');
      expect(channelLabel('ai_bot_task', translate)).not.toBe('ai_bot_task');

      setLocale('pl');
      expect(channelLabel('ai_bot_task', translate)).toBe('Praca botów');
    });

    it('gives bot work its own glyph, NOT the unknown-channel fallback', () => {
      // `sparkles` is what an unrecognised channel gets. Drawing a known channel with it would
      // leave the screen looking exactly as it did before the label was added.
      expect(channelIcon('ai_bot_task')).toBe('workflow');
      expect(channelIcon('ai_bot_task')).not.toBe(channelIcon('totally_unknown'));
    });

    it('still degrades gracefully for a channel nobody has taught it', () => {
      // The property that made the missing label survivable rather than a crash — worth keeping
      // true as channels keep arriving.
      expect(channelIcon('some_future_channel')).toBe('sparkles');
    });

    it('actor label uses display_name when present', () => {
      setLocale('en');
      expect(actorLabel(actor({ display_name: 'Ada Lovelace' }), translate)).toBe('Ada Lovelace');
    });

    it('actor label falls back to a per-type label when display_name is null', () => {
      setLocale('en');
      expect(actorLabel(actor({ actor_type: 'workflow_run', display_name: null }), translate)).toBe('Automation');
      expect(actorLabel(actor({ actor_type: 'bot', display_name: '  ' }), translate)).toBe('Bot');
      expect(actorLabel(actor({ actor_type: 'others', actor_id: null, display_name: null }), translate)).toBe('Others');
    });

    it('actor glyph is type-based (bot → sparkles, workflow_run → workflow, others → users, user → user)', () => {
      expect(actorIcon(actor({ actor_type: 'bot' }))).toBe('sparkles');
      expect(actorIcon(actor({ actor_type: 'workflow_run' }))).toBe('workflow');
      expect(actorIcon(actor({ actor_type: 'others' }))).toBe('users');
      expect(actorIcon(actor({ actor_type: 'user' }))).toBe('user');
    });
  });
});
