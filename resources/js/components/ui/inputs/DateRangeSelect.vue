<script setup lang="ts">
import { computed } from "vue";
import { cn } from "@/lib/helpers";
import DropdownMenu from "@/components/ui/DropdownMenu.vue";
import Icon from "@/components/ui/Icon.vue";
import Button from "@/components/ui/Button.vue";
import DateInput from "./DateInput.vue";
import SwitchInput from "./SwitchInput.vue";

export type DateRangePreset = "" | "today" | "this_week" | "last_week" | "this_month";

export interface DateRangeValue {
  preset: DateRangePreset;
  from: string | null;
  to: string | null;
  hide_without_deadline: boolean;
}

const props = withDefaults(
  defineProps<{
    modelValue: DateRangeValue | null;
    id?: string;
    label?: string;
    disabled?: boolean;
    error?: string;
    hint?: string;
    class?: string;
    clearable?: boolean;
  }>(),
  {
    clearable: true,
  }
);

const emit = defineEmits<{ (e: "update:modelValue", value: DateRangeValue): void }>();

const inputId = props.id ?? `daterange_${Math.random().toString(16).slice(2)}`;

const value = computed<DateRangeValue>({
  get() {
    return (
      props.modelValue ?? {
        preset: "",
        from: null,
        to: null,
        hide_without_deadline: false,
      }
    );
  },
  set(v) {
    emit("update:modelValue", v);
  },
});

const presetOptions: Array<{ id: DateRangePreset; label: string }> = [
  { id: "", label: "Wszystkie terminy" },
  { id: "today", label: "Dzisiaj" },
  { id: "this_week", label: "Ten tydzień" },
  { id: "last_week", label: "Ostatni tydzień" },
  { id: "this_month", label: "Ten miesiąc" },
];

function displayFromYmd(ymd: string | null) {
  if (!ymd) return "";
  const m = String(ymd).match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!m) return "";
  return `${m[3]}.${m[2]}.${m[1]}`;
}

const displayText = computed(() => {
  const from = value.value.from;
  const to = value.value.to;

  if (from && to) return `${displayFromYmd(from)} - ${displayFromYmd(to)}`;
  if (from) return `od ${displayFromYmd(from)}`;
  if (to) return `do ${displayFromYmd(to)}`;

  const preset = value.value.preset ?? "";
  return presetOptions.find((o) => o.id === preset)?.label ?? "Wszystkie terminy";
});

function setPreset(preset: DateRangePreset) {
  value.value = {
    preset,
    from: null,
    to: null,
    hide_without_deadline: value.value.hide_without_deadline,
  };
}

function setFrom(from: string | null) {
  const raw = from ?? null;
  const iso = raw && /^\d{4}-\d{2}-\d{2}$/.test(raw) ? raw : null;

  // Jeśli jesteśmy w trybie presetu i użytkownik tylko kliknął w input (emit pusty/niepoprawny),
  // nie przełączaj na "Wszystkie daty".
  if (!iso && (raw === null || raw === "") && value.value.preset && !value.value.from && !value.value.to) {
    return;
  }

  // Ignoruj częściowe wpisy (DateInput może chwilowo emitować string nie-ISO).
  if (!iso && raw) return;

  value.value = {
    preset: "",
    from: iso,
    to: value.value.to || null,
    hide_without_deadline: value.value.hide_without_deadline,
  };
}

function setTo(to: string | null) {
  const raw = to ?? null;
  const iso = raw && /^\d{4}-\d{2}-\d{2}$/.test(raw) ? raw : null;

  if (!iso && (raw === null || raw === "") && value.value.preset && !value.value.from && !value.value.to) {
    return;
  }

  if (!iso && raw) return;

  value.value = {
    preset: "",
    from: value.value.from || null,
    to: iso,
    hide_without_deadline: value.value.hide_without_deadline,
  };
}

function setHideWithoutDeadline(v: boolean) {
  value.value = {
    preset: value.value.preset,
    from: value.value.from,
    to: value.value.to,
    hide_without_deadline: v,
  };
}

function clear(closeMenu?: () => void) {
  if (props.disabled) return;
  value.value = { preset: "", from: null, to: null, hide_without_deadline: false };
  closeMenu?.();
}
</script>

<template>
  <div class="space-y-1.5 flex flex-col">
    <label v-if="label" class="text-sm font-medium text-foreground text-left" :for="inputId">
      {{ label }}
    </label>

    <DropdownMenu :class="class" align="start" :matchTriggerWidth="true">
      <template #activator>
        <div
          :id="inputId"
          :class="cn(
            'min-h-11 w-full font-semibold flex items-center gap-2 rounded-lg border bg-card px-3 py-2 text-sm text-foreground cursor-pointer',
            'border-border hover:border-border/80',
            'transition-colors hover:bg-secondary/40',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:border-primary/40',
            disabled && 'opacity-60 cursor-not-allowed',
            error && 'border-danger focus-visible:ring-danger/25 focus-visible:border-danger'
          )"
        >
          <span class="text-muted-foreground">
            <Icon name="calendar" size="md" />
          </span>

          <span class="truncate flex-1 min-w-0">{{ displayText }}</span>

          <span class="text-muted-foreground">
            <Icon name="chevron-down" :size="16" />
          </span>
        </div>
      </template>

      <template #default="{ closeMenu }">
        <div class="p-2">
          <div class="px-1 pb-2">
            <SwitchInput
              :modelValue="value.hide_without_deadline"
              @update:modelValue="setHideWithoutDeadline"
              label="Ukryj zadania bez terminu"
              hint="Gdy włączone, zadania bez ustawionego terminu nie pojawią się w wynikach (nawet przy zakresie)."
            />
          </div>

          <div class="my-2 border-t border-border" />

          <div class="grid grid-cols-1 gap-1">
            <button
              v-for="opt in presetOptions"
              :key="opt.id || 'all'"
              type="button"
              class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm font-semibold text-left transition hover:cursor-pointer"
              :class="[
                value.preset === opt.id && !value.from && !value.to
                  ? 'bg-primary text-background'
                  : 'hover:bg-primary/80',
              ]"
              @click="setPreset(opt.id)"
            >
              {{ opt.label }}
            </button>
          </div>

          <div class="my-2 border-t border-border" />

          <div class="grid grid-cols-2 gap-2">
            <DateInput
              :modelValue="value.from"
              @update:modelValue="setFrom"
              label="Od"
              :max="value.to"
              clearable
              class="w-full"
            />
            <DateInput
              :modelValue="value.to"
              @update:modelValue="setTo"
              label="Do"
              :min="value.from"
              clearable
              class="w-full"
            />
          </div>

          <div v-if="clearable" class="mt-2 border-t border-border pt-2 flex justify-end gap-2">
            <Button variant="ghost" type="button" :disabled="disabled" @click="clear(closeMenu)">
              Wyczyść
            </Button>
            <Button variant="primary" type="button" @click="closeMenu">Gotowe</Button>
          </div>
        </div>
      </template>
    </DropdownMenu>

    <p v-if="hint && !error" class="text-xs text-muted-foreground">{{ hint }}</p>
    <p v-if="error" class="text-xs text-danger">{{ error }}</p>
  </div>
</template>
