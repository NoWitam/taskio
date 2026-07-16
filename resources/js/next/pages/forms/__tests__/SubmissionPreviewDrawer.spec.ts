// @vitest-environment happy-dom
// SubmissionPreviewDrawer.spec — the `diff` mode contract (Workflows run detail, B7).
// Pins the snapshot-vs-current diff: a CHANGED field surfaces the "changed since this
// run" status + highlights the row with the project-wide `modified` token + a "changed"
// marker (never color-only); an UNCHANGED submission reads "Unchanged". The forms store
// (single-submission fetch) + vue-router (the "open original" href) are mocked.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import SubmissionPreviewDrawer from '../SubmissionPreviewDrawer.vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

// --- Forms store mock (single-submission fetch + form-schema fetch) ---------
const fetchSubmission = vi.fn();
const fetchForm = vi.fn();
vi.mock('../../../app/stores/forms', () => ({
  useFormsStore: () => ({ fetchSubmission, fetchForm }),
}));

// --- Router mock (the "open original submission" href) ----------------------
vi.mock('vue-router', () => ({
  useRouter: () => ({
    resolve: (loc: { params?: { id?: string } }) => ({
      href: `/next/forms/${loc.params?.id ?? ''}/submissions`,
    }),
  }),
}));

function mountDiff(props: Record<string, unknown> = {}) {
  return mount(SubmissionPreviewDrawer, {
    attachTo: document.body,
    props: {
      open: true,
      mode: 'diff',
      submissionId: 'sub-1',
      formId: 'form-1',
      submittedAt: '2026-07-02T14:00:00Z',
      source: 'manual',
      isAnonymous: false,
      snapshotFields: { color: 'red', size: 'M' },
      ...props,
    },
  });
}

beforeEach(() => {
  installBrowserMocks();
  setLocale('en');
  fetchSubmission.mockReset();
  fetchForm.mockReset();
  // Default: no schema loaded → the drawer degrades to the raw key/value view
  // (the diff-status assertions below don't depend on a labelled schema).
  fetchForm.mockResolvedValue({ content: [] });
});

afterEach(() => {
  restoreBrowserMocks();
  document.body.innerHTML = '';
});

describe('SubmissionPreviewDrawer — diff mode (B7)', () => {
  it('flags a changed field: "Changed since this run" + a highlighted row + marker', async () => {
    // Current submission differs on `color` (red → blue); `size` unchanged.
    fetchSubmission.mockResolvedValue({ data: { color: 'blue', size: 'M' } });
    mountDiff();
    await flushPromises();

    const body = document.body;
    expect(fetchSubmission).toHaveBeenCalledWith('sub-1');
    expect(body.textContent).toContain('Changed since this run');
    // The changed field carries the modified token background + a "changed" marker.
    expect(body.querySelector('.bg-next-modified-subtle')).not.toBeNull();
    expect(body.textContent).toContain('changed');
    // The current value surfaces via the "Now:" line.
    expect(body.textContent).toContain('Now: blue');
  });

  it('reads "Unchanged" when the current submission matches the snapshot', async () => {
    fetchSubmission.mockResolvedValue({ data: { color: 'red', size: 'M' } });
    mountDiff();
    await flushPromises();

    expect(document.body.textContent).toContain('Unchanged');
    expect(document.body.querySelector('.bg-next-modified-subtle')).toBeNull();
    expect(document.body.textContent).not.toContain('Changed since this run');
  });

  it('treats order-shuffled array answers as unchanged (canonical compare)', async () => {
    fetchSubmission.mockResolvedValue({ data: { tags: ['b', 'a'] } });
    mountDiff({ snapshotFields: { tags: ['a', 'b'] } });
    await flushPromises();

    expect(document.body.textContent).toContain('Unchanged');
    expect(document.body.querySelector('.bg-next-modified-subtle')).toBeNull();
  });

  it('degrades gracefully when the current version cannot be fetched', async () => {
    fetchSubmission.mockRejectedValue(new Error('gone'));
    mountDiff();
    await flushPromises();

    // The snapshot still renders; no changed/unchanged verdict is claimed.
    expect(document.body.textContent).toContain('Couldn’t load the current version');
    expect(document.body.textContent).not.toContain('Changed since this run');
    expect(document.body.textContent).not.toContain('Unchanged');
  });

  it('links "open original submission" to the form\'s submissions area (new tab)', async () => {
    fetchSubmission.mockResolvedValue({ data: { color: 'red', size: 'M' } });
    mountDiff();
    await flushPromises();

    const link = Array.from(document.body.querySelectorAll('a')).find((a) =>
      (a.textContent ?? '').includes('Open original submission'),
    );
    expect(link).toBeTruthy();
    expect(link?.getAttribute('href')).toBe('/next/forms/form-1/submissions');
    expect(link?.getAttribute('target')).toBe('_blank');
  });

  // --- schema-driven labelled rendering (Problem C) --------------------------
  it('renders answers as LABELLED, type-formatted fields (not raw JSON), keeps the changed highlight, and falls back for a deleted field', async () => {
    // A form whose content nests the answers under a section: label + option +
    // checkbox fields. The snapshot ALSO carries a `legacy_note` key with no
    // matching schema element (a field removed since the run).
    fetchForm.mockResolvedValue({
      content: [
        {
          id: 'dane',
          type: 'section',
          config: {
            name: 'Employee data',
            children: [
              { id: 'fullName', type: 'short_text', config: { label: 'Full name' } },
              { id: 'age', type: 'number', config: { label: 'Age' } },
              {
                id: 'team',
                type: 'select',
                config: {
                  label: 'Team',
                  options: [
                    { value: 'eng', label: 'Engineering' },
                    { value: 'hr', label: 'Human Resources' },
                  ],
                },
              },
              { id: 'consent', type: 'checkbox', config: { label: 'Consent given' } },
            ],
          },
        },
      ],
    });
    // Current differs on `team` (eng → hr); everything else matches.
    fetchSubmission.mockResolvedValue({
      data: { dane: { fullName: 'Ann Smith', age: 24, team: 'hr', consent: true }, legacy_note: 'old value' },
    });
    mountDiff({
      snapshotFields: { dane: { fullName: 'Ann Smith', age: 24, team: 'eng', consent: true }, legacy_note: 'old value' },
    });
    await flushPromises();

    const body = document.body;
    expect(fetchForm).toHaveBeenCalledWith('form-1');

    // Field LABELS (from the schema) + human values — never the raw JSON blob.
    expect(body.textContent).toContain('Full name');
    expect(body.textContent).toContain('Ann Smith');
    expect(body.textContent).toContain('Team');
    // The select renders the option LABEL, not the raw stored value ('eng').
    expect(body.textContent).toContain('Engineering');
    // Checkbox → yes/no.
    expect(body.textContent).toContain('Consent given');
    expect(body.textContent).toContain('Yes');
    // No raw-JSON dump of the nested section object.
    expect(body.textContent).not.toContain('"fullName"');

    // The changed field (team) keeps the modified highlight + a "Now: <label>" line.
    expect(body.querySelector('.bg-next-modified-subtle')).not.toBeNull();
    expect(body.textContent).toContain('Changed since this run');
    expect(body.textContent).toContain('Now: Human Resources');

    // The deleted field is NOT dropped — it falls back to the raw key + value.
    expect(body.textContent).toContain('legacy_note');
    expect(body.textContent).toContain('old value');
  });
});
