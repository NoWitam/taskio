<script setup lang="ts">
// PublicationEditorDrawer — the composer (§6). One component, two entrances: `?new=1` from
// the list and `?edit=<id>` from either the list or the detail.
//
// ═════════════════════════════════════════════════════════════════════════════════════════
// A `PUT` IS A WHOLE-ROW WRITE. A FIELD THIS FORM DOES NOT SEND IS NULLED IN THE DATABASE.
// ═════════════════════════════════════════════════════════════════════════════════════════
// `PublicationService::attributesFrom()` assigns title, body, platform,
// platform_connection_id, scheduled_at, media AND options on every update. Three real
// consequences, in rising order of how quietly they happen:
//
//   • a PUT without `media`   → the publication loses every attachment;
//   • a PUT without `options` → `{"privacy":"unlisted"}` disappears, and this form never
//     even shows that field (D13 — it belongs to an adapter that does not exist yet);
//   • a PUT without `scheduled_at` on a SCHEDULED row → the moment becomes null while the
//     status stays `scheduled`, and the sweep (`scheduled_at <= now()`) never selects it
//     again. Armed forever, going nowhere, with no message anywhere. That is the quietest
//     defect this module can produce.
//
// So the drawer holds the WHOLE object it fetched and sends the complete payload every time,
// `options` included. `buildPayload()` is exported for the unit test that pins exactly this.
//
// The body is a `Textarea`, not the markdown editor (D11): YouTube, Instagram and Facebook
// render no markdown, so a bold button would promise emphasis that becomes literal asterisks
// in a public caption.
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import Button from '../../ui/primitives/Button.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Badge from '../../ui/primitives/Badge.vue';
import FormField from '../../ui/forms/FormField.vue';
import FieldShell from '../../ui/forms/FieldShell.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Textarea from '../../ui/forms/Textarea.vue';
import Select, { type SelectOption } from '../../ui/forms/Select.vue';
import DateTimePicker from '../../ui/forms/DateTimePicker.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import Tooltip from '../../ui/overlay/Tooltip.vue';
import PublicationMediaField from './PublicationMediaField.vue';
import SchedulePublicationModal from './SchedulePublicationModal.vue';
import { usePublishingStore } from '../../app/stores/publishing';
import { usePublishingConnectionsStore } from '../../app/stores/publishingConnections';
import { useToast } from '../../app/composables/useToast';
import { useConfirm } from '../../app/composables/useConfirm';
import { useI18n } from '../../app/i18n';
import { ALL_PLATFORMS, platformIcon } from './publishingMeta';
import { armAffordance, hasPendingReview } from './publicationActions';
import {
  fieldErrorsOf,
  isUnderReview,
  prohibitedFieldsIn,
  serverMessageOf,
  statusOf,
  transitionRefusalOf,
} from './publishingErrors';
import { instantToLocalInput, zoneMismatch } from './publishingTime';
import { buildPublicationPayload } from './publicationPayload';
import type { Publication, PublishingPlatform } from './types';

const TITLE_MAX = 255;
const BODY_MAX = 5000;

const props = defineProps<{ publicationId: string | null }>();
const emit = defineEmits<{ (e: 'close'): void; (e: 'saved', publication: Publication): void }>();

const { t } = useI18n();
const router = useRouter();
const store = usePublishingStore();
const connections = usePublishingConnectionsStore();
const toast = useToast();
const confirm = useConfirm();

const isEdit = computed(() => props.publicationId !== null);

// --- Form state -------------------------------------------------------------
const loading = ref(false);
const loadError = ref<string | null>(null);
const saving = ref(false);
const fieldErrors = ref<Record<string, string>>({});
/** A 403 on save: somebody armed or reviewed the row while this form was open. */
const staleConflict = ref(false);
/** The server's own sentence for that, when it sent one (the `publication_under_review` 422). */
const staleMessage = ref<string | null>(null);

