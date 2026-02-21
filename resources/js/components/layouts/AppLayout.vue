<template>
  <div class="flex h-screen bg-gray-50">
    <!-- Sidebar -->
    <Sidebar :isOpen="sidebarOpen" @toggle="toggleSidebar" />

    <!-- Main content -->
    <div class="h-screen flex-1 flex flex-col overflow-hidden">
      <!-- Navbar -->
      <Navbar @toggle-sidebar="toggleSidebar" />

      <!-- Page content -->
      <main class="flex flex-col h-full min-h-0 bg-background overflow-x-hidden overflow-y-auto">
        <div class="flex-1 min-h-0">
          <router-view />
        </div>
      </main>
    </div>

    <ToastViewport />
  </div>
</template>

<script setup>
import { ref } from 'vue';
import Sidebar from '@/components/ui/Sidebar.vue';
import Navbar from '@/components/ui/Navbar.vue';
import ToastViewport from '@/components/ui/ToastViewport.vue';

const sidebarOpen = ref(true);

const toggleSidebar = () => {
  sidebarOpen.value = !sidebarOpen.value;
};
</script>

<style scoped>
/* Styles will be handled by Tailwind CSS */
</style>
