// withSetup — run a composable inside a real Vue component instance so lifecycle
// hooks (onMounted / onBeforeUnmount / onScopeDispose) and effect scope behave
// exactly as in production. Returns the composable result plus an `unmount()` to
// trigger teardown.
//
// Used by the composable specs that rely on onMounted (useChipOverflow) and
// scope disposal (useDebounce).
import { createApp, type App } from 'vue';

export interface SetupResult<T> {
  result: T;
  app: App;
  unmount: () => void;
}

export function withSetup<T>(composable: () => T): SetupResult<T> {
  let result!: T;
  const app = createApp({
    setup() {
      result = composable();
      return () => null;
    },
  });
  const root = document.createElement('div');
  app.mount(root);
  return {
    result,
    app,
    unmount: () => app.unmount(),
  };
}
