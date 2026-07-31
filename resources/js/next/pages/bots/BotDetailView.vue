<script setup lang="ts">
// BotDetailView — the read-only detail page for one bot (`/bots/:id/<section>`;
// one component shared by the inbox / activity / config child routes).
//
// A sub-view of BotsModuleLayout. Reads the bot from the store's detail cache when
// the user arrived via a card prefetch; on a DEEP LINK (no cache) it fetches by id
// and shows skeletons / an error state. The PageHeader h1 names the active
// SECTION's purpose (uniform header scale app-wide); the bot's identity (icon +
// name + status) lives in the module aside's selected block. Renders the bot's
// MODULE PREVIEWS read-only:
//   • Text module — persona + style + dictionary / phrases / prohibitions chips,
//   • Task-execution — enabled flag, knowledge source, tools (or a "not configured"
//     note),
//   • Visual / Voice — visibly DISABLED "coming soon" placeholder cards,
//   • Tasks & activity — a clean EmptyState noting it arrives with execution
//     (Batch 2); it never fakes data.
// An Edit action opens the `?bot=<id>` editor drawer (gated on can_be_edited).
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Surface from '../../ui/layout/Surface.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon, { type IconName } from '../../ui/primitives/Icon.vue';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import BotActionTimeline from './BotActionTimeline.vue';
import BotInbox from './BotInbox.vue';
import BotVisualImage from './BotVisualImage.vue';
import { toolIcon, toolLabel, isKnownTool } from './botToolMeta';
import { useBotsStore } from '../../app/stores/bots';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const store = useBotsStore();
const toast = useToast();

const botId = computed(() => String(route.params.id));

// The active detail section, derived from the child ROUTE NAME
// (`next.bots.detail.<section>` — one shared component across the siblings; the
// module sidebar links the sections).
const section = computed(() => {
  const name = String(route.name ?? '');
  const prefix = 'next.bots.detail.';
  return name.startsWith(prefix) ? name.slice(prefix.length) : 'inbox';
});

// PageHeader content per section: the h1 names the PAGE's purpose (the bot's
// identity lives in the module aside's selected block).
const SECTION_META: Record<string, { icon: IconName; titleKey: string; descriptionKey: string }> = {
  inbox: { icon: 'inbox', titleKey: 'bots.detail.tabInbox', descriptionKey: 'bots.detail.sectionDescriptions.inbox' },
  activity: { icon: 'clock', titleKey: 'bots.detail.tabActivity', descriptionKey: 'bots.detail.sectionDescriptions.activity' },
  config: { icon: 'settings', titleKey: 'bots.detail.tabConfig', descriptionKey: 'bots.detail.sectionDescriptions.config' },
};
const sectionMeta = computed(() => SECTION_META[section.value] ?? SECTION_META.inbox);

// The cached detail (when it matches the route id), else null until fetched.
// Inference flows from `store.detail` (Pinia widens the `null`-literal visual/
// voice props when re-annotated, so we let the getter return type infer).
const bot = computed(() =>
  store.detail && store.detail.id === botId.value ? store.detail : null,
);

const loading = ref(false);
const loadError = ref(false);

async function load(): Promise<void> {
  // Already prefetched into the cache by the list card → no fetch flash.
  if (bot.value) return;
  loading.value = true;
  loadError.value = false;
  const result = await store.fetchBot(botId.value);
  loading.value = false;
  if (!result) loadError.value = true;
}

onMounted(load);
// Re-load if the route id changes (in-place navigation between bots).
watch(botId, () => {
  loadError.value = false;
  void load();
});