const title = ref('');
const body = ref('');
const platform = ref<PublishingPlatform>('dry_run');
const connectionId = ref<string | null>(null);
const moment = ref<string | null>(null);
const media = ref<string[]>([]);
/**
 * NOT RENDERED AND NOT EDITABLE — and carried verbatim from the GET to every PUT. This is
 * the whole of D13: the field is schema-less and belongs to an adapter that does not exist
 * yet, so a generic JSON box would be a surface where anything can be typed and nothing can
 * be checked. Dropping it instead would make editing a caption erase a privacy setting.
 */
const options = ref<Record<string, unknown>>({});

/** The record as fetched — the baseline for "is this dirty" and the source of `options`. */
const original = ref<Publication | null>(null);
const platformChangedOnce = ref(false);
const scheduleAfterSave = ref<Publication | null>(null);

const dirty = computed(() => {
  const o = original.value;
  if (!o) return title.value !== '' || body.value !== '' || media.value.length > 0;
  return (
    title.value !== o.title ||
    body.value !== (o.body ?? '') ||
    platform.value !== o.platform ||
    connectionId.value !== o.platform_connection_id ||
    JSON.stringify(media.value) !== JSON.stringify(o.media) ||
    moment.value !== instantToLocalInput(o.scheduled_at, store.timezone)
  );
});

// --- Destination + account --------------------------------------------------
function platformLabel(value: string): string {
  const fromData =
    original.value?.platform === value
      ? original.value.platform_label
      : store.items.find((p) => p.platform === value)?.platform_label;
  return fromData ?? t(`publishing.platforms.${value}`);
}

const platformOptions = computed<SelectOption[]>(() =>
  ALL_PLATFORMS.map((value) => ({
    value,
    label: platformLabel(value),
    icon: platformIcon(value),
  })),
);

/**
 * Whether the chosen destination goes out into the world — THE FOURTH DEPARTURE FROM D5.
 *
 * `publishes_publicly` is the server's word and is read from the row WHENEVER THERE IS ONE
 * to read: for the destination the record was fetched with, the server's own answer wins, so
 * the day `dry_run` stops being the only rehearsal (or a new destination arrives that does
 * not publish publicly) this form follows without a change.
 *
 * The derivation survives only where no server word exists AND CANNOT: a new publication has
 * no row, and picking a different destination in this select names one the row cannot answer
 * for — the contract has no destination catalogue endpoint (gap L1), so there is nothing else
 * to ask. That is the whole of the departure, and it is narrow on purpose; the previous
 * comment claimed the opposite of the line beneath it, which is worse than no comment.
 */
const publishesPublicly = computed(() => {
  const record = original.value;
  if (record && platform.value === record.platform) return record.publishes_publicly;
  return platform.value !== 'dry_run';
});

/** Only accounts the server itself says may publish (`can_publish`), for this destination. */
const accountOptions = computed<SelectOption[]>(() =>
  connections.publishableFor(platform.value).map((connection) => ({
    value: connection.id,
    label: connection.account_name,
    description: connection.external_account_id,
  })),
);

const noAccountsForPlatform = computed(
  () => publishesPublicly.value && accountOptions.value.length === 0,
);

// Changing the destination invalidates the account — and SAYS SO the first time, because a
// field that empties itself without a word reads as a bug.
//
// The `seeding` guard is load-bearing, not defensive. `seed()` assigns the destination and
// the account in the same tick; this watcher runs POST-FLUSH, so without the guard loading a
// publication for editing would fire as if the reader had just changed the destination —
// clearing the very account the record came with, and announcing it.
watch(platform, (next, previous) => {
  if (seeding || previous === undefined || next === previous) return;
  if (connectionId.value !== null) platformChangedOnce.value = true;
  connectionId.value = null;
});

// --- The clock --------------------------------------------------------------
const zone = computed(() => zoneMismatch(store.timezone));
const momentHint = computed(() =>
  store.timezone
    ? t('publishing.editor.momentZone', '', { tz: store.timezone })
    // The workspace inherits the application clock, which this client does not know. It says
    // so rather than naming the BROWSER's zone, which would be a confident wrong answer.
    : t('publishing.editor.momentZoneUnknown'),
);

