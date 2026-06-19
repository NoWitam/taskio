<script setup lang="ts">
// Gallery: FieldShell — the bordered box + state line shared by every text-like
// control. Demonstrates all states (default/hover/focus/error/success/dirty/
// disabled/readonly) in light + dark, leading/trailing adornments, a multi-control
// (segmented) field, and the no-grow / truncation rule.
import { ref } from 'vue';
import FieldShell from '../../ui/forms/FieldShell.vue';
import Icon from '../../ui/primitives/Icon.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const sizes = ['sm', 'md', 'lg'] as const;

// A bare input matching how a control fills the shell body (truncate + min-w-0).
const longValue =
  'A very long value that overflows the control and must truncate with an ellipsis rather than widening the field';

const day = ref('');
const month = ref('');
const year = ref('');

const propRows: ApiRow[] = [
  { name: 'size', type: "'sm' | 'md' | 'lg'", default: "'md'", description: 'Fixed control height + text scale.' },
  { name: 'focused', type: 'boolean', default: '—', description: 'Force/clear the focus line. Omit to let :focus-within drive it.' },
  { name: 'disabled', type: 'boolean', default: 'false', description: 'Muted fill, dimmed, inert.' },
  { name: 'readonly', type: 'boolean', default: 'false', description: 'Muted fill (orthogonal — can still be error/success).' },
  { name: 'error', type: 'boolean', default: 'false', description: 'Danger inset ring + border (same shape as focus).' },
  { name: 'success', type: 'boolean', default: 'false', description: 'Success inset ring + border.' },
  { name: 'dirty', type: 'boolean', default: 'false', description: 'Subtle primary border accent (no ring).' },
  { name: 'segmented', type: 'boolean', default: 'false', description: 'Evenly weight slot children with internal rules (split field).' },
];

const slotRows: ApiRow[] = [
  { name: 'default', type: 'control(s)', description: 'One or several bare controls sharing one border + state line.' },
  { name: 'leading', type: 'adornment', description: 'Inside the border, before the control (icon, unit, button).' },
  { name: 'trailing', type: 'adornment', description: 'Inside the border, after the control (clear, toggle, stepper, unit).' },
];
</script>

