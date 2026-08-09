// @vitest-environment happy-dom
// factChecklist.spec — the reviewer's checklist, and the one row on it that matters.
//
// This exists because dramatic facts were vanishing out of the source text — a drinking incident,
// a wave of abuse — and nothing said so. It cannot be automated away: a model that writes up the
// departure date and omits the incident reports its coverage perfectly honestly. The PERSON is the
// check, so the only question these tests ask is whether the person has something to read.
//
// IT NO LONGER JUDGES COVERAGE. The flagging it used to do was driven by a field the model ignores,
// so the claims came back empty for every fact and the panel shouted "nothing covered" over entries
// that covered half the material. A warning that fires nine times out of nine trains people to stop
// reading the panel — and would have taken the list's real value with it.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import KnowledgeFactChecklist from '../KnowledgeFactChecklist.vue';
import { setLocale, translate as t } from '../../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../../__tests__/helpers/dom';

const INCIDENT = {
  id: 'F2',
  text: 'Łukasz przesadził z alkoholem i wywołał falę hejtu',
  date: '15 sierpnia',
  subjects: ['Łukasz'],
};
const ORDINARY = { id: 'F1', text: 'Łukasz poleciał do Lizbony', date: '12 sierpnia', subjects: ['Łukasz'] };

function mountList(props: Record<string, unknown> = {}) {
  return mount(KnowledgeFactChecklist, { attachTo: document.body, props });
}