// --- Arming affordance ------------------------------------------------------
/**
 * Whether "Save and schedule" would actually arm anything. Uses the SAVED record, because
 * the flags and the pipeline belong to the row rather than to the draft in this form — and
 * for a brand-new publication there is no row yet, so the two closures that can be answered
 * without one (a public destination with no account) are answered here.
 */
const armBlockedReason = computed<string | null>(() => {
  if (publishesPublicly.value && connectionId.value === null) {
    return t('publishing.blocked.noAccount');
  }
  const record = original.value;
  if (!record) return null;
  const affordance = armAffordance(record, connections.connections);
  if (affordance.blockedBy === 'connectionBroken') return t('publishing.blocked.connectionBroken');
  if (affordance.blockedBy === 'underReview') return t('publishing.blocked.underReview');
  return null;
});

/**
 * The media message, whichever ENTRY the server named.
 *
 * Laravel validates `media.*` and reports per index: the fourth file's refusal arrives as
 * `media.3`, and a reader that only knew `media` and `media.0` dropped it on the floor — the
 * save failed, the drawer stayed open, and nothing on screen said why. The whole-field
 * message wins when there is one; otherwise the first indexed entry, in the server's own
 * words. (The tile that file belongs to is named inside the sentence, which is as specific as
 * this form can be without a second mapping of index → tile.)
 */
const mediaError = computed<string | null>(() => {
  const errors = fieldErrors.value;
  if (errors.media) return errors.media;
  // The server's own order, not a sort: `media.10` sorts before `media.2` as text, and the
  // first sentence a person reads should be the first one the server chose to say.
  const indexed = Object.keys(errors).find((field) => field.startsWith('media.'));
  return indexed ? errors[indexed] : null;
});

/** A pipeline turns "Save and schedule" into "Save and send for approval". */
const armLabel = computed(() =>
  original.value && hasPendingReview(original.value)
    ? t('publishing.editor.saveAndSubmit')
    : t('publishing.editor.saveAndSchedule'),
);

// --- Load -------------------------------------------------------------------
async function load(): Promise<void> {
  if (!props.publicationId) {
    original.value = null;
    return;
  }
  loading.value = true;
  loadError.value = null;
  try {
    const record = await store.fetchPublication(props.publicationId);
    if (record) seed(record);
  } catch (err) {
    loadError.value = serverMessageOf(err) ?? t('publishing.errors.loadDescription');
  } finally {
    loading.value = false;
  }
}

/** True for the duration of a seed + the flush that follows it. See the watcher above. */
let seeding = false;

function seed(record: Publication): void {
  seeding = true;
  original.value = record;
  title.value = record.title;
  body.value = record.body ?? '';
  platform.value = record.platform;
  connectionId.value = record.platform_connection_id;
  media.value = [...record.media];
  options.value = { ...(record.options ?? {}) };
  moment.value = instantToLocalInput(record.scheduled_at, store.timezone);
  platformChangedOnce.value = false;
  // Released after the watchers for THIS assignment have run, not before.
  void nextTick(() => {
    seeding = false;
  });
}

// The moment is stored as an INSTANT and edited as a wall clock, so the zone has to be known
// before it can be shown. It arrives asynchronously; re-derive when it lands.
watch(
  () => store.timezone,
  () => {
    if (original.value) moment.value = instantToLocalInput(original.value.scheduled_at, store.timezone);
  },
);

onMounted(() => {
  void connections.fetchConnections().catch(() => undefined);
  void store.loadTimezone().catch(() => undefined);
  void load();
});