// --- Derived module view-state --------------------------------------------
const hasStyle = computed(() => !!bot.value?.style?.trim());
const dictionary = computed(() => bot.value?.dictionary ?? []);
const phrases = computed(() => bot.value?.phrases ?? []);
const prohibitions = computed(() => bot.value?.prohibitions ?? []);
const taskExecution = computed(() => bot.value?.task_execution ?? null);
const taskExecutionActive = computed(() => taskExecution.value?.enabled === true);
const tools = computed(() => taskExecution.value?.tools ?? []);
// Knowledge module (Batch 6): the enable flag + the repeatable {title, content}
// entries. Mirrors BotResource's `knowledge: { enabled, entries }` object.
const knowledgeEnabled = computed(() => bot.value?.knowledge?.enabled === true);
const knowledge = computed(() => bot.value?.knowledge?.entries ?? []);
// Visual module ("Wygląd"): null when it was never configured (a bot older than the module) — that is a
// different statement from "configured but empty", so the card says so in one line rather than showing
// four blank fields.
const visual = computed(() => bot.value?.visual ?? null);
const visualEnabled = computed(() => visual.value?.enabled === true);
const visualCanonicalId = computed(() => visual.value?.canonical_file_id ?? null);
const visualProhibitions = computed(() => visual.value?.prohibitions ?? []);

const canEdit = computed(() => bot.value?.can_be_edited === true);

// --- Status action (Activate / Deactivate) --------------------------------
// A bot's live status is toggled via the dedicated endpoint (never the form).
// Gated on `can_be_edited` (creator-only server-side). Optimistic-free: the store
// PATCHes + reconciles; we toast on success, and on 403/422 (danger toast).
const isActive = computed(() => bot.value?.status === 'active');
const togglingStatus = ref(false);
async function onToggleStatus(): Promise<void> {
  if (!bot.value || togglingStatus.value) return;
  const next = isActive.value ? 'inactive' : 'active';
  togglingStatus.value = true;
  try {
    await store.setStatus(bot.value.id, next);
    toast.success(
      next === 'active'
        ? t('bots.statusAction.activated')
        : t('bots.statusAction.deactivated'),
    );
  } catch {
    toast.danger(t('bots.statusAction.error'));
  } finally {
    togglingStatus.value = false;
  }
}

function onEdit(): void {
  if (bot.value) void router.push({ query: { ...route.query, bot: bot.value.id } });
}
function onBack(): void {
  void router.push({ name: 'next.bots' });
}
</script>

