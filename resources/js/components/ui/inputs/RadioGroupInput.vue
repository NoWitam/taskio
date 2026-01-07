<script setup lang="ts">
    import { computed, ref } from "vue";
    import { cn } from "@/lib/helpers";

    type OptionValue = string | number;

    export type RadioOption = {
        value: OptionValue;
        label: string;
        description?: string;
        disabled?: boolean;
    };

    const props = withDefaults(
        defineProps<{
            modelValue: OptionValue | null;
            options: RadioOption[];
            id?: string;
            name?: string;
            label?: string;
            hint?: string;
            error?: string;
            disabled?: boolean;
            orientation?: "vertical" | "horizontal";
            class?: string;
        }>(),
        { disabled: false, orientation: "vertical" }
    );

    const emit = defineEmits<{
        (e: "update:modelValue", value: OptionValue): void;
    }>();

    const groupId = props.id ?? `rg_${Math.random().toString(16).slice(2)}`;
    const itemRefs = ref<Array<HTMLButtonElement | null>>([]);

    const describedBy = computed(() => {
        const ids: string[] = [];
        if (props.hint) ids.push(`${groupId}_hint`);
        if (props.error) ids.push(`${groupId}_err`);
        return ids.length ? ids.join(" ") : undefined;
    });

    const enabledIndexes = computed(() =>
        props.options
            .map((o, idx) => ({ o, idx }))
            .filter(({ o }) => !o.disabled && !props.disabled)
            .map(({ idx }) => idx)
    );

    const selectedIndex = computed(() =>
        props.options.findIndex((o) => o.value === props.modelValue)
    );

    function setRef(el: HTMLButtonElement | null, idx: number) {
        itemRefs.value[idx] = el;
    }

    function focusIndex(idx: number) {
        itemRefs.value[idx]?.focus();
    }

    function firstEnabled() {
        return enabledIndexes.value[0] ?? -1;
    }

    function lastEnabled() {
        const a = enabledIndexes.value;
        return a.length ? a[a.length - 1] : -1;
    }

    function nextEnabled(from: number) {
        const enabled = enabledIndexes.value;
        if (!enabled.length) return from;
        const pos = enabled.indexOf(from);
        const nextPos = pos >= 0 ? (pos + 1) % enabled.length : 0;
        return enabled[nextPos];
    }

    function prevEnabled(from: number) {
        const enabled = enabledIndexes.value;
        if (!enabled.length) return from;
        const pos = enabled.indexOf(from);
        const prevPos = pos >= 0 ? (pos - 1 + enabled.length) % enabled.length : enabled.length - 1;
        return enabled[prevPos];
    }

    function select(idx: number, focus = false) {
        const opt = props.options[idx];
        if (!opt || opt.disabled || props.disabled) return;
        emit("update:modelValue", opt.value);
        if (focus) focusIndex(idx);
    }

    function onKeyDown(e: KeyboardEvent) {
        if (props.disabled) return;

        const cur = selectedIndex.value >= 0 ? selectedIndex.value : firstEnabled();
        if (cur < 0) return;

        switch (e.key) {
            case "ArrowRight":
            case "ArrowDown":
            e.preventDefault();
            select(nextEnabled(cur), true);
            break;

            case "ArrowLeft":
            case "ArrowUp":
            e.preventDefault();
            select(prevEnabled(cur), true);
            break;

            case "Home":
            e.preventDefault();
            select(firstEnabled(), true);
            break;

            case "End":
            e.preventDefault();
            select(lastEnabled(), true);
            break;

            case " ":
            case "Enter":
            e.preventDefault();
            select(cur, true);
            break;
        }
    }

    const layoutCls = computed(() =>
        props.orientation === "horizontal"
            ? "flex flex-wrap items-start gap-3"
            : "flex flex-col gap-2"
    );

    function isChecked(v: OptionValue) {
        return v === props.modelValue;
    }
</script>

<template>
  <div class="space-y-1.5 flex flex-col">
    <label v-if="label" class="text-sm font-medium text-foreground text-left pl-0">
      {{ label }}
    </label>

    <div
      :id="groupId"
      role="radiogroup"
      :aria-disabled="disabled || undefined"
      :aria-invalid="!!error || undefined"
      :aria-describedby="describedBy"
      :class="cn(layoutCls, props.class)"
      @keydown="onKeyDown"
    >
      <button
        v-for="(opt, idx) in options"
        :key="String(opt.value)"
        :ref="(el) => setRef(el as HTMLButtonElement | null, idx)"
        type="button"
        role="radio"
        :name="name"
        :aria-checked="isChecked(opt.value)"
        :aria-disabled="(disabled || opt.disabled) || undefined"
        :tabindex="isChecked(opt.value) ? 0 : -1"
        :disabled="disabled || opt.disabled"
        @click="select(idx)"
        :class="cn(
          'group w-full text-left rounded-xl border bg-card p-3 transition',
          'border-border hover:border-border/80',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:border-primary/40',
          isChecked(opt.value) && 'border-primary/40 ring-2 ring-primary/20',
          (disabled || opt.disabled) && 'opacity-60 cursor-not-allowed',
          error && 'border-danger focus-visible:ring-danger/25 focus-visible:border-danger'
        )"
      >
        <div class="flex items-start gap-3">
          <!-- dot -->
          <span
            class="mt-0.5 inline-flex h-5 w-5 items-center justify-center rounded-full border bg-background transition"
            :class="cn(
              'border-border group-hover:border-border/80',
              isChecked(opt.value) && 'border-primary bg-primary/10'
            )"
            aria-hidden="true"
          >
            <span
              v-if="isChecked(opt.value)"
              class="h-2.5 w-2.5 rounded-full bg-primary"
            />
          </span>

          <div class="min-w-0">
            <div class="text-sm font-semibold text-foreground">
              {{ opt.label }}
            </div>
            <div v-if="opt.description" class="mt-0.5 text-sm text-muted-foreground">
              {{ opt.description }}
            </div>
          </div>
        </div>
      </button>
    </div>

    <p v-if="hint" :id="`${groupId}_hint`" class="text-xs text-muted-foreground text-left pl-0">
      {{ hint }}
    </p>
    <p v-if="error" :id="`${groupId}_err`" class="text-xs text-danger text-left pl-0">
      {{ error }}
    </p>
  </div>
</template>
