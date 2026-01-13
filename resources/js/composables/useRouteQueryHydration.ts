import { watch, type Ref } from 'vue';
import { useRoute, type LocationQuery } from 'vue-router';

/**
 * Reużywalny watcher: hydratuje stan z route.query.
 * - Nie narzuca strategii merge (to robi `apply`).
 * - Watch jest deep + immediate (domyślnie), bo query bywa obiektem z tablicami.
 */
export function useRouteQueryHydration(
  apply: (query: LocationQuery) => void | Promise<void>,
  options: { immediate?: boolean; deep?: boolean } = {}
) {
  const route = useRoute();

  watch(
    () => route.query,
    (q) => {
      void apply(q);
    },
    {
      immediate: options.immediate ?? true,
      deep: options.deep ?? true,
    }
  );

  return { route };
}
