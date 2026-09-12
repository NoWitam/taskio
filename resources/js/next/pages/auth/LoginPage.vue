<script setup lang="ts">
// LoginPage — the public sign-in screen for the "next" frontend.
//
// A centered card composed entirely from the design system (FormField + TextInput
// for email/password, Checkbox for "remember me", Button for submit, Alert for the
// error). On success it pushes to the `?redirect=` query (if safe) or /dashboard.
// A LocaleSwitcher + theme toggle sit in the top-right corner so the user can pick
// their language/theme before signing in.
//
// A11y: a labelled <form>; each control is wired through FormField; the error is an
// Alert with role="alert" (assertive) so it is announced; the submit Button shows a
// loading state and is the form's single primary action.
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { AxiosError } from 'axios';
import { useAuthStore } from '../../app/stores/auth';
import { useI18n } from '../../app/i18n';
import { useTheme } from '../../app/lib/theme';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Checkbox from '../../ui/forms/Checkbox.vue';
import Button from '../../ui/primitives/Button.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Icon from '../../ui/primitives/Icon.vue';
import LocaleSwitcher from '../../ui/LocaleSwitcher.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const { t } = useI18n();
const { isDark, toggle: toggleTheme } = useTheme();

const email = ref('');
const password = ref('');
const remember = ref(false);
const loading = ref(false);
const error = ref('');

// Only honor SAME-ORIGIN, app-relative redirects (avoid open-redirects).
const redirectTarget = computed(() => {
  const raw = route.query.redirect;
  const path = typeof raw === 'string' ? raw : '';
  return path.startsWith('/') && !path.startsWith('//') ? path : '/dashboard';
});

/** Map a failed login to a friendly, translated message (no raw backend text). */
function messageFor(err: unknown): string {
  const axiosErr = err as AxiosError | undefined;
  const status = axiosErr?.response?.status;
  if (status === 401 || status === 422) return t('auth.invalidCredentials', 'The email or password is incorrect.');
  if (status === 429) return t('auth.tooManyAttempts', 'Too many attempts. Please wait and try again.');
  return t('auth.genericError', 'Something went wrong. Please try again.');
}

async function submit(): Promise<void> {
  if (loading.value) return;
  error.value = '';
  loading.value = true;
  try {
    await auth.login(email.value, password.value, remember.value);
    await router.replace(redirectTarget.value);
  } catch (err) {
    error.value = messageFor(err);
  } finally {
    loading.value = false;
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
        :aria-label="isDark ? t('app.themeToLight', 'Switch to light theme') : t('app.themeToDark', 'Switch to dark theme')"
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
            <Icon name="layout-dashboard" class="text-next-2xl" />
          </span>
          <h1 class="text-next-2xl font-next-semibold text-next-fg">
            {{ t('app.name', 'Taskio') }}
          </h1>
          <p class="text-next-sm text-next-muted-foreground">
            {{ t('auth.subtitle', 'Sign in to your Taskio workspace') }}
          </p>
        </div>

        <form
          class="flex flex-col gap-next-4 rounded-next-lg border border-next-border bg-next-card p-next-6 shadow-next-sm"
          novalidate
          :aria-label="t('auth.title', 'Sign in')"
          @submit.prevent="submit"
        >
          <Alert
            v-if="error"
            variant="danger"
            :title="t('auth.errorTitle', 'Sign in failed')"
          >
            {{ error }}
          </Alert>

          <FormField :label="t('auth.email', 'Email')" required>
            <TextInput
              v-model="email"
              type="email"
              name="email"
              autocomplete="email"
              leading-icon="at-sign"
              :placeholder="t('auth.emailPlaceholder', 'you@example.com')"
            />
          </FormField>

          <FormField :label="t('auth.password', 'Password')" required>
            <TextInput
              v-model="password"
              type="password"
              name="password"
              autocomplete="current-password"
              leading-icon="lock"
              :placeholder="t('auth.passwordPlaceholder', 'Your password')"
            />
          </FormField>

          <div class="flex items-center justify-between gap-next-2">
            <Checkbox v-model="remember" :label="t('auth.remember', 'Remember me')" />

            <!-- The only route to the reset flow. A `link` Button (a real <a href>) rather
                 than router navigation, matching how the sibling public pages cross-link. -->
            <Button variant="link" size="sm" href="/next/forgot-password">
              {{ t('auth.forgotLink', 'Forgot your password?') }}
            </Button>
          </div>

          <Button
            type="submit"
            variant="primary"
            full-width
            :loading="loading"
          >
            {{ loading ? t('auth.submitting', 'Signing in…') : t('auth.submit', 'Sign in') }}
          </Button>
        </form>
      </div>
    </div>
  </div>
</template>
