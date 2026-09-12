// publishingMeta.spec — the maps that turn a SERVER CODE into a design-system choice.
//
// These look like trivia and are not. Three of them would fail SILENTLY if they drifted:
// a draft's `status_tone: null` degrading to something other than `neutral` would colour
// every draft badge wrongly with nothing to catch it; a `failure_code` this build has never
// heard of falling through without a fallback would print a raw catalog key on a screen
// somebody reached after a failure; and an OAuth reason tone drifting to `danger` would
// accuse a person of breaking something when all they did was decline a permission.
import { describe, it, expect } from 'vitest';
import {
  ATTENTION_STATUSES,
  CONNECTABLE_PLATFORMS,
  connectionFailureSentenceKey,
  connectionStatusIcon,
  failureSentenceKey,
  OAUTH_REASONS,
  oauthCanRetry,
  oauthReasonKey,
  oauthReasonTone,
  platformIcon,
  staleAfterMinutes,
  STATUS_TABS,
  statusBandRole,
  statusIcon,
  toneToVariant,
} from '../publishingMeta';

describe('toneToVariant', () => {
  it('degrades a draft’s null tone to neutral EXPLICITLY', () => {
    // `draft` is the one status with no colour to state, and the resource sends `null`
    // rather than `'neutral'` — that null is a contract shared with the calendar.
    expect(toneToVariant(null)).toBe('neutral');
  });

  it('maps every tone the server can send', () => {
    expect(toneToVariant('info')).toBe('info');
    expect(toneToVariant('warning')).toBe('warning');
    expect(toneToVariant('success')).toBe('success');
    expect(toneToVariant('danger')).toBe('danger');
  });

  it('maps a connection’s `muted` tone to neutral (it has no variant of its own)', () => {
    expect(toneToVariant('muted')).toBe('neutral');
  });

  it('falls back to neutral for a tone this build has never heard of', () => {
    expect(toneToVariant('chartreuse')).toBe('neutral');
    expect(toneToVariant(undefined)).toBe('neutral');
  });
});

describe('statusIcon', () => {
  it('gives needs_reconcile a QUESTION, not a warning', () => {
    // The meaning of that state is "nobody knows", which is a question. The colour already
    // says "urgent"; `alert-triangle` would say "something is wrong", which is precisely
    // what is not known.
    expect(statusIcon('needs_reconcile')).toBe('help-circle');
    expect(statusIcon('failed')).toBe('x-circle');
  });

  it('covers all seven statuses and falls back for an eighth', () => {
    for (const status of STATUS_TABS) {
      expect(statusIcon(status)).toBeTruthy();
    }
    expect(statusIcon('some_future_status')).toBe('circle');
  });
});

describe('statusBandRole', () => {
  it('is assertive only where a person has to act', () => {
    expect(statusBandRole('failed')).toBe('alert');
    expect(statusBandRole('blocked')).toBe('alert');
    expect(statusBandRole('needs_reconcile')).toBe('alert');
    // `publishing` LOOKS urgent and expects nothing of the reader.
    expect(statusBandRole('publishing')).toBe('status');
    expect(statusBandRole('published')).toBe('status');
    expect(statusBandRole('draft')).toBe('status');
  });
});

describe('platformIcon', () => {
  it('gives every destination its own generic glyph', () => {
    expect(platformIcon('youtube')).toBe('film');
    expect(platformIcon('instagram')).toBe('image');
    expect(platformIcon('facebook')).toBe('users');
    expect(platformIcon('dry_run')).toBe('eye-off');
  });

  it('falls back for a destination B4 may add', () => {
    expect(platformIcon('tiktok')).toBe('circle');
  });
});

describe('the module’s closed sets', () => {
  it('offers an account only for destinations that can have one', () => {
    // `dry_run` publishes nothing and has nothing to connect, so it gets no card.
    expect(CONNECTABLE_PLATFORMS).toEqual(['youtube', 'instagram', 'facebook']);
  });

  it('orders the tabs by LIFECYCLE, not alphabetically', () => {
    expect(STATUS_TABS).toEqual([
      'draft',
      'scheduled',
      'publishing',
      'published',
      'failed',
      'needs_reconcile',
      'blocked',
    ]);
  });

  it('puts needs_reconcile first among the attention statuses', () => {
    // It is the most urgent of the three: it may ALREADY be in the world.
    expect(ATTENTION_STATUSES[0]).toBe('needs_reconcile');
    expect([...ATTENTION_STATUSES].sort()).toEqual(['blocked', 'failed', 'needs_reconcile']);
  });
});

