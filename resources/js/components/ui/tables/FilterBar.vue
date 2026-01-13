<script setup lang="ts">
  import { computed, ref, watch } from "vue";
  import { useRoute, useRouter, type LocationQueryRaw } from "vue-router";
    import { cn } from "@/lib/helpers";
    import Badge from "@/components/ui/Badge.vue";
    import Button from "@/components/ui/Button.vue";

    export type ActiveFilter = {
        key: string;
        label: string;
    };

    const props = withDefaults(
        defineProps<{
            active: ActiveFilter[];
        urlable?: boolean;
        /**
         * Query params do synchronizacji (np. { q: 'design', status: 'low', labels: ['id1','id2'] }).
         * Puste wartości (null/undefined/''/[]) są usuwane z URL.
         */
        urlQuery?: LocationQueryRaw;
        /**
         * Opcjonalna lista kluczy które komponent ma traktować jako "zarządzane".
         * Przydatne, gdy chcesz usuwać parametry nawet jeśli urlQuery chwilowo ich nie zawiera.
         */
        urlKeys?: string[];
            title?: string;
            clearLabel?: string;
            class?: string;
        }>(),
        {
        urlable: false,
            title: "Filtry",
            clearLabel: "Wyczyść",
        }
    );

    const router = useRouter();
    const route = useRoute();

    const managedKeys = ref<Set<string>>(new Set());

    function replaceQuery(nextQuery: LocationQueryRaw) {
      return router.replace({ query: nextQuery });
    }

    function isEmptyQueryValue(v: unknown) {
      if (v === undefined || v === null) return true;
      if (typeof v === "string" && v.trim() === "") return true;
      if (Array.isArray(v) && v.length === 0) return true;
      return false;
    }

    function updateManagedKeys() {
      const keysFromProps = Array.isArray(props.urlKeys) ? props.urlKeys.filter(Boolean) : [];
      const keysFromQuery = props.urlQuery ? Object.keys(props.urlQuery) : [];

      if (keysFromProps.length) {
        keysFromProps.forEach((k) => managedKeys.value.add(k));
        return keysFromProps;
      }

      if (keysFromQuery.length) {
        keysFromQuery.forEach((k) => managedKeys.value.add(k));
        return keysFromQuery;
      }

      // Gdy urlQuery jest puste (np. po "Wyczyść"), wciąż chcemy usunąć poprzednio zapisane parametry.
      return Array.from(managedKeys.value);
    }

    function syncUrlQuery() {
      if (!props.urlable) return;

      const keys = updateManagedKeys();
      if (!keys.length) return;

      const nextQuery: LocationQueryRaw = { ...route.query };
      const source = props.urlQuery ?? {};

      keys.forEach((key) => {
        const value = (source as Record<string, unknown>)[key];

        if (isEmptyQueryValue(value)) {
          delete (nextQuery as Record<string, unknown>)[key];
        } else {
          (nextQuery as Record<string, unknown>)[key] = value as any;
        }
      });

      // szybkie porównanie (w praktyce wystarczające)
      if (JSON.stringify(route.query) === JSON.stringify(nextQuery)) return;
      void replaceQuery(nextQuery);
    }

    function removeManagedParamsFromUrl() {
      const keys = Array.from(managedKeys.value);
      if (!keys.length) return;

      const nextQuery: LocationQueryRaw = { ...route.query };
      keys.forEach((key) => {
        delete (nextQuery as Record<string, unknown>)[key];
      });

      if (JSON.stringify(route.query) === JSON.stringify(nextQuery)) return;
      void replaceQuery(nextQuery);
    }

    const emit = defineEmits<{
        (e: "remove", key: string): void;
        (e: "clear"): void;
    }>();

    const hasActive = computed(() => props.active?.length > 0);

    watch(
      () => props.urlQuery,
      () => {
        syncUrlQuery();
      },
      { deep: true, immediate: props.urlable }
    );

    watch(
      () => props.urlable,
      (next, prev) => {
        if (prev && !next) {
          removeManagedParamsFromUrl();
          return;
        }

        if (!prev && next) {
          syncUrlQuery();
        }
      }
    );

    function remove(key: string) {
        emit("remove", key);
    }

    function clearAll() {
        emit("clear");
    }
</script>

<template>
  <section
    :class="cn(
      'rounded-2xl border border-secondary/60 bg-background p-4 shadow-sm',
      props.class
    )"
    aria-label="Pasek filtrów"
  >
    <!-- Góra: kontrolki + akcje -->
    <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
      <div class="min-w-0 flex gap-4 items-center">
        <div class="text-sm font-semibold text-foreground">
          {{ title }}
        </div>

        <slot />
      </div>

      <div v-if="$slots.actions" class="shrink-0">
        <slot name="actions" />
      </div>
    </div>

    <!-- Dół: aktywne filtry -->
    <div class="mt-4 border-t border-secondary/60 pt-3">
      <div class="flex flex-wrap items-center gap-2 min-h-9">
        <template v-if="hasActive">
          <Badge
            v-for="f in active"
            :key="f.key"
            tone="neutral"
            class="gap-1.5 pr-1"
          >
            <span class="max-w-56 truncate">{{ f.label }}</span>

            <button
              type="button"
              class="inline-flex h-5 w-5 items-center justify-center rounded-full border border-secondary/60 bg-background text-foreground/70 transition hover:cursor-pointer hover:bg-secondary/60 hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
              :aria-label="`Usuń filtr: ${f.label}`"
              @click="remove(f.key)"
            >
              ✕
            </button>
          </Badge>

          <Button size="sm" variant="ghost" @click="clearAll">
            {{ clearLabel }}
          </Button>
        </template>

        <template v-else>
          <span class="text-xs font-medium text-foreground/60">Brak aktywnych filtrów</span>
        </template>
      </div>
    </div>
  </section>
</template>
