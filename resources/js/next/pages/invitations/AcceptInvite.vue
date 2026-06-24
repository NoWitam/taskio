<script setup lang="ts">
// AcceptInvite — the PUBLIC invitation-accept page for the "next" frontend.
//
// Shell-less + pre-auth (mirrors LoginPage): a centered card with a corner
// language/theme control. It renders ONE of several mutually-exclusive states
// driven entirely by the verified `GET /invitations/{token}` preview + the
// viewer's auth state (resolved by the pure `acceptInvite` state machine):
//   loading      → skeleton while the preview loads
//   not_found    → unknown token (404; handled inline — the api interceptor never
//                  redirects here since these endpoints never 401)
//   invalid      → preview.valid === false → a message keyed by `status`
//                  (expired / revoked / already accepted)
//   accept_only  → authed viewer whose email matches → just an "Accept" button
//   login        → an account exists → a password field (login)
//   register     → no account → name + password (email shown read-only)
//
// On success the backend returns a LOGIN-shaped payload. If it carries a `token`
// (unauth paths) we persist it via the auth store; either way we apply the context
// and route into the dashboard — the joined workspace is already `current_workspace`.
//
// A11y: labelled fields, a single primary action per state, errors via Alert
// (role="alert") + per-field FormField messages, status conveyed by text+icon.
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore, type AuthContext } from '../../app/stores/auth';
import { useI18n } from '../../app/i18n';
import { useTheme } from '../../app/lib/theme';
import { api } from '../../app/lib/api';
import {
  resolveAcceptStep,
  parseAcceptError,
  acceptErrorMessageKey,
  type InvitationPreview,
  type AcceptStep,
} from './acceptInvite';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Button from '../../ui/primitives/Button.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import LocaleSwitcher from '../../ui/LocaleSwitcher.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const { t } = useI18n();
const { isDark, toggle: toggleTheme } = useTheme();

/** Login-shaped accept payload (token is absent on the already-authed path). */
interface AcceptResponse extends AuthContext {
  token?: string;
}

const token = computed(() => String(route.params.token ?? ''));

// --- Preview load state ---------------------------------------------------
type PageState = 'loading' | 'not_found' | 'ready' | 'load_error';
const pageState = ref<PageState>('loading');
const preview = ref<InvitationPreview | null>(null);

// --- Accept form state ----------------------------------------------------
const name = ref('');
const password = ref('');
const submitting = ref(false);
/** Page-level error (token/generic kinds + load failures). */
const formError = ref('');
/** Per-field errors bound to the FormField under the matching control. */
const emailError = ref('');
const passwordError = ref('');
const nameError = ref('');

// --- Derived: which UI to render ------------------------------------------
const step = computed<AcceptStep | null>(() => {
  if (!preview.value) return null;
  return resolveAcceptStep(preview.value, {
    isAuthenticated: auth.isAuthenticated,
    email: auth.user?.email,
  });
});

// Human message for an invalid invitation, keyed by its lifecycle status.
const invalidMessageKey = computed(() => {
  const status = preview.value?.status ?? '';
  if (status === 'expired') return 'acceptInvite.invalid.expired';
  if (status === 'revoked') return 'acceptInvite.invalid.revoked';
  if (status === 'accepted') return 'acceptInvite.invalid.accepted';
  return 'acceptInvite.invalid.generic';
});

const workspaceName = computed(() => preview.value?.workspace_name ?? '');
const inviterName = computed(() => preview.value?.invited_by_name ?? '');
const inviteEmail = computed(() => preview.value?.email ?? '');

// --- Load the preview -----------------------------------------------------
async function loadPreview(): Promise<void> {
  pageState.value = 'loading';
  preview.value = null;
  try {
    const res = await api.get<{ data: InvitationPreview }>(`/invitations/${token.value}`);
    preview.value = res.data;
    pageState.value = 'ready';
  } catch (err: unknown) {
    // 404 = unknown token → the explicit not-found state. Anything else (network)
    // → a retryable load-error. These endpoints never 401, so the interceptor
    // never redirects; we still catch defensively so nothing leaks out.
    const status = (err as { response?: { status?: number } })?.response?.status;
    pageState.value = status === 404 ? 'not_found' : 'load_error';
  }
}

onMounted(loadPreview);

// --- Submit the accept ----------------------------------------------------
function clearErrors(): void {
  formError.value = '';
  emailError.value = '';
  passwordError.value = '';
  nameError.value = '';
}

