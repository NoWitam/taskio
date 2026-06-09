<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useUserStore } from '@/store/user';

const router = useRouter();
const userStore = useUserStore();

const email = ref('');
const password = ref('');
const remember = ref(false);
const error = ref('');
const loading = ref(false);

const submit = async () => {
  error.value = '';
  loading.value = true;

  try {
    await userStore.login(email.value, password.value, remember.value);
    const redirect = router.currentRoute.value.query.redirect;
    router.push(typeof redirect === 'string' ? redirect : '/app');
  } catch (e) {
    error.value = e?.response?.data?.message || 'Logowanie nie powiodło się.';
  } finally {
    loading.value = false;
  }
};
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-background p-4">
    <form
      class="w-full max-w-sm space-y-4 rounded-lg border border-border bg-card p-6 shadow-sm"
      @submit.prevent="submit"
    >
      <h1 class="text-xl font-semibold text-foreground">Zaloguj się</h1>

      <p v-if="error" class="rounded-md bg-danger/10 px-3 py-2 text-sm text-danger">
        {{ error }}
      </p>

      <label class="block space-y-1">
        <span class="text-sm text-muted-foreground">Email</span>
        <input
          v-model="email"
          type="email"
          required
          autocomplete="email"
          class="w-full rounded-md border border-border bg-background px-3 py-2 text-foreground"
        />
      </label>

      <label class="block space-y-1">
        <span class="text-sm text-muted-foreground">Hasło</span>
        <input
          v-model="password"
          type="password"
          required
          autocomplete="current-password"
          class="w-full rounded-md border border-border bg-background px-3 py-2 text-foreground"
        />
      </label>

      <label class="flex items-center gap-2 text-sm text-foreground">
        <input v-model="remember" type="checkbox" />
        Zapamiętaj mnie
      </label>

      <button
        type="submit"
        :disabled="loading"
        class="w-full rounded-md bg-primary px-4 py-2 font-medium text-primary-foreground disabled:opacity-50"
      >
        {{ loading ? '…' : 'Zaloguj' }}
      </button>
    </form>
  </div>
</template>
