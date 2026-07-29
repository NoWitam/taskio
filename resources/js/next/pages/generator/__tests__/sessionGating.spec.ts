// sessionGating.spec — the SINGLE capability switch for the generation-session chat surface. Pins the
// R2 sub-stage 2d state: the per-part refine loop (regenerate / composer + per-part refine / undo) and the
// version display are now ALL live — nothing on the session surface remains gated. A regression that flips
// one back off is caught here.
import { describe, it, expect } from 'vitest';
import { SESSION_CAPABILITIES } from '../session/sessionGating';

describe('SESSION_CAPABILITIES (2d)', () => {
  it('keeps the 2b/2c live set live', () => {
    expect(SESSION_CAPABILITIES.wholeGenerate).toBe(true);
    expect(SESSION_CAPABILITIES.editSlots).toBe(true);
    expect(SESSION_CAPABILITIES.delete).toBe(true);
    expect(SESSION_CAPABILITIES.copy).toBe(true);
    expect(SESSION_CAPABILITIES.saveToDisk).toBe(true);
  });

  it('turns the 2d refine loop + version display ON', () => {
    expect(SESSION_CAPABILITIES.regeneratePart).toBe(true);
    expect(SESSION_CAPABILITIES.composerSend).toBe(true);
    expect(SESSION_CAPABILITIES.undo).toBe(true);
    expect(SESSION_CAPABILITIES.history).toBe(true);
  });

  it('leaves nothing gated after 2d', () => {
    expect(Object.values(SESSION_CAPABILITIES).every((v) => v === true)).toBe(true);
  });
});