function placeError(field: 'email' | 'password' | 'token' | null, message: string): void {
  if (field === 'email') emailError.value = message;
  else if (field === 'password') passwordError.value = message;
  else formError.value = message; // token + generic are page-level
}

async function submit(): Promise<void> {
  if (submitting.value || !step.value) return;
  clearErrors();

  // Client-side guards so we don't round-trip an obviously-empty form.
  if (step.value === 'login' && !password.value) {
    passwordError.value = t('acceptInvite.errors.login_required');
    return;
  }
  if (step.value === 'register') {
    if (!name.value.trim()) {
      nameError.value = t('acceptInvite.errors.nameRequired');
      return;
    }
    if (!password.value) {
      passwordError.value = t('acceptInvite.errors.registration_required');
      return;
    }
  }

  // Build the body per branch (accept_only sends nothing).
  const body: { name?: string; password?: string } = {};
  if (step.value === 'login') body.password = password.value;
  if (step.value === 'register') {
    body.name = name.value.trim();
    body.password = password.value;
  }

  submitting.value = true;
  try {
    const data = await api.post<AcceptResponse>(`/invitations/${token.value}/accept`, body);

    // Persist a returned token (unauth paths) then apply the login-shaped context.
    // The already-authed path returns NO token — we keep the existing one. Either
    // way `current_workspace` is the just-joined workspace, so applyContext switches.
    if (data.token) auth.persistToken(data.token);
    auth.applyContext(data);

    await router.replace('/dashboard');
  } catch (err: unknown) {
    // A 404 here means the token vanished between preview + accept → not-found.
    const status = (err as { response?: { status?: number } })?.response?.status;
    if (status === 404) {
      pageState.value = 'not_found';
      return;
    }
    const parsed = parseAcceptError(err);
    placeError(parsed.field, t(acceptErrorMessageKey(parsed.kind)));
  } finally {
    submitting.value = false;
  }
}

const submitLabel = computed(() => {
  if (step.value === 'register') return t('acceptInvite.register.submit');
  if (step.value === 'login') return t('acceptInvite.login.submit');
  return t('acceptInvite.acceptOnly.submit');
});

const skeletonLines = [1, 2, 3];
</script>

