// @vitest-environment happy-dom
// SessionDirectionCard.spec — the READ-ONLY creative-direction card of the session chat (the direction layer).
//
// What matters here, in order of consequence:
//   1. VISIBILITY — the card must NEVER render an empty shell. Null, undefined, `{}`, and an object whose
//      every field is blank/whitespace/an empty list all produce NO card. A direction that carries even one
//      field does render. (A full-run claim NULLS the direction, so the null case is a real runtime state,
//      not a hypothetical.)
//   2. COLLAPSE — collapsed by default (the frame is context, not the artifact), with a working toggle whose
//      `aria-expanded` tracks the state and whose `aria-controls` resolves to the body it controls.
//   3. FIELD RENDERING — present fields render with their labels; ABSENT fields render neither label nor a
//      placeholder; arc beats render as an ORDERED list; visual-style facets as labeled chips; the duration
//      as "~N s"; continuity notes last.
//   4. PLAIN TEXT — every value is model-derived content. Markup in a value must reach the DOM as TEXT, not
//      as elements. This is the regression guard on the "never v-html / never a markdown renderer" rule.
//
// The card is DISPLAY-only: there must be no edit / regenerate / pin affordance (v2 scope).
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { setLocale } from '../../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../../__tests__/helpers/dom';
import SessionDirectionCard from '../SessionDirectionCard.vue';
import type { CreativeDirection } from '../../sessionTypes';

const full: CreativeDirection = {
  message: 'One tool, one afternoon, one finished garden bed.',
  goal: 'Drive spring pre-orders',
  audience: 'First-time balcony gardeners',
  tone: 'Warm and practical',
  through_line: 'From bare soil to first sprout',
  arc_beats: ['Bare balcony', 'Hands in soil', 'First sprout'],
  subject: 'A terracotta planter',
  setting: 'A small city balcony at golden hour',
  visual_style: {
    medium: 'Photography',
    palette: 'Warm earth tones',
    lighting: 'Golden hour',
    camera: 'Handheld, 35mm',
  },
  duration_target_seconds: 45,
  continuity_notes: 'The same planter appears in every frame.',
};

function mountCard(direction: CreativeDirection | null | undefined) {
  return mount(SessionDirectionCard, { props: { direction }, attachTo: document.body });
}

/** The collapse/expand control (the card's only interactive element). */
function toggleOf(wrapper: ReturnType<typeof mountCard>) {
  return wrapper.get('button');
}