describe('failureSentenceKey', () => {
  it('maps each of the eight codes to its own sentence', () => {
    for (const code of [
      'title_missing',
      'publish_outcome_unknown',
      'reconciled_absent',
      'dispatch_failed',
      'publish_worker_failed',
      'reaper_stale',
      'connection_needs_reauth',
      'connection_disconnected',
    ]) {
      expect(failureSentenceKey(code)).toBe(`publishing.failures.${code}`);
    }
  });

  it('falls back for an adapter code B4 has not written yet', () => {
    expect(failureSentenceKey('quota_exhausted')).toBe('publishing.failures.unknown');
  });

  it('returns nothing at all for an absent code', () => {
    expect(failureSentenceKey(null)).toBe('');
    expect(failureSentenceKey(undefined)).toBe('');
  });
});

describe('connectionFailureSentenceKey', () => {
  it('maps the four connection codes and falls back for anything else', () => {
    expect(connectionFailureSentenceKey('refresh_failed')).toBe(
      'publishing.connectionFailures.refresh_failed',
    );
    expect(connectionFailureSentenceKey('credentials_unreadable')).toBe(
      'publishing.connectionFailures.credentials_unreadable',
    );
    expect(connectionFailureSentenceKey('something_else')).toBe('publishing.failures.unknown');
  });
});

describe('connectionStatusIcon', () => {
  it('never leaves colour as the only signal', () => {
    expect(connectionStatusIcon('active')).toBe('check-circle');
    expect(connectionStatusIcon('needs_reauth')).toBe('alert-circle');
    expect(connectionStatusIcon('revoked')).toBe('x-circle');
  });
});

describe('staleAfterMinutes', () => {
  it('reads the one whitelisted key', () => {
    expect(staleAfterMinutes({ stale_after_seconds: 900 })).toBe(15);
  });

  it('renders NOTHING for keys the specification does not know by name', () => {
    // `failure_context` also carries `{exception: 'Illuminate\\…'}`, and an exception class
    // name is not a message for a person.
    expect(staleAfterMinutes({ exception: 'Illuminate\\Database\\QueryException' })).toBeNull();
    expect(staleAfterMinutes(null)).toBeNull();
    expect(staleAfterMinutes({ stale_after_seconds: 0 })).toBeNull();
    expect(staleAfterMinutes({ stale_after_seconds: 'soon' })).toBeNull();
  });
});

describe('the OAuth return', () => {
  it('knows exactly the fourteen named reasons', () => {
    expect(OAUTH_REASONS).toHaveLength(14);
  });

  it('treats a declined consent as a warning, not an accusation', () => {
    // The user said no, or clicked a stale link. Nothing broke; red would be an accusation.
    for (const reason of [
      'access_denied',
      'missing_code',
      'oauth_state_malformed',
      'oauth_state_bad_signature',
      'oauth_state_expired',
      'oauth_state_already_used',
      'oauth_state_platform_mismatch',
      'oauth_browser_mismatch',
    ]) {
      expect(oauthReasonTone(reason)).toBe('warning');
    }
  });

  it('treats a refusal AFTER consent as danger', () => {
    for (const reason of [
      'token_exchange_failed',
      'token_response_unusable',
      'account_lookup_failed',
      'connection_failed',
      'workspace_unavailable',
      'unknown_platform',
    ]) {
      expect(oauthReasonTone(reason)).toBe('danger');
    }
  });

  it('gives an unrecognised platform error a tone and a fallback sentence', () => {
    // The callback passes ANY platform `error` through verbatim, truncated to 64 chars.
    expect(oauthReasonTone('server_error')).toBe('danger');
    expect(oauthReasonKey('server_error')).toBe('publishing.oauth.failures.unknown');
    expect(oauthReasonKey(null)).toBe('publishing.oauth.failures.unknown');
  });

  it('keys each named reason to its own sentence', () => {
    for (const reason of OAUTH_REASONS) {
      expect(oauthReasonKey(reason)).toBe(`publishing.oauth.failures.${reason}`);
    }
  });

  it('offers "connect again" only where the remedy is the reader’s', () => {
    expect(oauthCanRetry('access_denied', 'youtube')).toBe(true);
    expect(oauthCanRetry('token_exchange_failed', 'facebook')).toBe(true);
    // The remedy is elsewhere entirely (access to the workspace).
    expect(oauthCanRetry('workspace_unavailable', 'youtube')).toBe(false);
    // There is nothing to repeat: the platform is not one this application connects to.
    expect(oauthCanRetry('unknown_platform', 'youtube')).toBe(false);
    // And with no platform there is no destination to start from.
    expect(oauthCanRetry('access_denied', null)).toBe(false);
  });
});