<template>
  <div class="flex flex-col gap-next-6">
    <!-- Deep-link / fetch error → a clear error state with retry + back. -->
    <EmptyState
      v-if="loadError && !bot"
      variant="error"
      :title="t('bots.detail.errorTitle')"
      :description="t('bots.detail.errorDescription')"
    >
      <template #action>
        <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="load">
          {{ t('bots.errors.retry') }}
        </Button>
      </template>
      <template #secondary>
        <Button variant="ghost" size="sm" leading-icon="arrow-left" @click="onBack">
          {{ t('bots.detail.back') }}
        </Button>
      </template>
    </EmptyState>

    <!-- Loading (deep-link without a prefetch): geometry-mimicking skeletons. -->
    <div v-else-if="loading && !bot" class="flex flex-col gap-next-6">
      <div class="flex items-center gap-next-3">
        <Skeleton variant="circle" diameter="2.75rem" />
        <div class="flex flex-1 flex-col gap-next-2">
          <Skeleton variant="text" width="30%" />
          <Skeleton variant="text" width="50%" />
        </div>
      </div>
      <Skeleton variant="rect" height="9rem" />
      <Skeleton variant="rect" height="6rem" />
    </div>

    <template v-else-if="bot">
      <!-- Page header: the h1 names the SECTION's purpose (uniform size across
           the app); the bot's identity lives in the module aside's selected
           block. Back-navigation lives in the aside/breadcrumb, so the actions
           carry only the bot's own operations. -->
      <PageHeader
        :title="t(sectionMeta.titleKey)"
        :icon="sectionMeta.icon"
        :description="t(sectionMeta.descriptionKey)"
      >
        <template #actions>
          <Button
            v-if="canEdit"
            :variant="isActive ? 'outline' : 'primary'"
            :leading-icon="isActive ? 'circle' : 'check-circle'"
            :loading="togglingStatus"
            :disabled="togglingStatus"
            @click="onToggleStatus"
          >
            {{ isActive ? t('bots.statusAction.deactivate') : t('bots.statusAction.activate') }}
          </Button>
          <Button v-if="canEdit" leading-icon="pencil" @click="onEdit">
            {{ t('bots.actions.edit') }}
          </Button>
        </template>
      </PageHeader>

      <!-- The active SECTION (the child route name; linked from the module sidebar). -->
      <BotInbox v-if="section === 'inbox'" :bot-id="bot.id" />
      <BotActionTimeline v-else-if="section === 'activity'" :bot-id="bot.id" />
      <template v-else>
        <div class="flex flex-col gap-next-6">

            <!-- TEXT MODULE (the mandatory persona). -->
      <Surface bg="card" border elevation="sm" radius="lg" class="flex flex-col gap-next-4 p-next-6">
        <header class="flex items-center gap-next-2">
          <span
            class="flex h-8 w-8 items-center justify-center rounded-next-md bg-next-primary-subtle text-next-primary-subtle-foreground"
            aria-hidden="true"
          >
            <Icon name="file-text" />
          </span>
          <h2 class="text-next-base font-next-semibold text-next-fg">{{ t('bots.modules.text') }}</h2>
        </header>

        <div class="flex flex-col gap-next-1">
          <h3 class="text-next-xs font-next-medium uppercase tracking-next-wide text-next-muted-foreground">
            {{ t('bots.detail.persona') }}
          </h3>
          <p class="whitespace-pre-wrap text-next-sm text-next-fg">{{ bot.persona }}</p>
        </div>

        <div v-if="hasStyle" class="flex flex-col gap-next-1">
          <h3 class="text-next-xs font-next-medium uppercase tracking-next-wide text-next-muted-foreground">
            {{ t('bots.detail.style') }}
          </h3>
          <p class="whitespace-pre-wrap text-next-sm text-next-fg">{{ bot.style }}</p>
        </div>

        <!-- Dictionary — term → meaning pairs (not raw chips). -->
        <div class="flex flex-col gap-next-2">
          <h3 class="text-next-xs font-next-medium uppercase tracking-next-wide text-next-muted-foreground">
            {{ t('bots.detail.dictionary') }}
          </h3>
          <ul v-if="dictionary.length" class="flex flex-col gap-next-1">
            <li v-for="(entry, i) in dictionary" :key="i" class="flex flex-wrap items-baseline gap-next-2 text-next-sm">
              <span class="font-next-semibold text-next-fg">{{ entry.term }}</span>
              <span class="text-next-muted-foreground">—</span>
              <span class="min-w-0 text-next-muted-foreground">{{ entry.meaning }}</span>
            </li>
          </ul>
          <p v-else class="text-next-xs text-next-muted-foreground">{{ t('bots.detail.emptyList') }}</p>
        </div>

        <!-- Phrases — phrase (+ optional context). -->
        <div class="flex flex-col gap-next-2">
          <h3 class="text-next-xs font-next-medium uppercase tracking-next-wide text-next-muted-foreground">
            {{ t('bots.detail.phrases') }}
          </h3>
          <ul v-if="phrases.length" class="flex flex-col gap-next-1">
            <li v-for="(entry, i) in phrases" :key="i" class="flex flex-wrap items-baseline gap-next-2 text-next-sm">
              <span class="font-next-medium text-next-fg">“{{ entry.phrase }}”</span>
              <span v-if="entry.context" class="min-w-0 text-next-muted-foreground">
                {{ t('bots.detail.phraseContext', '', { context: entry.context }) }}
              </span>
            </li>
          </ul>
          <p v-else class="text-next-xs text-next-muted-foreground">{{ t('bots.detail.emptyList') }}</p>
        </div>

        <!-- Prohibitions — a plain topic list (chips). -->
        <div class="flex flex-col gap-next-2">
          <h3 class="text-next-xs font-next-medium uppercase tracking-next-wide text-next-muted-foreground">
            {{ t('bots.detail.prohibitions') }}
          </h3>
          <div v-if="prohibitions.length" class="flex flex-wrap gap-next-1">
            <Badge v-for="word in prohibitions" :key="word" variant="danger" tone="subtle" size="sm">
              {{ word }}
            </Badge>
          </div>
          <p v-else class="text-next-xs text-next-muted-foreground">{{ t('bots.detail.emptyList') }}</p>
        </div>
      </Surface>

      <!-- VISUAL MODULE ("Wygląd") — read-only: the approved likeness beside the written identity.
           Placed right after Text because both describe WHO the bot is; execution comes after. -->
      <Surface bg="card" border elevation="sm" radius="lg" class="flex flex-col gap-next-4 p-next-6">
        <header class="flex items-center justify-between gap-next-3">
          <div class="flex items-center gap-next-2">
            <span
              class="flex h-8 w-8 items-center justify-center rounded-next-md bg-next-accent text-next-accent-foreground"
              aria-hidden="true"
            >
              <Icon name="palette" />
            </span>
            <h2 class="text-next-base font-next-semibold text-next-fg">{{ t('bots.modules.visual') }}</h2>
          </div>
          <Badge
            v-if="visual"
            :variant="visualEnabled ? 'primary' : 'neutral'"
            tone="subtle"
            size="sm"
            :icon="visualEnabled ? 'check-circle' : 'circle'"
          >
            {{ visualEnabled ? t('bots.detail.enabled') : t('bots.detail.disabled') }}
          </Badge>
        </header>

        <p v-if="!visual" class="text-next-sm text-next-muted-foreground">
          {{ t('bots.detail.visualNotConfigured') }}
        </p>

        <template v-else>
          <div class="flex flex-col gap-next-4 next-sm:flex-row">
            <!-- The approved likeness — the one thing a reader wants to see first. -->
            <div class="w-full shrink-0 next-sm:w-40">
              <div
                v-if="visualCanonicalId"
                class="aspect-square overflow-hidden rounded-next-md border border-next-border bg-next-muted/40"
              >
                <BotVisualImage
                  :file-id="visualCanonicalId"
                  :alt="t('bots.detail.visualImageAlt', '', { name: bot.name })"
                  fit="cover"
                />
              </div>
              <p v-else class="text-next-xs text-next-muted-foreground">{{ t('bots.detail.visualNoImage') }}</p>
            </div>

            <div class="flex min-w-0 flex-1 flex-col gap-next-3">
              <div class="flex flex-col gap-next-1">
                <h3 class="text-next-xs font-next-medium uppercase tracking-next-wide text-next-muted-foreground">
                  {{ t('bots.detail.visualDescriptor') }}
                </h3>
                <p class="whitespace-pre-wrap text-next-sm text-next-fg">
                  {{ visual.descriptor || t('bots.detail.emptyList') }}
                </p>
              </div>
              <div class="flex flex-col gap-next-1">
                <h3 class="text-next-xs font-next-medium uppercase tracking-next-wide text-next-muted-foreground">
                  {{ t('bots.detail.visualWardrobe') }}
                </h3>
                <p class="whitespace-pre-wrap text-next-sm text-next-fg">
                  {{ visual.wardrobe || t('bots.detail.emptyList') }}
                </p>
              </div>
              <div class="flex flex-col gap-next-1">
                <h3 class="text-next-xs font-next-medium uppercase tracking-next-wide text-next-muted-foreground">
                  {{ t('bots.detail.visualAesthetic') }}
                </h3>
                <p class="whitespace-pre-wrap text-next-sm text-next-fg">
                  {{ visual.aesthetic || t('bots.detail.emptyList') }}
                </p>
              </div>
              <div class="flex flex-col gap-next-2">
                <h3 class="text-next-xs font-next-medium uppercase tracking-next-wide text-next-muted-foreground">
                  {{ t('bots.detail.visualProhibitions') }}
                </h3>
                <div v-if="visualProhibitions.length" class="flex flex-wrap gap-next-1">
                  <Badge v-for="word in visualProhibitions" :key="word" variant="danger" tone="subtle" size="sm">
                    {{ word }}
                  </Badge>
                </div>
                <p v-else class="text-next-xs text-next-muted-foreground">{{ t('bots.detail.emptyList') }}</p>
              </div>
            </div>
          </div>
        </template>
      </Surface>

      <!-- TASK-EXECUTION MODULE. -->
      <Surface bg="card" border elevation="sm" radius="lg" class="flex flex-col gap-next-4 p-next-6">
        <header class="flex items-center justify-between gap-next-3">
          <div class="flex items-center gap-next-2">
            <span
              class="flex h-8 w-8 items-center justify-center rounded-next-md bg-next-success-subtle text-next-success-subtle-foreground"
              aria-hidden="true"
            >
              <Icon name="list-checks" />
            </span>
            <h2 class="text-next-base font-next-semibold text-next-fg">{{ t('bots.modules.taskExecution') }}</h2>
          </div>
          <Badge
            :variant="taskExecutionActive ? 'success' : 'neutral'"
            tone="subtle"
            size="sm"
            :icon="taskExecutionActive ? 'check-circle' : 'circle'"
          >
            {{ taskExecutionActive ? t('bots.detail.enabled') : t('bots.detail.disabled') }}
          </Badge>
        </header>

        <template v-if="taskExecution">
          <div class="flex flex-col gap-next-2">
            <h3 class="text-next-xs font-next-medium uppercase tracking-next-wide text-next-muted-foreground">
              {{ t('bots.detail.tools') }}
            </h3>
            <div v-if="tools.length" class="flex flex-wrap gap-next-1">
              <!-- Known tool → localized label + icon (info); unknown id → raw id, muted. -->
              <Badge
                v-for="tool in tools"
                :key="tool"
                :variant="isKnownTool(tool) ? 'info' : 'neutral'"
                tone="subtle"
                size="sm"
                :icon="toolIcon(tool)"
                :title="isKnownTool(tool) ? undefined : t('bots.tools.unavailableTitle')"
              >
                {{ toolLabel(tool, t) }}
              </Badge>
            </div>
            <p v-else class="text-next-xs text-next-muted-foreground">{{ t('bots.detail.noTools') }}</p>
          </div>
        </template>
        <p v-else class="text-next-sm text-next-muted-foreground">{{ t('bots.detail.taskExecutionNotConfigured') }}</p>
      </Surface>

      <!-- KNOWLEDGE MODULE — read-only preview: enable badge + the {title, content}
           entries. -->
      <Surface bg="card" border elevation="sm" radius="lg" class="flex flex-col gap-next-4 p-next-6">
        <header class="flex items-center justify-between gap-next-3">
          <div class="flex items-center gap-next-2">
            <span
              class="flex h-8 w-8 items-center justify-center rounded-next-md bg-next-info-subtle text-next-info-subtle-foreground"
              aria-hidden="true"
            >
              <Icon name="bookmark" />
            </span>
            <h2 class="text-next-base font-next-semibold text-next-fg">{{ t('bots.modules.knowledge') }}</h2>
          </div>
          <Badge
            :variant="knowledgeEnabled ? 'info' : 'neutral'"
            tone="subtle"
            size="sm"
            :icon="knowledgeEnabled ? 'check-circle' : 'circle'"
          >
            {{ knowledgeEnabled ? t('bots.detail.enabled') : t('bots.detail.disabled') }}
          </Badge>
        </header>

        <ul v-if="knowledge.length" class="flex flex-col gap-next-3">
          <li
            v-for="(entry, i) in knowledge"
            :key="i"
            class="flex flex-col gap-next-1 rounded-next-md border border-next-border bg-next-muted/30 p-next-3"
          >
            <span class="text-next-sm font-next-semibold text-next-fg">{{ entry.title }}</span>
            <span class="whitespace-pre-wrap text-next-sm text-next-muted-foreground">{{ entry.content }}</span>
          </li>
        </ul>
        <p v-else class="text-next-sm text-next-muted-foreground">{{ t('bots.detail.noKnowledge') }}</p>
      </Surface>

      <!-- AUDIO — the last not-yet-built module. FULL WIDTH on purpose: a lone tile in a two-column
           grid reads as a rendering bug, not as "one module is still coming". -->
      <Surface
        bg="muted"
        border
        radius="lg"
        class="flex flex-col gap-next-2 p-next-6 opacity-70"
        aria-disabled="true"
      >
        <header class="flex items-center justify-between gap-next-2">
          <div class="flex items-center gap-next-2">
            <Icon name="bell" class="text-next-muted-foreground" aria-hidden="true" />
            <h2 class="text-next-base font-next-semibold text-next-fg">{{ t('bots.modules.audio') }}</h2>
          </div>
          <Badge variant="neutral" tone="subtle" size="sm">{{ t('bots.detail.comingSoon') }}</Badge>
        </header>
        <p class="text-next-sm text-next-muted-foreground">{{ t('bots.detail.audioPlaceholder') }}</p>
      </Surface>
        </div>
      </template>
    </template>
  </div>
</template>
