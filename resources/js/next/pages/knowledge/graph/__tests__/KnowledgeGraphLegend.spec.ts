// @vitest-environment happy-dom
// KnowledgeGraphLegend.spec — the key to a code.
//
// The canvas separates edge kinds by DASH PATTERN, because colour is reserved for state (and
// because a pattern survives greyscale and colour blindness). A pattern with no key is decoration,
// so the legend is mandatory — and it is only useful while it matches what is drawn.
//
// This spec therefore checks the two things that can silently rot:
//   1. every kind the layout knows has a legend row (add a fifth kind, get a fifth row);
//   2. no two rows share a pattern — otherwise two relations look identical and the key lies.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setLocale, translate } from '../../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../../__tests__/helpers/dom';
import KnowledgeGraphLegend from '../KnowledgeGraphLegend.vue';
// Driven by the legend's OWN list, not by every kind the layout can carry: `manual` is a source
// nothing writes, so it has no pattern to explain. Importing the list rather than restating it is
// what keeps this test honest when the set changes again.
import { LEGEND_EDGE_KINDS } from '../knowledgeGraphLayout';

const t = translate;

describe('KnowledgeGraphLegend', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('pl');
  });
  afterEach(() => {
    restoreBrowserMocks();
    document.body.innerHTML = '';
  });

  it('carries one row per edge kind the layout knows about', () => {
    const wrapper = mount(KnowledgeGraphLegend, { attachTo: document.body });

    const kinds = wrapper.findAll('line[data-kind]').map((l) => l.attributes('data-kind'));
    expect(kinds).toEqual(LEGEND_EDGE_KINDS);
    // Including `mention`, added in B10.
    expect(kinds).toContain('mention');
    wrapper.unmount();
  });

  it('names every kind in words, not by its sample alone', () => {
    const wrapper = mount(KnowledgeGraphLegend, { attachTo: document.body });

    for (const kind of LEGEND_EDGE_KINDS) {
      expect(wrapper.text()).toContain(t(`knowledge.graph.edge.${kind}`));
    }
    wrapper.unmount();
  });

  it('gives each kind a DISTINCT pattern class — no two relations look alike', () => {
    const wrapper = mount(KnowledgeGraphLegend, { attachTo: document.body });

    const patterns = wrapper
      .findAll('line[data-kind]')
      .map((l) => l.classes().filter((c) => c.startsWith('is-')).join(' '));

    expect(new Set(patterns).size).toBe(LEGEND_EDGE_KINDS.length);
    wrapper.unmount();
  });

  it('marks the DIRECTED kinds with the arrowhead the canvas draws', () => {
    const wrapper = mount(KnowledgeGraphLegend, { attachTo: document.body });

    // wikilink + mention + relation point somewhere; similarity does not, and a ghost ends in the
    // hollow target circle instead. The relation sample is drawn directed because the MAJORITY of
    // the fifteen verbs are — the two symmetric ones lose the arrowhead on the canvas, where the
    // flag is known per edge, which the legend cannot show for a whole layer at once.
    expect(wrapper.findAll('.next-kg-legend-arrow')).toHaveLength(3);
    expect(wrapper.findAll('.next-kg-legend-ghost')).toHaveLength(1);
    // The mid-line square belonged to `manual`, which the legend no longer explains.
    expect(wrapper.findAll('.next-kg-legend-badge')).toHaveLength(0);
    wrapper.unmount();
  });

  it('doubles as a census when counts are supplied', () => {
    const wrapper = mount(KnowledgeGraphLegend, {
      attachTo: document.body,
      props: { counts: { mention: 3 } },
    });

    expect(wrapper.text()).toContain(
      t('knowledge.graph.edge.count', '', { label: t('knowledge.graph.edge.mention'), count: 3 }),
    );
    wrapper.unmount();
  });
});
