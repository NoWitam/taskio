<script setup lang="ts">
// ResetPasswordPage — the PUBLIC "set a new password" screen, reached from the mailed link.
//
// Shell-less + pre-auth, a sibling of LoginPage / ForgotPasswordPage / AcceptInvite. Three
// mutually-exclusive states, resolved by the pure `passwordReset` module:
//   missing_link → arrived without `?token=&email=` (a truncated or hand-typed URL)
//   form         → the two password fields
//   dead_link    → the server refused the link: invalid, EXPIRED or already spent
//
// THE DEAD-LINK STATE IS A FULL STATE, NOT AN INLINE ERROR, because there is nothing on this
// form the user can change to fix it — the only way forward is a new link, so that is the
// screen's primary action. It covers all three causes with one sentence deliberately: the
// backend answers identically for them (telling an expired link from an unknown address apart
// would reveal whether the address has an account), so inventing three messages here would be
// the frontend claiming knowledge it does not have.
//
// ON SUCCESS the backend returns a LOGIN-shaped payload — the person just proved control of
// the inbox AND chose the password, and every other session of theirs was revoked server-side,
// so we persist the fresh token and go straight into the app (the same handling AcceptInvite
// does with its own login-shaped answer).
//
// A11y: labelled fields via FormField, per-field messages, a page-level Alert with
// role="alert", one primary action per state, loading state on submit.
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore, type AuthContext } from '../../app/stores/auth';
import { useI18n } from '../../app/i18n';
import { useTheme } from '../../app/lib/theme';
import { api } from '../../app/lib/api';
import { parseResetError, parseResetLink, validateNewPassword } from './passwordReset';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Button from '../../ui/primitives/Button.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Icon from '../../ui/primitives/Icon.vue';
import LocaleSwitcher from '../../ui/LocaleSwitcher.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const { t } = useI18n();
const { isDark, toggle: toggleTheme } = useTheme();

/** Login-shaped reset answer: the context plus the message and the fresh token. */
interface ResetResponse extends AuthContext {
  token: string;
  message: string;
}

/** The `?token=&email=` pair, or null when the link arrived incomplete. */
const link = computed(() => parseResetLink(route.query));

const password = ref('');
const confirmation = ref('');
const submitting = ref(false);
/** Set when the server refuses the LINK — switches the whole card to the dead-link state. */
const linkDead = ref(false);
const formError = ref('');
const passwordError = ref('');

type PageState = 'missing_link' | 'dead_link' | 'form';
const pageState = computed<PageState>(() => {
  if (link.value === null) return 'missing_link';
  if (linkDead.value) return 'dead_link';
  return 'form';
});