// --- Save -------------------------------------------------------------------
async function save(): Promise<Publication | null> {
  saving.value = true;
  fieldErrors.value = {};
  staleConflict.value = false;
  staleMessage.value = null;
  try {
    // THE COMPLETE ROW, built by a pure function so the "options must survive" rule is
    // asserted on the payload rather than inferred from a template. See the file docblock.
    const payload = buildPublicationPayload({
      title: title.value,
      body: body.value,
      platform: platform.value,
      connectionId: connectionId.value,
      moment: moment.value,
      media: media.value,
      options: options.value,
    });
    const saved = props.publicationId
      ? await store.updatePublication(props.publicationId, payload)
      : await store.createPublication(payload);
    seed(saved);
    return saved;
  } catch (err) {
    const status = statusOf(err);
    if (status === 403) {
      // The row moved out from under this form (armed, or sent for review).
      staleConflict.value = true;
      return null;
    }

    const refusal = transitionRefusalOf(err);
    if (refusal && isUnderReview(refusal)) {
      // A LIVE REVIEW took the row while this form was open — the same situation as the 403
      // above, arriving as a 422. It is a STATE, not an event: a toast that fades leaves a
      // form full of edits that can no longer be saved and no sign of why. It stays on
      // screen, in the server's own words, with the one action that helps.
      staleConflict.value = true;
      staleMessage.value = refusal.message || null;
      return null;
    }
    if (refusal) {
      // Server prose, verbatim — the sentence is already in the reader's language.
      toast.warning(refusal.message);
      return null;
    }

    const errors = fieldErrorsOf(err);
    const prohibited = prohibitedFieldsIn(errors);
    if (prohibited.length > 0) {
      // A FRONTEND DEFECT, not a user error: there is no field on this form to hang it on.
      // eslint-disable-next-line no-console
      console.error('[publishing] refused a prohibited field', prohibited, errors);
      toast.danger(serverMessageOf(err) ?? t('publishing.errors.clientFieldRefused'));
      return null;
    }

    fieldErrors.value = errors;
    if (Object.keys(errors).length === 0) {
      toast.danger(serverMessageOf(err) ?? t('publishing.toasts.actionError'));
    }
    return null;
  } finally {
    saving.value = false;
  }
}

async function onSaveDraft(): Promise<void> {
  const saved = await save();
  if (!saved) return;
  toast.success(t('publishing.toasts.draftSaved'));
  emit('saved', saved);
}

async function onSaveAndSchedule(): Promise<void> {
  const saved = await save();
  if (!saved) return;
  // Straight into the scheduling modal on the SAME record — the flow a person expects when
  // they meant "and now send it".
  scheduleAfterSave.value = saved;
}

function onScheduleDone(publication: Publication): void {
  scheduleAfterSave.value = null;
  emit('saved', publication);
}

async function onCancel(): Promise<void> {
  if (dirty.value) {
    const ok = await confirm({
      title: t('publishing.confirm.discardTitle'),
      message: t('publishing.confirm.discardBody'),
      // "Discard", not "Delete": nothing is being deleted here — the draft on the server is
      // untouched and only the unsaved edits go. A confirm button whose word does not match
      // its question is the one people read instead of the question.
      confirmLabel: t('publishing.confirm.discardConfirm'),
      cancelLabel: t('common.cancel'),
      variant: 'danger',
    });
    if (!ok) return;
  }
  emit('close');
}

/**
 * "I have no account" → connect one. The draft is SAVED FIRST when it can be, because
 * otherwise the road "no account → let me connect one → back" costs whatever was written.
 * When it cannot be saved yet (no title), Connections opens in a NEW TAB and this drawer
 * stays exactly where it is. Both behaviours are stated on the button itself.
 */
const canSaveBeforeLeaving = computed(() => title.value.trim() !== '');

async function onConnectAccount(): Promise<void> {
  if (canSaveBeforeLeaving.value) {
    const saved = await save();
    if (!saved) return;
    toast.success(t('publishing.toasts.draftSaved'));
    emit('saved', saved);
    void router.push({ name: 'next.publishing.connections' });
    return;
  }
  window.open('/next/publishing/connections', '_blank', 'noopener,noreferrer');
}
</script>

