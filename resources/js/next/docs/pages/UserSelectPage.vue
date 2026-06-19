<script setup lang="ts">
// Gallery: UserSelect — the global people picker. Single + multiple, avatar in
// options/chips/value (initials fallback when avatar is null), and async cursor
// pagination via a self-contained mock loader (latency → skeletons). Demo-only
// strings use t() with literal fallbacks; no real backend.
import { ref } from 'vue';
import UserSelect from '../../ui/forms/UserSelect.vue';
import type { SelectFetchArgs, SelectFetchResult, SelectOption } from '../../ui/forms/Select.vue';
import FormField from '../../ui/forms/FormField.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

// --- Mock /users dataset (cursor pages of 8, 450ms latency) ----------------
interface MockUser {
  value: string;
  label: string;
  email: string;
  avatar: string | null;
}
const FIRST = ['Ava', 'Ben', 'Chloe', 'Dan', 'Eva', 'Finn', 'Gabe', 'Hana', 'Iris', 'Jon', 'Kira', 'Leo', 'Mia', 'Noah', 'Omar', 'Pia', 'Quinn', 'Rosa', 'Sam', 'Tara'];
const LAST = ['Stone', 'Carter', 'Diaz', 'Ellis', 'Frost', 'Gray', 'Hunt', 'Ito', 'Jones', 'Khan'];
const ALL_USERS: MockUser[] = FIRST.map((f, i) => {
  const name = `${f} ${LAST[i % LAST.length]}`;
  return {
    value: `u${i}`,
    label: name,
    email: `${f.toLowerCase()}@taskio.test`,
    avatar: null, // mirrors UserResource (avatar: null) → initials fallback
  };
});
const PAGE = 8;

function mockFetchUsers(args: SelectFetchArgs): Promise<SelectFetchResult> {
  const q = args.query.trim().toLowerCase();
  const matches = ALL_USERS.filter((u) => !q || u.label.toLowerCase().includes(q));
  const start = args.cursor ? Number(args.cursor) : 0;
  const page = matches.slice(start, start + PAGE);
  const next = start + PAGE;
  const nextCursor = next < matches.length ? String(next) : null;
  return new Promise((resolve) =>
    setTimeout(
      () =>
        resolve({
          options: page.map((u) => ({
            value: u.value,
            label: u.label,
            email: u.email,
            avatar: u.avatar,
          })) as SelectOption[],
          nextCursor,
        }),
      450,
    ),
  );
}

const single = ref<string | null>(null);
const multi = ref<string[]>([]);
// A pre-selected, possibly off-page value seeded so its chip renders immediately.
const preselected = ref<string[]>(['u0', 'u3']);
const seed = [
  { id: 'u0', name: 'Ava Stone', email: 'ava@taskio.test', avatar: null },
  { id: 'u3', name: 'Dan Ellis', email: 'dan@taskio.test', avatar: null },
];
const formVal = ref<string | null>(null);

const propRows: ApiRow[] = [
  { name: 'v-model', type: 'string | null', default: 'null', description: 'Selected user id (single mode).' },
  { name: 'v-model:values', type: 'string[]', default: '[]', description: 'Selected user ids (when :multiple).' },
  { name: 'multiple', type: 'boolean', default: 'false', description: 'Pick several people (chips / summary).' },
  { name: 'display / summary', type: "'chips' | 'summary'", default: "'chips'", description: 'Multi trigger display (mirrors Select).' },
  { name: 'seed', type: '{ id, name, email?, avatar? }[]', default: '—', description: 'Pre-known users so their chips/value render before/without an async page.' },
  { name: 'fetchOptions', type: '(args) => Promise<{ options, nextCursor }>', default: 'GET /users', description: 'Loader override (defaults to the /users endpoint). Used by the gallery/tests.' },
  { name: 'size', type: "'sm' | 'md' | 'lg'", default: "'md'", description: 'Trigger height + text scale.' },
  { name: 'disabled / readonly', type: 'boolean', default: 'false', description: 'Inert / non-editable trigger.' },
  { name: 'ariaInvalid / success / dirty', type: 'boolean', default: 'false', description: 'Standalone state lines (auto inside a FormField).' },
  { name: 'placeholder / ariaLabel', type: 'string', default: 'i18n', description: 'Trigger placeholder + accessible label.' },
];
</script>

<template>
  <StoryPage
    title="UserSelect"
    description="A global, reusable people picker built on Select. It loads from GET /users (cursor-paginated, q search) and renders each option / chip / single value with the user's Avatar (initials fallback when avatar is null) plus name and (in options) email. v-model is a user id, or string[] in multiple mode."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Inherits Select's full combobox ARIA + keyboard (see the Select page). Avatars are decorative; the name is the accessible text.</li>
        <li>Placeholder + group label resolve through <code>t('userSelect.*')</code>; pass <code>ariaLabel</code> for a precise label per usage.</li>
      </ul>
    </template>

    <StorySection title="Single" description="One assignee. The trigger shows the selected user's avatar + name; options show avatar + name + email.">
      <div class="max-w-sm">
        <UserSelect v-model="single" :fetch-options="mockFetchUsers" />
      </div>
    </StorySection>

    <StorySection title="Multiple (chips)" description="Several people as avatar chips with a width-driven +N overflow; remove a chip with its ✕.">
      <div class="max-w-md">
        <UserSelect v-model:values="multi" multiple :fetch-options="mockFetchUsers" />
      </div>
    </StorySection>

    <StorySection title="Multiple — pre-selected (seeded, off-page)" description="seed lets already-known users render as chips before (or without) an async page that contains them.">
      <div class="max-w-md">
        <UserSelect v-model:values="preselected" multiple :seed="seed" :fetch-options="mockFetchUsers" />
      </div>
    </StorySection>

    <StorySection title="Summary + sizes + disabled">
      <div class="grid max-w-md gap-next-4">
        <StoryCell label="summary"><div class="w-full"><UserSelect v-model:values="multi" multiple summary :fetch-options="mockFetchUsers" /></div></StoryCell>
        <StoryCell label="sm"><div class="w-full"><UserSelect v-model="single" size="sm" :fetch-options="mockFetchUsers" /></div></StoryCell>
        <StoryCell label="disabled"><div class="w-full"><UserSelect v-model="single" disabled :fetch-options="mockFetchUsers" /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection title="Inside a FormField" description="Inherits id / aria-describedby / required and draws the error/success/dirty state line automatically.">
      <FormField label="Assignee" required :error="!formVal ? 'Choose a person.' : undefined">
        <UserSelect v-model="formVal" :fetch-options="mockFetchUsers" />
      </FormField>
    </StorySection>

    <StorySection title="API">
      <ApiTable title="Props" :rows="propRows" show-default />
    </StorySection>
  </StoryPage>
</template>
