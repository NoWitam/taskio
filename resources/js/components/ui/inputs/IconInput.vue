<script setup lang="ts">
  import { computed, ref } from "vue";
  import { cn } from "@/lib/helpers";

  import DropdownMenu from "@/components/ui/DropdownMenu.vue";
  import Icon from "@/components/ui/Icon.vue";
  import TextInput from "@/components/ui/inputs/TextInput.vue";
  import Tooltip from "@/components/ui/Tooltip.vue";
import Button from '@/components/ui/Button.vue';

  const iconModules = import.meta.glob("@/assets/icons/*.vue", { eager: true });
  const defaultIcons = Object.keys(iconModules)
    .map((p) => {
      const file = p.split("/").pop() ?? p;
      return file.endsWith(".vue") ? file.slice(0, -4) : file;
    })
    .filter(Boolean)
    .sort((a, b) => a.localeCompare(b));

  const props = withDefaults(
    defineProps<{
      modelValue: string | null;
      id?: string;
      label?: string;
      placeholder?: string;
      disabled?: boolean;
      error?: string;
      hint?: string;
      class?: string;
      clearable?: boolean;
      icons?: string[];
    }>(),
    {
      disabled: false,
      clearable: true,
    }
  );

  const emit = defineEmits<{ (e: "update:modelValue", value: string | null): void }>();

  const inputId = props.id ?? `icon_${Math.random().toString(16).slice(2)}`;
  const query = ref("");

  const disabled = computed(() => !!props.disabled);
  const allIcons = computed(() => (Array.isArray(props.icons) && props.icons.length ? props.icons : defaultIcons));
  const filteredIcons = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return allIcons.value;
    return allIcons.value.filter((name) => name.toLowerCase().includes(q));
  });

  function selectIcon(name: string, closeMenu?: () => void) {
    if (disabled.value) return;
    emit("update:modelValue", name);
    if (closeMenu) closeMenu();
  }

  function clearSelection() {
    if (disabled.value) return;
    emit("update:modelValue", null);
  }
</script>

<template>
  <div class="space-y-1.5 flex flex-col" :class="props.class">
    <label v-if="label" class="text-sm font-medium text-foreground text-left pl-0" :for="inputId">
      {{ label }}
    </label>

    <DropdownMenu align="start" width="2xl" :matchTriggerWidth="true">
      <template #activator="{ toggle }">
        <div
          :id="inputId"
          @click.stop="toggle()"
          :class="cn(
            'min-h-11 w-full flex items-center gap-2 rounded-lg border bg-card px-2 text-sm text-foreground cursor-pointer',
            'border-border hover:border-border/80',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:border-primary/40',
            disabled && 'opacity-60 cursor-not-allowed',
            error && 'border-danger focus-visible:ring-danger/25 focus-visible:border-danger'
          )"
        >
          <div class="flex items-center gap-2 min-w-0 flex-1">
            <div v-if="modelValue" class="h-7 w-7 rounded-md border border-border bg-background flex items-center justify-center">
              <Icon :name="modelValue" :size="16" />
            </div>

            <span v-if="modelValue" class="truncate font-semibold">{{ modelValue }}</span>
            <span v-else class="truncate text-muted-foreground">{{ placeholder ?? 'Wybierz ikonę…' }}</span>
          </div>

          <div class="flex items-center gap-1">
            <Button
              v-if="clearable && modelValue"
              variant="ghost"
              class="p-1 rounded-md text-muted-foreground hover:text-foreground hover:bg-secondary/60"
              @click.stop="clearSelection"
              :disabled="disabled"
              aria-label="Wyczyść wybór"
              title="Wyczyść"
            >
              <Icon name="x" :size="16" />
            </Button>

            <span class="text-muted-foreground flex items-center">
              <Icon name="chevron-down" :size="16" />
            </span>
          </div>
        </div>
      </template>

      <template #default="{ closeMenu }">
        <div class="min-w-md">
            <div class="p-2 border-b border-border">
                <TextInput v-model="query" placeholder="Szukaj ikony…" class="w-full" />
            </div>

          <div class="p-2 max-h-72 overflow-auto">
            <div
              v-if="filteredIcons.length"
              class="grid gap-2 grid-cols-8"
            >
                <Tooltip v-for="name in filteredIcons" :key="name" side="top" :disabled="disabled" zIndexClass="z-9999">
                <Button
                    :variant="modelValue === name ? 'primary' : 'secondary'"
                  :class="cn(
                    'aspect-square rounded-lg border bg-background flex items-center justify-center transition border-border',
                    disabled && 'opacity-60 cursor-not-allowed',
                    !disabled && modelValue !== name && 'hover:border-primary/60',
                    modelValue === name && 'border-primary/60 ring-2 ring-primary/20'
                  )"
                    :disabled="disabled"
                    @click="selectIcon(name, closeMenu)"
                    :aria-label="name"
                >
                  <Icon :name="name" :size="20" />
                </Button>

                <template #content>
                  {{ name }}
                </template>
              </Tooltip>
            </div>

            <div v-else class="py-8 px-3 text-center">
              <div class="mx-auto mb-2 h-10 w-10 rounded-full bg-secondary flex items-center justify-center text-foreground/60">
                <Icon name="search" :size="18" />
              </div>
              <div class="text-sm font-semibold text-foreground/80">Brak wyników</div>
              <div class="mt-1 text-xs text-muted-foreground">Nie znaleziono ikony pasującej do wyszukiwania.</div>
            </div>
          </div>
        </div>
      </template>
    </DropdownMenu>

    <p v-if="hint" class="text-xs text-muted-foreground text-left pl-0">
      {{ hint }}
    </p>
    <p v-if="error" class="text-xs text-danger text-left pl-0">
      {{ error }}
    </p>
  </div>
</template>
