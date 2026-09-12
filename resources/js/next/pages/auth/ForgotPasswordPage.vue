<script setup lang="ts">
// ForgotPasswordPage — the PUBLIC "email me a reset link" screen.
//
// Shell-less + pre-auth, a sibling of LoginPage and AcceptInvite: the same centered card,
// the same corner language/theme controls, the same design-system parts.
//
// IT HAS EXACTLY TWO STATES AND THE SUCCESS ONE SAYS NOTHING ABOUT THE ADDRESS. The backend
// answers 200 with one sentence whether or not an account exists (anything else would turn
// this form into a membership check on the user table), so this screen must not "helpfully"
// distinguish either — no "we don't know that address", no different wording, no resend
// affordance that only appears for real accounts. The confirmation is deliberately phrased
// conditionally, and it is the same panel in both cases.
//
// A11y: a labelled <form>, the field wired through FormField, errors in an Alert with
// role="alert", the confirmation announced via role="status", and the submit Button is the
// single primary action with a loading state.
import { ref } from 'vue';
import { useI18n } from '../../app/i18n';
import { useTheme } from '../../app/lib/theme';
import { api } from '../../app/lib/api';
import { parseForgotError } from './passwordReset';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Button from '../../ui/primitives/Button.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Icon from '../../ui/primitives/Icon.vue';
import LocaleSwitcher from '../../ui/LocaleSwitcher.vue';

const { t } = useI18n();
const { isDark, toggle: toggleTheme } = useTheme();

const email = ref('');
const loading = ref(false);
const error = ref('');
/** Flips to the confirmation panel. Set on ANY 2xx — never on what the answer contained. */
const submitted = ref(false);

async function submit(): Promise<void> {
  if (loading.value) return;
  error.value = '';

  if (!email.value.trim()) {
    error.value = t('auth.forgot.errors.invalidEmail');
    return;
  }

  loading.value = true;
  try {
    await api.post('/auth/forgot-password', { email: email.value.trim() });
    submitted.value = true;
  } catch (err: unknown) {
    error.value = t(parseForgotError(err));
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
            <Icon name="mail" class="text-next-2xl" />
          </span>
          <h1 class="text-next-2xl font-next-semibold text-next-fg">
            {{ t('auth.forgot.title') }}
          </h1>
          <p class="text-next-sm text-next-muted-foreground">
            {{ t('auth.forgot.subtitle') }}
          </p>
        </div>

        <div class="rounded-next-lg border border-next-border bg-next-card p-next-6 shadow-next-sm">
          <!-- ===== Confirmation. Identical for a known and an unknown address. ===== -->
          <div
            v-if="submitted"
            class="flex flex-col items-center gap-next-3 text-center"
          >
            <span
              class="flex h-12 w-12 items-center justify-center rounded-next-full bg-next-success-subtle text-next-success-subtle-foreground"
              aria-hidden="true"
            >
              <Icon name="check-circle" class="text-next-xl" />
            </span>
            <h2 class="text-next-lg font-next-semibold text-next-fg">
              {{ t('auth.forgot.sent.title') }}
            </h2>
            <p class="text-next-sm text-next-muted-foreground" role="status">
              {{ t('auth.forgot.sent.body') }}
            </p>
            <p class="text-next-xs text-next-muted-foreground">
              {{ t('auth.forgot.sent.hint') }}
            </p>
            <Button variant="outline" href="/next/login" full-width leading-icon="arrow-left">
              {{ t('auth.forgot.backToLogin') }}
            </Button>
          </div>

          <!-- ===== The request form ===== -->
          <form
            v-else
            class="flex flex-col gap-next-4"
            novalidate
            :aria-label="t('auth.forgot.title')"
            @submit.prevent="submit"
          >
            <Alert v-if="error" variant="danger" :title="t('auth.forgot.errorTitle')">
              {{ error }}
            </Alert>

            <FormField :label="t('auth.email')" required>
              <TextInput
                v-model="email"
                type="email"
                name="email"
                autocomplete="email"
                leading-icon="at-sign"
                :placeholder="t('auth.emailPlaceholder')"
              />
            </FormField>

            <Button type="submit" variant="primary" full-width :loading="loading">
              {{ loading ? t('auth.forgot.submitting') : t('auth.forgot.submit') }}
            </Button>

            <Button variant="link" href="/next/login" full-width>
              {{ t('auth.forgot.backToLogin') }}
            </Button>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>
