<script setup lang="ts">
import { ref, computed, watch } from 'vue';

type PillItem = { key: string; label: string };

const props = withDefaults(defineProps<{
  modelValue?: string[] | null; // array of keys
  id?: string;
  name?: string;
  class?: string;
  disabled?: boolean;
  items: PillItem[]; // required: list of {key, label}
  label?: string;
  hint?: string;
  error?: string;
  ariaLabel?: string;
  disabledItems?: string[]; // keys that are disabled
}>(), { disabled: false, disabledItems: [] });

const emit = defineEmits<{ (e: 'update:modelValue', v: string[] | null): void }>();

// Normalize items to PillItem[]
const normalizedItems = computed((): PillItem[] => {
  return props.items;
});

// Get all keys in order
const allKeys = computed(() => normalizedItems.value.map(item => item.key));

const inputId = props.id ?? `pill_${Math.random().toString(16).slice(2)}`;
const describedBy = computed(() => {
  const ids: string[] = [];
  if (props.hint) ids.push(`${inputId}_hint`);
  if (props.error) ids.push(`${inputId}_err`);
  return ids.length ? ids.join(' ') : undefined;
});

const disabledSet = computed(() => new Set((props.disabledItems ?? []).map(s => String(s))));
function isDisabledItem(key: string){ return props.disabled || disabledSet.value.has(key); }

const selectedSet = ref(new Set<string>(props.modelValue ?? []));
watch(() => props.modelValue, (v) => {
  selectedSet.value = new Set(v ?? []);
});

function toggle(key: string){
  if (isDisabledItem(key)) return;
  const s = selectedSet.value;
  if (s.has(key)) s.delete(key);
  else s.add(key);
  // Return keys in the same order as allKeys
  const out = allKeys.value.filter(k => s.has(k));
  emit('update:modelValue', out.length ? out : null);
}

// refs to buttons for keyboard nav
const btnRefs = ref<Array<HTMLButtonElement | null>>([]);
function setBtnRef(el: HTMLButtonElement | null, idx: number){ btnRefs.value[idx] = el; }

function focusIdx(idx: number){
  const refEl = btnRefs.value[idx];
  refEl?.focus();
}

function onBtnKeydown(e: KeyboardEvent, idx: number){
  if (e.key === 'ArrowLeft' || e.key === 'ArrowUp'){
    e.preventDefault();
    const prev = (idx - 1 + allKeys.value.length) % allKeys.value.length;
    focusIdx(prev);
  } else if (e.key === 'ArrowRight' || e.key === 'ArrowDown'){
    e.preventDefault();
    const next = (idx + 1) % allKeys.value.length;
    focusIdx(next);
  } else if (e.key === ' ' || e.key === 'Enter'){
    e.preventDefault();
    const key = allKeys.value[idx];
    if (!isDisabledItem(key)) toggle(key);
  }
}

function isSelected(key: string){ return selectedSet.value.has(key); }

function baseBtnClass(){
  return 'px-3 py-1 rounded-full text-sm font-medium focus:outline-none transition border';
}

</script>

<template>
  <div class="space-y-1.5 flex flex-col">
    <label v-if="props.label" class="text-sm font-medium text-foreground text-left pl-0" :for="inputId">
      {{ props.label }}
    </label>

    <div :class="['flex items-center gap-2', props.class]" role="group" :aria-label="props.ariaLabel ?? props.label ?? 'Wybierz'" :aria-describedby="describedBy">
      <div class="flex gap-2" aria-hidden="false">
        <button
          v-for="(item, i) in normalizedItems"
          :key="item.key"
          :ref="(el) => setBtnRef(el, i)"
          type="button"
          :disabled="isDisabledItem(item.key)"
          :aria-disabled="isDisabledItem(item.key) ? 'true' : undefined"
          :class="[ 
            baseBtnClass(),
            isSelected(item.key) 
              ? 'bg-primary text-white hover:bg-primary/70' 
              : 'bg-card border border-border text-foreground',
            !isDisabledItem(item.key) && !isSelected(item.key)
              ? 'hover:bg-primary/20 hover:border-primary/30'
              : '',
            isDisabledItem(item.key) 
              ? 'cursor-not-allowed opacity-60' 
              : 'cursor-pointer' 
          ]"
          :aria-pressed="isSelected(item.key)"
          @click="toggle(item.key)"
          @keydown="(e) => onBtnKeydown(e, i)"
        >
          <span
            :class="isSelected(item.key) ? 'text-white' : (isDisabledItem(item.key) ? 'text-muted-foreground' : 'text-foreground')">
            {{ item.label }}
          </span>
        </button>
      </div>
    </div>

    <p v-if="props.hint" :id="`${inputId}_hint`" class="text-xs text-muted-foreground text-left pl-0">
      {{ props.hint }}
    </p>
    <p v-if="props.error" :id="`${inputId}_err`" class="text-xs text-danger text-left pl-0">
      {{ props.error }}
    </p>
  </div>
</template>