describe('the checklist beside the proposals', () => {
  beforeEach(() => {
    setLocale('pl');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('lists what the material says happened', () => {
    const wrapper = mountList({ facts: [ORDINARY, INCIDENT] });

    expect(wrapper.findAll('[data-fact]')).toHaveLength(2);
    expect(wrapper.text()).toContain('Łukasz poleciał do Lizbony');
    wrapper.unmount();
  });

  it('keeps the MATERIAL’S order and ranks nothing', () => {
    // Nothing is floated any more: the source's sequence is what the reviewer reads against, so
    // reordering makes the comparison harder rather than easier.
    const wrapper = mountList({ facts: [ORDINARY, INCIDENT] });

    expect(wrapper.findAll('[data-fact]').map((r) => r.attributes('data-fact'))).toEqual(['F1', 'F2']);
    wrapper.unmount();
  });

  it('passes NO verdict on whether a fact was covered', () => {
    // The claims field the old flagging relied on comes back empty for everything, so any verdict
    // built on it was false. The list states the material and stops there.
    const wrapper = mountList({
      facts: [{ ...INCIDENT, covered: false, covered_by: [] }],
    });
    const row = wrapper.find('[data-fact="F2"]');

    expect(row.attributes('data-uncovered')).toBeUndefined();
    expect(wrapper.find('[data-uncovered-count]').exists()).toBe(false);
    expect(wrapper.find('[data-fact-uncovered]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('shows an assignment ONLY when something actually claimed the fact', () => {
    // `covered: false` with an empty list is the ordinary case now, and it must render as nothing
    // rather than as an absence.
    const empty = mountList({ facts: [{ ...ORDINARY, covered: false, covered_by: [] }] });
    expect(empty.find('[data-covered-by="F1"]').exists()).toBe(false);
    empty.unmount();

    const claimed = mountList({
      facts: [{ ...ORDINARY, covered: true, covered_by: [{ id: 'd1', slug: 'x', title: 'Łukasz' }] }],
    });
    expect(claimed.find('[data-covered-by="F1"]').exists()).toBe(true);
    claimed.unmount();
  });

  it('renders the date EXACTLY as the source wrote it', () => {
    // The reviewer compares this against their own text. A date this screen had normalised to
    // `2026-08-15` would be one more thing to take on trust instead of the thing they can check.
    const wrapper = mountList({ facts: [ORDINARY] });

    expect(wrapper.find('[data-fact-date]').text()).toBe('12 sierpnia');
    wrapper.unmount();
  });
});

describe('the three edge states are three different sentences', () => {
  beforeEach(() => {
    setLocale('pl');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('NO FACTS: the material had none, and that is not a fault', () => {
    const wrapper = mountList({ facts: [] });

    expect(wrapper.find('[data-facts-empty]').exists()).toBe(true);
    expect(wrapper.find('[data-facts-unavailable]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('UNAVAILABLE: the reading did not run — a statement about the tool, said calmly', () => {
    const wrapper = mountList({ facts: [], notes: [{ code: 'facts_unavailable' }] });

    expect(wrapper.find('[data-facts-unavailable]').exists()).toBe(true);
    // Explicitly NOT the "material has no events" wording: that would blame the text.
    expect(wrapper.find('[data-facts-empty]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('TRUNCATED: the list was cut by the tool, and says so with the number', () => {
    // A reviewer who counts thirty and stops is a reviewer who believes they have seen everything.
    const wrapper = mountList({
      facts: [ORDINARY],
      notes: [{ code: 'facts_truncated', max: 30 }],
    });

    expect(wrapper.find('[data-facts-truncated]').text()).toContain('30');
    wrapper.unmount();
  });

  it('shows no truncation line on an ordinary run', () => {
    expect(mountList({ facts: [ORDINARY] }).find('[data-facts-truncated]').exists()).toBe(false);
  });
});

// ------------------------------------------------------------------------------------------------
// WHERE A FACT LANDED.
//
// `covered_by` is a LIST, and that is the substance: an episode between two people belongs in BOTH
// their chronicles, so "Łukasz przeprosił influencerkę" is one fact two entries rightly cover.
// Rendering only the first would make a correct answer look partial.
describe('the entries that claimed a fact', () => {
  beforeEach(() => {
    setLocale('pl');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  const SHARED = {
    id: 'F3',
    text: 'Łukasz przeprosił influencerkę',
    date: '13 września',
    subjects: ['Łukasz', 'Influencerka'],
    covered: true,
    covered_by: [
      { id: 'd1', slug: 'lukasz-barszcz', title: 'Łukasz Barszcz' },
      { id: 'd2', slug: 'influencerka', title: 'Influencerka' },
    ],
  };

  it('renders EVERY entry that covered it, not just the first', () => {
    const wrapper = mountList({ facts: [SHARED] });
    const claims = wrapper.findAll('[data-claim]');

    expect(claims).toHaveLength(2);
    expect(claims.map((c) => c.text())).toEqual(['Łukasz Barszcz', 'Influencerka']);
    wrapper.unmount();
  });

  it('offers a way to each one', async () => {
    const wrapper = mountList({ facts: [SHARED] });

    await wrapper.find('[data-claim="d2"]').trigger('click');

    // The DRAFT id, because a draft is a card on the board rather than a page to route to.
    expect(wrapper.emitted('open')?.[0]).toEqual(['d2']);
    wrapper.unmount();
  });

  it('names each link for a screen reader, since "Influencerka" alone says nothing about the act', () => {
    const wrapper = mountList({ facts: [SHARED] });

    expect(wrapper.find('[data-claim="d1"]').attributes('aria-label')).toBe(
      t('knowledge.compose.facts.openClaim', '', { title: 'Łukasz Barszcz' }),
    );
    wrapper.unmount();
  });

  it('shows no assignment for a fact nothing claimed, and no warning either', () => {
    // The old version of this asserted a warning badge. That badge is gone with the signal that
    // drove it — the claims field the model ignores — so the row is simply plain.
    const wrapper = mountList({ facts: [{ ...INCIDENT, covered: false, covered_by: [] }] });

    expect(wrapper.find('[data-covered-by="F2"]').exists()).toBe(false);
    expect(wrapper.find('[data-fact="F2"]').attributes('data-uncovered')).toBeUndefined();
    wrapper.unmount();
  });

  it('survives an OLDER session with no claims recorded at all', () => {
    // Nobody asked those runs to record a claim, so the absence is accurate rather than a gap —
    // and it must not render as one, nor throw on the missing key.
    const wrapper = mountList({ facts: [ORDINARY] });

    expect(wrapper.find('[data-fact="F1"]').exists()).toBe(true);
    expect(wrapper.find('[data-covered-by="F1"]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('STILL claims nothing about quality — a covered row is not a handled row', () => {
    // The load-bearing one, and it outlived the flagging: an entry that records the date and omits
    // what happened reports its coverage honestly, so "covered" may never read as "done well".
    // There is no approving language on the row — only where it went.
    const wrapper = mountList({ facts: [SHARED] });
    const row = wrapper.find('[data-fact="F3"]');

    expect(row.attributes('data-uncovered')).toBeUndefined();
    expect(row.text()).not.toContain('✓');
    wrapper.unmount();
  });
});