async function submit(): Promise<void> {
  if (submitting.value || link.value === null) return;
  formError.value = '';
  passwordError.value = '';

  // Mirror the server's rules so the two fixable problems stay distinguishable — the server
  // reports both under one field. It re-checks either way; this is not the guard.
  const localProblem = validateNewPassword(password.value, confirmation.value);
  if (localProblem !== null) {
    passwordError.value = t(localProblem);
    return;
  }

  submitting.value = true;
  try {
    const data = await api.post<ResetResponse>('/auth/reset-password', {
      token: link.value.token,
      email: link.value.email,
      password: password.value,
      password_confirmation: confirmation.value,
    });

    // Persist the fresh session, then apply the login-shaped context and enter the app.
    auth.persistToken(data.token);
    auth.applyContext(data);

    await router.replace('/dashboard');
  } catch (err: unknown) {
    const parsed = parseResetError(err);

    if (parsed.field === 'token' || parsed.field === 'email') {
      linkDead.value = true;
      return;
    }
    if (parsed.field === 'password') {
      passwordError.value = t(parsed.key);
      return;
    }
    formError.value = t(parsed.key);
  } finally {
    submitting.value = false;
  }
}
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
        :aria-label="isDark ? t('app.themeToLight') : t('app.themeToDark')"
        @click="toggleTheme"
      />
    </div>

    <div class="flex flex-1 items-center justify-center px-next-4 pb-next-12">
      <div class="w-full max-w-sm">
        <!-- Brand -->
        <div class="mb-next-6 flex flex-col items-center gap-next-2 text-center">
          <span
            class="flex h-12 w-12 items-center justify-center rounded-next-lg bg-next-primary text-next-primary-foreground"
            aria-hidden="true"
          >
            <Icon name="lock" class="text-next-2xl" />
          </span>
          <h1 class="text-next-2xl font-next-semibold text-next-fg">
            {{ t('auth.reset.title') }}
          </h1>
          <p v-if="pageState === 'form'" class="text-next-sm text-next-muted-foreground">
            {{ t('auth.reset.subtitle') }}
          </p>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-6 shadow-next-sm">
          <!-- ===== The link never carried a token (truncated / hand-typed URL) ===== -->
          <div
            v-if="pageState === 'missing_link'"
            class="flex flex-col items-center gap-next-3 text-center"
          >
            <span
              class="flex h-12 w-12 items-center justify-center rounded-next-full bg-next-warning-subtle text-next-warning-subtle-foreground"
              aria-hidden="true"
            >
              <Icon name="alert-triangle" class="text-next-xl" />
            </span>
            <h2 class="text-next-lg font-next-semibold text-next-fg">
              {{ t('auth.reset.missingLink.title') }}
            </h2>
            <p class="text-next-sm text-next-muted-foreground" role="alert">
              {{ t('auth.reset.missingLink.body') }}
            </p>
            <Button variant="primary" href="/next/forgot-password" full-width>
              {{ t('auth.reset.requestNewLink') }}
            </Button>
            <Button variant="link" href="/next/login" full-width>
              {{ t('auth.forgot.backToLogin') }}
            </Button>
          </div>

          <!-- ===== The server refused the link: invalid, expired or already used ===== -->
          <div
            v-else-if="pageState === 'dead_link'"
            class="flex flex-col items-center gap-next-3 text-center"
          >
            <span
              class="flex h-12 w-12 items-center justify-center rounded-next-full bg-next-warning-subtle text-next-warning-subtle-foreground"
              aria-hidden="true"
            >
              <Icon name="clock" class="text-next-xl" />
            </span>
            <h2 class="text-next-lg font-next-semibold text-next-fg">
              {{ t('auth.reset.deadLink.title') }}
            </h2>
            <p class="text-next-sm text-next-muted-foreground" role="alert">
              {{ t('auth.reset.errors.invalidLink') }}
            </p>
            <Button variant="primary" href="/next/forgot-password" full-width>
              {{ t('auth.reset.requestNewLink') }}
            </Button>
            <Button variant="link" href="/next/login" full-width>
              {{ t('auth.forgot.backToLogin') }}
            </Button>
          </div>

          <!-- ===== The form ===== -->
          <form
            v-else
            class="flex flex-col gap-next-4"
            novalidate
            :aria-label="t('auth.reset.title')"
            @submit.prevent="submit"
          >
            <Alert v-if="formError" variant="danger" :title="t('auth.reset.errorTitle')">
              {{ formError }}
            </Alert>

            <!-- The address the link is bound to, shown read-only so the person can see WHICH
                 account they are changing (it is posted from the link, never from this field). -->
            <FormField :label="t('auth.email')">
              <TextInput
                :model-value="link?.email ?? ''"
                type="email"
                name="email"
                readonly
                leading-icon="at-sign"
              />
            </FormField>

            <FormField
              :label="t('auth.reset.newPassword')"
              :description="t('auth.reset.rule')"
              :error="passwordError || undefined"
              required
            >
              <TextInput
                v-model="password"
                type="password"
                name="password"
                autocomplete="new-password"
                leading-icon="lock"
                :placeholder="t('auth.reset.newPasswordPlaceholder')"
              />
            </FormField>

            <FormField :label="t('auth.reset.confirmPassword')" required>
              <TextInput
                v-model="confirmation"
                type="password"
                name="password_confirmation"
                autocomplete="new-password"
                leading-icon="lock"
                :placeholder="t('auth.reset.confirmPasswordPlaceholder')"
              />
            </FormField>

            <p class="text-next-xs text-next-muted-foreground">
              {{ t('auth.reset.revokesSessions') }}
            </p>

            <Button type="submit" variant="primary" full-width :loading="submitting">
              {{ submitting ? t('auth.reset.submitting') : t('auth.reset.submit') }}
            </Button>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>
