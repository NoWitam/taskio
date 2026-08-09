// @vitest-environment happy-dom
// KnowledgeRelationSentence.spec — the two layers, and the fact that they can never disagree.
//
// The component's whole reason to exist is that a relation is a SENTENCE and a tuple is not. The
// visible line is dense and scannable (bold ends, a Badge verb, an arrow); the accessible layer is
// prose. A screen reader hearing "Anna member of arrow right Acme" would be worse off than one
// hearing nothing, which is why the visual half is `aria-hidden` in its entirety.
import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import KnowledgeRelationSentence from '../KnowledgeRelationSentence.vue';
import { setLocale } from '../../../../app/i18n';

const ANNA = { title: 'Anna Kowalska', entry_type: 'person' as const };
const ACME = { title: 'Acme', entry_type: 'organization' as const };

function mountSentence(props: Record<string, unknown> = {}) {
  return mount(KnowledgeRelationSentence, {
    props: {
      subject: ANNA,
      object: ACME,
      relationType: 'member_of',
      label: 'is a member of',
      inverseLabel: 'has member',
      ...props,
    },
  });
}

/** The visually-hidden prose — what a screen reader actually announces. */
function prose(wrapper: ReturnType<typeof mountSentence>): string {
  return wrapper.find('.next-sr-only').text();
}

describe('the accessible layer is a SENTENCE', () => {
  beforeEach(() => setLocale('en'));

  it('announces subject, verb and object as prose', () => {
    expect(prose(mountSentence())).toBe('Anna Kowalska is a member of Acme');
  });

  it('never leaks the raw predicate id into the UI', () => {
    const wrapper = mountSentence();

    // `member_of` appears nowhere a user can read — not in the prose, not on screen.
    expect(wrapper.text()).not.toContain('member_of');
  });

  it('folds the validity into the sentence rather than gluing it on', () => {
    expect(prose(mountSentence({ validFrom: '2024-01-15' }))).toContain('from 01.2024');
  });

  it('says UNTIL, not FROM, once the claim is about the past', () => {
    const ended = prose(mountSentence({ validFrom: '2020-01-01', validTo: '2024-06-01' }));

    expect(ended).toContain('until 06.2024');
    // The end date wins the sentence: what matters about an ended relation is that it ended.
    expect(ended).not.toContain('from 01.2020');
  });
});

describe('the visual layer is hidden from the a11y tree', () => {
  beforeEach(() => setLocale('en'));

  it('hides the arrow and the Badge, so the verb is not read as "arrow right"', () => {
    const wrapper = mountSentence();
    const visual = wrapper.findAll('[aria-hidden="true"]');

    expect(visual.length).toBeGreaterThan(0);
    // Every SVG in this component is decoration for prose that already exists.
    for (const svg of wrapper.findAll('svg')) {
      expect(svg.attributes('aria-hidden') ?? svg.element.closest('[aria-hidden="true"]')).toBeTruthy();
    }
  });

  it('draws NO arrow for a symmetric verb — it carries no direction to assert', () => {
    const symmetric = mountSentence({
      relationType: 'knows',
      label: 'knows',
      inverseLabel: 'knows',
      symmetric: true,
    });
    const asymmetric = mountSentence();

    expect(symmetric.findAll('svg').length).toBeLessThan(asymmetric.findAll('svg').length);
  });
});

describe('direction', () => {
  beforeEach(() => setLocale('en'));

  it('uses the INVERSE verb when read from the other end', () => {
    // The caller passes the ends already ordered; the component words the verb for the direction.
    const wrapper = mountSentence({ subject: ACME, object: ANNA, direction: 'inverse' });

    expect(prose(wrapper)).toBe('Acme has member Anna Kowalska');
  });

  it('ignores the requested direction for a symmetric verb', () => {
    const props = { relationType: 'knows', label: 'knows', inverseLabel: 'known by', symmetric: true };

    expect(prose(mountSentence({ ...props, direction: 'inverse' }))).toContain('knows');
    expect(prose(mountSentence({ ...props, direction: 'inverse' }))).not.toContain('known by');
  });
});

describe('dates carry the exact value even though the text is short', () => {
  beforeEach(() => setLocale('en'));

  it('puts the FULL ISO date in `datetime` while showing the month', () => {
    const wrapper = mountSentence({ validFrom: '2024-01-15' });
    const time = wrapper.find('time');

    expect(time.attributes('datetime')).toBe('2024-01-15');
    expect(time.text()).toContain('01.2024');
  });
});

describe('a missing end', () => {
  beforeEach(() => setLocale('en'));

  it('renders a dash rather than a blank — a gap reads as a rendering fault', () => {
    // Real case: the graph sends relation edges whose ends are ids, and a review proposal can point
    // at a draft that has no title yet.
    const wrapper = mountSentence({ object: null });

    expect(wrapper.text()).toContain('—');
  });
});