<template>
  <div class="flex min-h-screen flex-col bg-next-bg text-next-fg">
    <!-- Corner controls: language + theme, usable before signing in. -->
    <div class="flex items-center justify-end gap-next-2 p-next-4">
      <LocaleSwitcher />
      <Button
        variant="outline"
        size="icon"
        :leading-icon="isDark ? 'sun' : 'moon'"
        :aria-label="isDark ? t('app.themeToLight', 'Switch to light theme') : t('app.themeToDark', 'Switch to dark theme')"
        @click="toggleTheme"
      />
    </div>

    <div class="flex flex-1 items-center justify-center px-next-4 pb-next-12">
      <div class="w-full max-w-md">
        <!-- Brand -->
        <div class="mb-next-6 flex flex-col items-center gap-next-2 text-center">
          <span
            class="flex h-12 w-12 items-center justify-center rounded-next-lg bg-next-primary text-next-primary-foreground"
            aria-hidden="true"
          >
            <Icon name="layout-dashboard" class="text-next-2xl" />
          </span>
          <h1 class="text-next-2xl font-next-semibold text-next-fg">
            {{ t('acceptInvite.title') }}
          </h1>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-6 shadow-next-sm">
          <!-- ===== Loading skeleton (mimics the card content) ===== -->
          <div v-if="pageState === 'loading'" class="flex flex-col gap-next-4" aria-busy="true">
            <span class="sr-only">{{ t('acceptInvite.loading') }}</span>
            <Skeleton variant="text" width="70%" />
            <Skeleton variant="text" width="90%" />
            <Skeleton v-for="n in skeletonLines" :key="n" variant="rect" height="2.5rem" radius="md" />
          </div>

          <!-- ===== Not found (unknown token) ===== -->
          <div v-else-if="pageState === 'not_found'" class="flex flex-col items-center gap-next-3 text-center">
            <span
              class="flex h-12 w-12 items-center justify-center rounded-next-full bg-next-danger-subtle text-next-danger-subtle-foreground"
              aria-hidden="true"
            >
              <Icon name="alert-triangle" class="text-next-xl" />
            </span>
            <h2 class="text-next-lg font-next-semibold text-next-fg">
              {{ t('acceptInvite.notFound.title') }}
            </h2>
            <p class="text-next-sm text-next-muted-foreground" role="alert">
              {{ t('acceptInvite.notFound.body') }}
            </p>
            <Button variant="outline" href="/next/login" full-width>
              {{ t('acceptInvite.goToLogin') }}
            </Button>
          </div>

          <!-- ===== Load error (network) — retryable ===== -->
          <div v-else-if="pageState === 'load_error'" class="flex flex-col items-center gap-next-3 text-center">
            <span
              class="flex h-12 w-12 items-center justify-center rounded-next-full bg-next-danger-subtle text-next-danger-subtle-foreground"
              aria-hidden="true"
            >
              <Icon name="alert-circle" class="text-next-xl" />
            </span>
            <h2 class="text-next-lg font-next-semibold text-next-fg">
              {{ t('acceptInvite.loadError.title') }}
            </h2>
            <p class="text-next-sm text-next-muted-foreground" role="alert">
              {{ t('acceptInvite.loadError.body') }}
            </p>
            <Button variant="primary" leading-icon="rotate-ccw" full-width @click="loadPreview">
              {{ t('common.retry', 'Retry') }}
            </Button>
          </div>

          <!-- ===== Ready: branch by the resolved step ===== -->
          <template v-else-if="pageState === 'ready' && preview">
            <!-- Invalid invitation (expired / revoked / already accepted). -->
            <div v-if="step === 'invalid'" class="flex flex-col items-center gap-next-3 text-center">
              <span
                class="flex h-12 w-12 items-center justify-center rounded-next-full bg-next-warning-subtle text-next-warning-subtle-foreground"
                aria-hidden="true"
              >
                <Icon name="clock" class="text-next-xl" />
              </span>
              <h2 class="text-next-lg font-next-semibold text-next-fg">
                {{ t('acceptInvite.invalid.title') }}
              </h2>
              <p class="text-next-sm text-next-muted-foreground" role="alert">
                {{ t(invalidMessageKey) }}
              </p>
              <Button variant="outline" href="/next/login" full-width>
                {{ t('acceptInvite.goToLogin') }}
              </Button>
            </div>

            <!-- Valid: the join intro + the per-step form. -->
            <form v-else class="flex flex-col gap-next-4" novalidate @submit.prevent="submit">
              <!-- Invitation intro: workspace + inviter, conveyed as text. -->
              <div class="flex flex-col gap-next-1 text-center">
                <p class="text-next-sm text-next-muted-foreground">
                  {{ t('acceptInvite.intro.invitedBy', undefined, { inviter: inviterName }) }}
                </p>
                <p class="text-next-lg font-next-semibold text-next-fg">
                  {{ t('acceptInvite.intro.workspace', undefined, { workspace: workspaceName }) }}
                </p>
              </div>

              <Alert v-if="formError" variant="danger" :title="t('acceptInvite.errorTitle')">
                {{ formError }}
              </Alert>

              <!-- Email: always shown read-only (bound to the invite). -->
              <FormField :label="t('acceptInvite.fields.email')" :error="emailError || undefined">
                <TextInput
                  :model-value="inviteEmail"
                  type="email"
                  name="email"
                  readonly
                  leading-icon="at-sign"
                />
              </FormField>

              <!-- Register: a name field (new account). -->
              <FormField
                v-if="step === 'register'"
                :label="t('acceptInvite.fields.name')"
                :error="nameError || undefined"
                required
              >
                <TextInput
                  v-model="name"
                  type="text"
                  name="name"
                  autocomplete="name"
                  leading-icon="user"
                  :placeholder="t('acceptInvite.fields.namePlaceholder')"
                />
              </FormField>

              <!-- Login + Register: a password field. accept_only has none. -->
              <FormField
                v-if="step === 'login' || step === 'register'"
                :label="step === 'register'
                  ? t('acceptInvite.fields.newPassword')
                  : t('acceptInvite.fields.password')"
                :error="passwordError || undefined"
                required
              >
                <TextInput
                  v-model="password"
                  type="password"
                  name="password"
                  :autocomplete="step === 'register' ? 'new-password' : 'current-password'"
                  leading-icon="lock"
                  :placeholder="t('acceptInvite.fields.passwordPlaceholder')"
                />
              </FormField>

              <!-- Helper note for the login branch (existing account). -->
              <p v-if="step === 'login'" class="text-next-xs text-next-muted-foreground">
                {{ t('acceptInvite.login.hint') }}
              </p>

              <Button type="submit" variant="primary" full-width :loading="submitting">
                {{ submitting ? t('acceptInvite.submitting') : submitLabel }}
              </Button>
            </form>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
