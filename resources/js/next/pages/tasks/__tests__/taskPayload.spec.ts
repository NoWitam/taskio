// Unit tests for the pure Task write-payload builder. The contract trap this
// guards: the TaskDTO coerces an ABSENT `form_id` / `approval_pipeline_id` to null
// on update, so the builder MUST always emit BOTH (string|null) — a cleared picker
// detaches, a present id stays attached. Since Batch 2 added the pipeline picker,
// `approval_pipeline_id` is now an explicit caller input (like `form_id`): the
// modal passes its picker value, the drawer echoes the loaded task's value.
import { describe, expect, it } from 'vitest';
import { buildTaskPayload } from '../taskPayload';

describe('buildTaskPayload', () => {
  const base = {
    title: 'Task',
    priority: 'medium' as const,
    assigned_id: 'u1',
  };

  it('always emits form_id; null when the picker is cleared (detach)', () => {
    const payload = buildTaskPayload({ ...base, form_id: null });
    // `form_id` is present (not omitted) and null → the backend detaches the form.
    expect('form_id' in payload).toBe(true);
    expect(payload.form_id).toBeNull();
  });

  it('keeps the chosen form_id when a form is attached', () => {
    const payload = buildTaskPayload({ ...base, form_id: 'f1' });
    expect(payload.form_id).toBe('f1');
  });

  it('emits form_id: null even when form_id is omitted entirely', () => {
    const payload = buildTaskPayload({ ...base });
    expect('form_id' in payload).toBe(true);
    expect(payload.form_id).toBeNull();
  });

  it('always emits approval_pipeline_id; null when the picker is cleared (detach)', () => {
    const payload = buildTaskPayload({ ...base, approval_pipeline_id: null });
    // Present (not omitted) and null → the backend detaches the pipeline.
    expect('approval_pipeline_id' in payload).toBe(true);
    expect(payload.approval_pipeline_id).toBeNull();
  });

  it('keeps the chosen approval_pipeline_id when a pipeline is attached', () => {
    const payload = buildTaskPayload({ ...base, approval_pipeline_id: 'p1' });
    expect(payload.approval_pipeline_id).toBe('p1');
  });

  it('emits approval_pipeline_id: null when it is omitted entirely', () => {
    const payload = buildTaskPayload({ ...base });
    expect('approval_pipeline_id' in payload).toBe(true);
    expect(payload.approval_pipeline_id).toBeNull();
  });

  it('carries BOTH explicit form_id and approval_pipeline_id values together', () => {
    const payload = buildTaskPayload({ ...base, form_id: 'f1', approval_pipeline_id: 'p1' });
    expect(payload.form_id).toBe('f1');
    expect(payload.approval_pipeline_id).toBe('p1');
  });

  it('detaches BOTH links when both pickers are cleared', () => {
    const payload = buildTaskPayload({ ...base, form_id: null, approval_pipeline_id: null });
    expect(payload.form_id).toBeNull();
    expect(payload.approval_pipeline_id).toBeNull();
  });

  it('trims the title and defaults labels/attachments to arrays', () => {
    const payload = buildTaskPayload({ ...base, title: '  Hello  ' });
    expect(payload.title).toBe('Hello');
    expect(payload.labels).toEqual([]);
    expect(payload.attachments).toEqual([]);
  });

  // --- Polymorphic assignee (Batch 2) -------------------------------------
  const baseNoAssigned = { title: 'Task', priority: 'medium' as const };

  it('emits assignee_type/assignee_id for a USER assignee (and not assigned_id)', () => {
    const payload = buildTaskPayload({
      ...baseNoAssigned,
      assignee_type: 'user',
      assignee_id: 'u1',
    });
    expect(payload.assignee_type).toBe('user');
    expect(payload.assignee_id).toBe('u1');
    expect('assigned_id' in payload).toBe(false);
  });

  it('emits assignee_type/assignee_id for a BOT assignee', () => {
    const payload = buildTaskPayload({
      ...baseNoAssigned,
      assignee_type: 'bot',
      assignee_id: 'b1',
    });
    expect(payload.assignee_type).toBe('bot');
    expect(payload.assignee_id).toBe('b1');
    expect('assigned_id' in payload).toBe(false);
  });

  it('CLEARS the assignee with BOTH null when assignee_type is null', () => {
    const payload = buildTaskPayload({
      ...baseNoAssigned,
      assignee_type: null,
      assignee_id: null,
    });
    expect('assignee_type' in payload).toBe(true);
    expect(payload.assignee_type).toBeNull();
    expect(payload.assignee_id).toBeNull();
  });

  it('forces assignee_id null when assignee_type is null even if an id was passed', () => {
    const payload = buildTaskPayload({
      ...baseNoAssigned,
      assignee_type: null,
      assignee_id: 'stale',
    });
    expect(payload.assignee_type).toBeNull();
    expect(payload.assignee_id).toBeNull();
  });

  it('falls back to the legacy assigned_id when assignee_type is omitted', () => {
    const payload = buildTaskPayload({ ...baseNoAssigned, assigned_id: 'u9' });
    expect(payload.assigned_id).toBe('u9');
    expect('assignee_type' in payload).toBe(false);
    expect('assignee_id' in payload).toBe(false);
  });
});