<template>
  <div class="flex h-full min-h-0 flex-col">
    <!-- Own header: the drawer renders no chrome for this editor. -->
    <header class="flex items-start gap-next-3 border-b border-next-border p-next-4">
      <div class="min-w-0 flex-1">
        <h2 class="text-next-base font-next-semibold text-next-fg">
          {{ isEdit ? t('publishing.editor.editTitle') : t('publishing.editor.createTitle') }}
        </h2>
        <!-- The single most common misunderstanding in this module, said before anything
             else: saving is not publishing. -->
        <p class="mt-next-0_5 text-next-xs text-next-muted-foreground">
          {{ t('publishing.editor.description') }}
        </p>
      </div>
      <Button
        variant="ghost"
        size="icon-sm"
        leading-icon="x"
        :aria-label="t('common.close')"
        @click="onCancel"
      />
    </header>

    <div class="min-h-0 flex-1 overflow-y-auto p-next-4">
      <div v-if="loading" class="flex flex-col gap-next-4">
        <Skeleton variant="rect" height="3rem" :label="t('common.loading')" />
        <Skeleton variant="rect" height="3rem" />
        <Skeleton variant="rect" height="8rem" />
      </div>

      <Alert v-else-if="loadError" variant="danger">
        {{ loadError }}
        <template #actions>
          <Button variant="ghost" size="sm" leading-icon="rotate-ccw" @click="load">
            {{ t('publishing.actions.retry') }}
          </Button>
        </template>
      </Alert>

      <form v-else class="flex flex-col gap-next-5" @submit.prevent="onSaveDraft">
        <!-- ── Where this goes ─────────────────────────────────────────────
             ONE FIELD IN TWO HALVES (`FieldShell segmented`), because the two are not
             independent: changing the first invalidates the second. Two separate boxes
             side by side would claim an independence that does not exist. -->
        <FormField
          :label="t('publishing.editor.destinationSection')"
          :error="fieldErrors.platform ?? fieldErrors.platform_connection_id"
        >
          <FieldShell segmented :error="!!(fieldErrors.platform || fieldErrors.platform_connection_id)">
            <Select
              v-model="platform"
              :options="platformOptions"
              :aria-label="t('publishing.editor.destination')"
            />
            <!-- For a rehearsal the account field DISAPPEARS rather than greying out: a
                 disabled control suggests an account exists somewhere and is merely out of
                 reach. The sentence is the server's own validation prose. -->
            <Select
              v-if="publishesPublicly"
              v-model="connectionId"
              :options="accountOptions"
              :placeholder="t('publishing.editor.accountPlaceholder')"
              :aria-label="t('publishing.editor.account')"
            />
          </FieldShell>
        </FormField>

        <Alert v-if="!publishesPublicly" variant="info" size="sm">
          {{ t('publishing.platforms.dry_run') }}
        </Alert>

        <Alert v-if="platformChangedOnce" variant="info" size="sm" dismissible @dismiss="platformChangedOnce = false">
          {{ t('publishing.editor.accountCleared') }}
        </Alert>

        <Alert v-if="noAccountsForPlatform" variant="warning" size="sm">
          <p class="font-next-medium">{{ t('publishing.editor.noAccounts.title') }}</p>
          <p>{{ t('publishing.editor.noAccounts.body') }}</p>
          <template #actions>
            <Button variant="outline" size="sm" leading-icon="link-2" @click="onConnectAccount">
              {{
                canSaveBeforeLeaving
                  ? t('publishing.editor.noAccounts.actionSaved')
                  : t('publishing.editor.noAccounts.actionNewTab')
              }}
            </Button>
          </template>
        </Alert>

        <!-- ── Content ─────────────────────────────────────────────────── -->
        <FormField
          :label="t('publishing.editor.titleField')"
          required
          :error="fieldErrors.title"
        >
          <TextInput
            v-model="title"
            :placeholder="t('publishing.editor.titlePlaceholder')"
            :maxlength="TITLE_MAX"
          >
            <template #trailing>
              <span class="text-next-2xs tabular-nums text-next-muted-foreground">
                {{ title.length }}/{{ TITLE_MAX }}
              </span>
            </template>
          </TextInput>
        </FormField>

        <FormField
          :label="t('publishing.editor.bodyField')"
          :description="t('publishing.editor.bodyHint')"
          :error="fieldErrors.body"
        >
          <Textarea
            v-model="body"
            auto-grow
            counter
            :maxlength="BODY_MAX"
            :rows="5"
            :placeholder="t('publishing.editor.bodyPlaceholder')"
          />
        </FormField>

        <!-- ── Media ───────────────────────────────────────────────────── -->
        <PublicationMediaField v-model="media" />
        <p v-if="mediaError" class="text-next-xs text-next-danger">{{ mediaError }}</p>

        <!-- ── The moment ──────────────────────────────────────────────── -->
        <FormField
          :label="t('publishing.editor.moment')"
          :description="momentHint"
          :error="fieldErrors.scheduled_at"
        >
          <!-- `min` IS DELIBERATELY NOT SET: "now" depends on the workspace's clock, which
               this client does not know for certain, so a locally computed minimum would
               either block a legal time or admit an illegal one. The SERVER refuses the past
               at arming time, with a sentence that already exists. -->
          <DateTimePicker v-model="moment" />
        </FormField>

        <div class="flex flex-wrap items-center gap-next-2">
          <Badge
            v-if="zone.differs"
            variant="warning"
            tone="subtle"
            size="sm"
            icon="alert-triangle"
          >
            {{ t('publishing.editor.momentZoneMismatch', '', {
              tz: store.timezone ?? '',
              localTz: zone.local ?? '',
            }) }}
          </Badge>
          <p class="inline-flex items-center gap-next-1 text-next-xs text-next-muted-foreground">
            <Icon name="info" class="text-next-xs" aria-hidden="true" />
            {{ t('publishing.editor.momentNotArming') }}
          </p>
        </div>

        <!-- The row moved while this form was open. -->
        <Alert v-if="staleConflict" variant="danger">
          <p class="font-next-medium">{{ t('publishing.editor.staleTitle') }}</p>
          <!-- The server's sentence when it sent one (a review hold names its pipeline);
               otherwise our own, for the 403, which carries no prose worth repeating. -->
          <p>{{ staleMessage ?? t('publishing.editor.staleBody') }}</p>
          <template #actions>
            <Button variant="outline" size="sm" leading-icon="rotate-ccw" @click="load">
              {{ t('publishing.actions.refresh') }}
            </Button>
          </template>
        </Alert>
      </form>
    </div>

    <footer class="flex flex-wrap items-center justify-end gap-next-2 border-t border-next-border p-next-4">
      <Button variant="ghost" :disabled="saving" @click="onCancel">{{ t('common.cancel') }}</Button>
      <Button variant="secondary" :loading="saving" @click="onSaveDraft">
        {{ t('publishing.editor.saveDraft') }}
      </Button>

      <!-- Disabled WITH A VISIBLE REASON, never a greyed button on its own. `aria-disabled`
           rather than `disabled` so the control stays reachable and the reason gets read —
           and DIMMED, because `aria-disabled` is a sentence for assistive technology that
           the pointer never hears: without the house inert look this button hovered like a
           live primary and then swallowed the click. Same three parts everywhere in this
           module: the look, the announcement, and a guard that actually stops the act. -->
      <Tooltip v-if="armBlockedReason" :label="armBlockedReason">
        <Button
          variant="primary"
          aria-disabled="true"
          class="opacity-60 cursor-not-allowed"
          @click.prevent
        >
          {{ armLabel }}
        </Button>
      </Tooltip>
      <Button v-else variant="primary" :loading="saving" @click="onSaveAndSchedule">
        {{ armLabel }}
      </Button>
    </footer>

    <p v-if="armBlockedReason" class="px-next-4 pb-next-3 text-next-xs text-next-muted-foreground">
      {{ armBlockedReason }}
    </p>

    <SchedulePublicationModal
      v-if="scheduleAfterSave"
      :key="scheduleAfterSave.id"
      :publication="scheduleAfterSave"
      :timezone="store.timezone"
      @close="scheduleAfterSave = null"
      @done="onScheduleDone"
    />
  </div>
</template>