<template>
  <StoryPage
    title="FieldShell"
    description="The bordered box + state line shared by every text-like control (TextInput, NumberInput, Select trigger, the Slider's number box). It owns the border, the state line (an inset ring ON the border at offset 0), and the leading/trailing adornment slots. Controls render their inner element inside it."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>The state line is an <strong>inset ring</strong> (<code>box-shadow: inset 0 0 0 1.5px</code>) + matching <code>border-color</code> — never an outward <code>outline-offset</code> ring, so it sits exactly on the border.</li>
        <li>Focus is detected via <code>:focus-within</code>, so the inner focusable element stays the focus target (no double ring).</li>
        <li><strong>Precedence:</strong> disabled &gt; error &gt; success &gt; focus &gt; dirty &gt; default. <code>readonly</code> is orthogonal (fill/cursor only).</li>
        <li>error + focus share the same shape; only the color differs (danger vs ring). success matches in the success color.</li>
      </ul>
    </template>

    <StorySection title="Sizes" description="Fixed height per size — content never changes it.">
      <StoryGrid align="center">
        <StoryCell v-for="s in sizes" :key="s" :label="s">
          <div class="w-56">
            <FieldShell :size="s">
              <input class="h-full w-full min-w-0 flex-1 truncate border-0 bg-transparent px-next-3 text-current outline-none placeholder:text-next-muted-foreground" placeholder="Placeholder" />
            </FieldShell>
          </div>
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="States" description="default · hover (live) · focus (live) · dirty · success · error · readonly · disabled. Each line sits exactly on the border.">
      <div class="grid max-w-md gap-next-4">
        <StoryCell label="default (focus / hover live)">
          <div class="w-full"><FieldShell><input class="h-full w-full min-w-0 flex-1 truncate border-0 bg-transparent px-next-3 text-current outline-none" placeholder="Click to focus" /></FieldShell></div>
        </StoryCell>
        <StoryCell label="dirty (subtle accent, no ring)">
          <div class="w-full"><FieldShell dirty><input class="h-full w-full min-w-0 flex-1 truncate border-0 bg-transparent px-next-3 text-current outline-none" value="changed" /></FieldShell></div>
        </StoryCell>
        <StoryCell label="success">
          <div class="w-full"><FieldShell success><input class="h-full w-full min-w-0 flex-1 truncate border-0 bg-transparent px-next-3 text-current outline-none" value="looks good" /></FieldShell></div>
        </StoryCell>
        <StoryCell label="error">
          <div class="w-full"><FieldShell error><input class="h-full w-full min-w-0 flex-1 truncate border-0 bg-transparent px-next-3 text-current outline-none" value="bad value" /></FieldShell></div>
        </StoryCell>
        <StoryCell label="readonly">
          <div class="w-full"><FieldShell readonly><input class="h-full w-full min-w-0 flex-1 truncate border-0 bg-transparent px-next-3 text-current outline-none" value="read-only" readonly /></FieldShell></div>
        </StoryCell>
        <StoryCell label="disabled">
          <div class="w-full"><FieldShell disabled><input class="h-full w-full min-w-0 flex-1 truncate border-0 bg-transparent px-next-3 text-current outline-none" value="disabled" disabled /></FieldShell></div>
        </StoryCell>
      </div>
    </StorySection>

    <StorySection title="Adornments" description="#leading and #trailing render inside the border, vertically centered, never overlapping the text.">
      <div class="grid max-w-md gap-next-4">
        <StoryCell label="leading icon + trailing unit">
          <div class="w-full">
            <FieldShell>
              <template #leading><Icon name="search" /></template>
              <input class="h-full w-full min-w-0 flex-1 truncate border-0 bg-transparent px-next-2 text-current outline-none" placeholder="Search…" />
              <template #trailing><span class="text-next-sm text-next-muted-foreground">⌘K</span></template>
            </FieldShell>
          </div>
        </StoryCell>
        <StoryCell label="trailing button">
          <div class="w-full">
            <FieldShell>
              <input class="h-full w-full min-w-0 flex-1 truncate border-0 bg-transparent px-next-3 text-current outline-none" placeholder="With a button" />
              <template #trailing>
                <button type="button" class="rounded-next-sm p-next-0_5 text-next-muted-foreground hover:text-next-fg" aria-label="Clear"><Icon name="x" /></button>
              </template>
            </FieldShell>
          </div>
        </StoryCell>
      </div>
    </StorySection>

    <StorySection title="Multiple controls (segmented)" description="One border + one state line shared by several bare controls, divided by thin internal rules. Click into a segment to focus the whole shell. The dividers track the state line: at rest they are a 1px neutral rule; in any active state (focus/error/success/dirty) they thicken to 1.5px in the state color to match the border. Segment edges stay flush rectangles even when an inner control is focused (no bent/crooked divider).">
      <div class="w-64">
        <FieldShell segmented>
          <input v-model="day" class="h-full w-full min-w-0 truncate border-0 bg-transparent px-next-2 text-center text-current outline-none" placeholder="DD" aria-label="Day" maxlength="2" />
          <input v-model="month" class="h-full w-full min-w-0 truncate border-0 bg-transparent px-next-2 text-center text-current outline-none" placeholder="MM" aria-label="Month" maxlength="2" />
          <input v-model="year" class="h-full w-full min-w-0 truncate border-0 bg-transparent px-next-2 text-center text-current outline-none" placeholder="YYYY" aria-label="Year" maxlength="4" />
        </FieldShell>
      </div>
    </StorySection>

    <StorySection title="No-grow / truncation" description="A long value truncates with an ellipsis — the field keeps its width and height.">
      <div class="w-56">
        <FieldShell>
          <input class="h-full w-full min-w-0 flex-1 truncate border-0 bg-transparent px-next-3 text-current outline-none" :value="longValue" />
        </FieldShell>
      </div>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Slots" type-header="Content" :rows="slotRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
