<script setup lang="ts">
import Avatar from '../../ui/primitives/Avatar.vue';
import AvatarGroup from '../../ui/primitives/AvatarGroup.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const sizes = ['xs', 'sm', 'md', 'lg', 'xl'] as const;

// A deterministic, dependency-free avatar image (inline SVG data URI) so the
// gallery works offline and the demo never hits the network.
const photo =
  'data:image/svg+xml;utf8,' +
  encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="%23b83280"/><stop offset="1" stop-color="%23553c9a"/></linearGradient></defs><rect width="96" height="96" fill="url(%23g)"/><circle cx="48" cy="38" r="18" fill="white" opacity="0.9"/><rect x="20" y="60" width="56" height="40" rx="28" fill="white" opacity="0.9"/></svg>',
  );

const propRows: ApiRow[] = [
  { name: 'src', type: 'string', default: '—', description: 'Image source. Falls back to initials then a user icon on error.' },
  { name: 'name', type: 'string', default: '—', description: 'Full name — drives initials, alt text, and aria-label.' },
  { name: 'alt', type: 'string', default: 'name', description: 'Explicit alt text for the image.' },
  { name: 'size', type: "'xs' | 'sm' | 'md' | 'lg' | 'xl'", default: "'md'", description: 'Avatar diameter + text scale.' },
  { name: 'status', type: "'online' | 'away' | 'busy' | 'offline'", default: '—', description: 'Presence dot — color + shape, word added to aria-label.' },
];

const groupPropRows: ApiRow[] = [
  { name: 'max', type: 'number', default: '—', description: 'Visual cap (consumer slices the slot to this many).' },
  { name: 'total', type: 'number', default: '—', description: 'Full count represented, used for the +N chip.' },
  { name: 'shown', type: 'number', default: '—', description: 'How many avatars are placed in the slot.' },
  { name: 'size', type: "'xs' | 'sm' | 'md' | 'lg' | 'xl'", default: "'md'", description: 'Size of the +N overflow chip (match the avatars).' },
  { name: 'label', type: 'string', default: "'People'", description: 'Accessible group label.' },
];
</script>

<template>
  <StoryPage
    title="Avatar"
    description="User representation with image → initials → icon fallback, five sizes, a presence dot (color + shape + aria), and a stacked AvatarGroup with +N overflow."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Image avatars carry <code>alt</code> (defaults to <code>name</code>). Initials/icon fallback uses <code>role="img"</code> + <code>aria-label</code> with the full name.</li>
        <li>Status is conveyed by color <em>and</em> shape (online = filled, away = ringed, busy = filled red, offline = hollow) and the status word is appended to the <code>aria-label</code> — never color alone.</li>
        <li>AvatarGroup is a labelled <code>role="group"</code>; the +N chip’s <code>aria-label</code> states how many more people are hidden.</li>
      </ul>
    </template>

    <StorySection title="Sizes" description="xs → xl. Image avatar.">
      <StoryGrid align="center">
        <StoryCell v-for="s in sizes" :key="s" :label="s">
          <Avatar :src="photo" name="Ada Lovelace" :size="s" />
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Fallback modes" description="Image, initials (no src), and icon (no src, no name).">
      <StoryGrid align="center">
        <StoryCell label="image"><Avatar :src="photo" name="Ada Lovelace" size="lg" /></StoryCell>
        <StoryCell label="initials"><Avatar name="Grace Hopper" size="lg" /></StoryCell>
        <StoryCell label="single name"><Avatar name="Taskio" size="lg" /></StoryCell>
        <StoryCell label="icon"><Avatar size="lg" /></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Image error → initials" description="A broken image URL falls back to initials automatically.">
      <StoryGrid align="center">
        <StoryCell label="broken src + name"><Avatar src="/does-not-exist.png" name="Linus Pauling" size="lg" /></StoryCell>
        <StoryCell label="broken src, no name"><Avatar src="/does-not-exist.png" size="lg" /></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Status" description="Presence dot: distinct color + shape per status.">
      <StoryGrid align="center">
        <StoryCell label="online"><Avatar name="Ada Lovelace" :src="photo" size="lg" status="online" /></StoryCell>
        <StoryCell label="away"><Avatar name="Grace Hopper" size="lg" status="away" /></StoryCell>
        <StoryCell label="busy"><Avatar name="Alan Turing" size="lg" status="busy" /></StoryCell>
        <StoryCell label="offline"><Avatar name="Edsger Dijkstra" size="lg" status="offline" /></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="AvatarGroup" description="Stacked avatars with a +N overflow chip.">
      <StoryGrid align="center">
        <StoryCell label="3 of 3">
          <AvatarGroup :total="3" :shown="3" label="Reviewers">
            <Avatar :src="photo" name="Ada Lovelace" />
            <Avatar name="Grace Hopper" />
            <Avatar name="Alan Turing" />
          </AvatarGroup>
        </StoryCell>
        <StoryCell label="3 of 8 (+5)">
          <AvatarGroup :total="8" :shown="3" label="Project members">
            <Avatar :src="photo" name="Ada Lovelace" />
            <Avatar name="Grace Hopper" />
            <Avatar name="Alan Turing" />
          </AvatarGroup>
        </StoryCell>
        <StoryCell label="small, 4 of 12 (+8)">
          <AvatarGroup :total="12" :shown="4" size="sm" label="Followers">
            <Avatar :src="photo" name="Ada Lovelace" size="sm" />
            <Avatar name="Grace Hopper" size="sm" />
            <Avatar name="Alan Turing" size="sm" />
            <Avatar name="Edsger Dijkstra" size="sm" />
          </AvatarGroup>
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Realistic usage" description="A form assignee row.">
      <div class="flex items-center justify-between rounded-next-lg border border-next-border bg-next-bg p-next-3">
        <div class="flex items-center gap-next-3">
          <Avatar :src="photo" name="Ada Lovelace" status="online" />
          <div>
            <p class="text-next-sm font-next-medium">Ada Lovelace</p>
            <p class="text-next-xs text-next-muted-foreground">Owner · online</p>
          </div>
        </div>
        <AvatarGroup :total="5" :shown="2" size="sm" label="Collaborators">
          <Avatar name="Grace Hopper" size="sm" />
          <Avatar name="Alan Turing" size="sm" />
        </AvatarGroup>
      </div>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Avatar props" :rows="propRows" show-default />
        <ApiTable title="AvatarGroup props" :rows="groupPropRows" show-default />
      </div>
    </StorySection>
  </StoryPage>
</template>
