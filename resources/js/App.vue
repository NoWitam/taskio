<template>
  <router-view />
</template>

<script setup>
import { onMounted } from 'vue';
import { useUserStore } from '@/store/user';

const userStore = useUserStore();

onMounted(() => {
  // Inicjalizuj store z danymi wstrzykniętymi przez backend
  userStore.initializeFromWindow();
});
</script>

<style>
@import url('https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600&display=swap');

body {
  font-family: 'Instrument Sans', sans-serif;
}
</style>