describe('SessionDirectionCard', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  describe('visibility', () => {
    it('renders nothing when the direction is null (the state a full-run claim leaves behind)', () => {
      const wrapper = mountCard(null);
      expect(wrapper.find('.next-card').exists()).toBe(false);
      expect(wrapper.text()).toBe('');
      wrapper.unmount();
    });

    it('renders nothing when the direction is absent', () => {
      const wrapper = mountCard(undefined);
      expect(wrapper.find('.next-card').exists()).toBe(false);
      wrapper.unmount();
    });

    it('renders nothing for an empty object (no empty shell)', () => {
      const wrapper = mountCard({});
      expect(wrapper.find('.next-card').exists()).toBe(false);
      wrapper.unmount();
    });

    it('renders nothing when every field is blank, null or an empty list', () => {
      const wrapper = mountCard({
        message: '',
        goal: '   ',
        audience: null,
        tone: null,
        through_line: '',
        arc_beats: [],
        subject: null,
        setting: null,
        visual_style: {},
        duration_target_seconds: null,
        continuity_notes: '',
      });
      expect(wrapper.find('.next-card').exists()).toBe(false);
      wrapper.unmount();
    });

    it('renders when a SINGLE field survives (here: only arc beats)', () => {
      const wrapper = mountCard({ arc_beats: ['Only beat'] });
      expect(wrapper.find('.next-card').exists()).toBe(true);
      expect(wrapper.text()).toContain('Creative direction');
      wrapper.unmount();
    });

    it('renders when only the visual style survives', () => {
      const wrapper = mountCard({ visual_style: { palette: 'Muted blues' } });
      expect(wrapper.find('.next-card').exists()).toBe(true);
      wrapper.unmount();
    });

    it('renders when only the target duration survives', () => {
      const wrapper = mountCard({ duration_target_seconds: 30 });
      expect(wrapper.find('.next-card').exists()).toBe(true);
      wrapper.unmount();
    });
  });

  describe('collapse', () => {
    it('starts COLLAPSED: the header + teaser show, the body is hidden', async () => {
      const wrapper = mountCard(full);
      const toggle = toggleOf(wrapper);
      expect(toggle.attributes('aria-expanded')).toBe('false');

      // The teaser (the message) rides the collapsed HEADER — asserted on the header itself, since the body
      // stays mounted (v-show) and would otherwise satisfy a whole-wrapper text match.
      const header = wrapper.get('.next-card__header');
      expect(header.text()).toContain('Creative direction');
      expect(header.text()).toContain('One tool, one afternoon, one finished garden bed.');

      // The body is mounted but hidden (v-show) so `aria-controls` never dangles.
      const body = wrapper.get(`#${toggle.attributes('aria-controls')}`);
      expect((body.element as HTMLElement).style.display).toBe('none');

      // …and the teaser gives way to the body once expanded (it is a collapsed-state affordance).
      await toggle.trigger('click');
      expect(wrapper.get('.next-card__header').text()).not.toContain(
        'One tool, one afternoon, one finished garden bed.',
      );
      wrapper.unmount();
    });

    it('expands and collapses again on click, tracking aria-expanded', async () => {
      const wrapper = mountCard(full);
      const toggle = toggleOf(wrapper);
      const bodySelector = `#${toggle.attributes('aria-controls')}`;

      await toggle.trigger('click');
      expect(toggleOf(wrapper).attributes('aria-expanded')).toBe('true');
      expect((wrapper.get(bodySelector).element as HTMLElement).style.display).not.toBe('none');

      await toggleOf(wrapper).trigger('click');
      expect(toggleOf(wrapper).attributes('aria-expanded')).toBe('false');
      expect((wrapper.get(bodySelector).element as HTMLElement).style.display).toBe('none');
      wrapper.unmount();
    });

    it('falls back to the through-line as the teaser when there is no message', () => {
      const wrapper = mountCard({ through_line: 'From bare soil to first sprout' });
      expect(wrapper.get('.next-card__header').text()).toContain('From bare soil to first sprout');
      wrapper.unmount();
    });

    it('is DISPLAY-only — the collapse control is the card’s only button', () => {
      const wrapper = mountCard(full);
      expect(wrapper.findAll('button')).toHaveLength(1);
      wrapper.unmount();
    });
  });

  describe('expanded content', () => {
    async function expanded(direction: CreativeDirection) {
      const wrapper = mountCard(direction);
      await toggleOf(wrapper).trigger('click');
      return wrapper;
    }

    it('renders every present field with its label', async () => {
      const wrapper = await expanded(full);
      const text = wrapper.text();
      for (const label of [
        'Message',
        'Goal',
        'Audience',
        'Tone',
        'Through-line',
        'Subject',
        'Setting',
        'Arc beats',
        'Visual style',
        'Target duration',
        'Continuity notes',
      ]) {
        expect(text).toContain(label);
      }
      expect(text).toContain('Drive spring pre-orders');
      expect(text).toContain('First-time balcony gardeners');
      expect(text).toContain('A terracotta planter');
      expect(text).toContain('The same planter appears in every frame.');
      // The muted provenance caption.
      expect(text).toContain('Derived automatically for this run.');
      wrapper.unmount();
    });

    it('SKIPS absent fields — no label, no placeholder', async () => {
      const wrapper = await expanded({ goal: 'Drive spring pre-orders' });
      const text = wrapper.text();
      expect(text).toContain('Goal');
      expect(text).not.toContain('Audience');
      expect(text).not.toContain('Tone');
      expect(text).not.toContain('Arc beats');
      expect(text).not.toContain('Visual style');
      expect(text).not.toContain('Target duration');
      expect(text).not.toContain('Continuity notes');
      wrapper.unmount();
    });

    it('skips blank / whitespace-only values the same way as absent ones', async () => {
      const wrapper = await expanded({ goal: 'Drive spring pre-orders', audience: '   ', tone: '' });
      const text = wrapper.text();
      expect(text).toContain('Goal');
      expect(text).not.toContain('Audience');
      expect(text).not.toContain('Tone');
      wrapper.unmount();
    });

    it('renders arc beats as an ORDERED list, in wire order', async () => {
      const wrapper = await expanded(full);
      const items = wrapper.findAll('ol li');
      expect(items).toHaveLength(3);
      expect(items[0].text()).toContain('Bare balcony');
      expect(items[1].text()).toContain('Hands in soil');
      expect(items[2].text()).toContain('First sprout');
      wrapper.unmount();
    });

    it('renders the visual style as labeled chips (label + value, never color alone)', async () => {
      const wrapper = await expanded(full);
      const text = wrapper.text();
      expect(text).toContain('Medium: Photography');
      expect(text).toContain('Palette: Warm earth tones');
      expect(text).toContain('Lighting: Golden hour');
      expect(text).toContain('Camera: Handheld, 35mm');
      wrapper.unmount();
    });

    it('drops visual-style facets with no value', async () => {
      const wrapper = await expanded({ visual_style: { medium: 'Photography', palette: '', camera: null } });
      const text = wrapper.text();
      expect(text).toContain('Medium: Photography');
      expect(text).not.toContain('Palette');
      expect(text).not.toContain('Camera');
      wrapper.unmount();
    });

    it('formats the target duration as "~N s"', async () => {
      const wrapper = await expanded(full);
      expect(wrapper.text()).toContain('~45 s');
      wrapper.unmount();
    });

    it('localizes to Polish', async () => {
      setLocale('pl');
      const wrapper = await expanded(full);
      const text = wrapper.text();
      expect(text).toContain('Kierunek kreatywny');
      expect(text).toContain('Przekaz');
      expect(text).toContain('Beaty narracji');
      expect(text).toContain('Wyprowadzony automatycznie dla tego uruchomienia.');
      setLocale('en');
      wrapper.unmount();
    });
  });

  describe('plain-text rendering (security)', () => {
    // Every value is MODEL-derived content laundered from user-supplied slot values. It must reach the DOM
    // as text — if this ever regresses to v-html / a markdown renderer, these assertions fail.
    const hostile: CreativeDirection = {
      message: '<img src=x onerror="alert(1)">',
      goal: '<script>alert(2)</script>',
      arc_beats: ['<b>bold beat</b>'],
      visual_style: { medium: '<em>italic medium</em>' },
      continuity_notes: '[link](javascript:alert(3))',
    };

    it('renders markup in values as TEXT, injecting no elements', async () => {
      const wrapper = mountCard(hostile);
      await toggleOf(wrapper).trigger('click');
      const html = wrapper.html();

      // No value became a real element.
      expect(wrapper.find('img').exists()).toBe(false);
      expect(wrapper.find('script').exists()).toBe(false);
      expect(wrapper.find('b').exists()).toBe(false);
      expect(wrapper.find('em').exists()).toBe(false);
      expect(wrapper.find('a').exists()).toBe(false);
      // …and the raw source survives, escaped, as visible text.
      expect(html).toContain('&lt;img src=x onerror=');
      expect(wrapper.text()).toContain('<img src=x onerror="alert(1)">');
      expect(wrapper.text()).toContain('<b>bold beat</b>');
      expect(wrapper.text()).toContain('<em>italic medium</em>');
      expect(wrapper.text()).toContain('[link](javascript:alert(3))');
      wrapper.unmount();
    });

    it('renders markup in the collapsed TEASER as text too', () => {
      const wrapper = mountCard(hostile);
      expect(wrapper.find('img').exists()).toBe(false);
      expect(wrapper.text()).toContain('<img src=x onerror="alert(1)">');
      wrapper.unmount();
    });
  });
});
