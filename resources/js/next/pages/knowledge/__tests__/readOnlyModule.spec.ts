// @vitest-environment happy-dom
// readOnlyModule.spec — the module's authorship boundary, pinned NEGATIVELY.
//
// An entry and a relation are written by the composer and by nothing else. A person approves,
// refuses, or steers the prompt; they do not author. Every surface that let them author has been
// removed, and the removals are the kind that get undone by accident — a route re-added "because
// something links to it", a store action restored "for a test", a pencil button copied from
// another module's list. Positive tests cannot catch any of that: absence is not something a
// passing test notices.
//
// So these assert the ABSENCE, and they are worth more than the code they guard.
import { describe, it, expect } from 'vitest';
// The router INSTANCE, not the raw table: `routes` is module-private, and asking the real router
// for its resolved names is the closer question anyway — a route reachable at runtime is what
// matters, not one written in a literal.
import { router } from '../../../app/router/index';
import { useKnowledgeStore } from '../../../app/stores/knowledge';
import { createPinia, setActivePinia } from 'pinia';

/** Every route name the router can actually resolve. */
function routeNames(): string[] {
  return router.getRoutes().map((route) => String(route.name ?? ''));
}

describe('there is no way to author an entry', () => {
  it('declares no entry-editor route', () => {
    // The editor was a full route with its own URL, so a stale bookmark is the likeliest way back.
    expect(routeNames()).not.toContain('next.knowledge.base.edit');
  });

  it('declares no entry-trash route', () => {
    // Cleaning up is the agent's job or a GDPR command's; it is not a screen.
    expect(routeNames()).not.toContain('next.knowledge.trash');
  });

  it('exposes no entry WRITE action on the store', () => {
    setActivePinia(createPinia());
    const store = useKnowledgeStore() as unknown as Record<string, unknown>;

    // `reorderEntries` is here with the rest on purpose: `position` is the base's canonical order,
    // so rearranging it is editorial control over the base's structure even though it changes no
    // text. The backend withdrew the endpoint for exactly that reason.
    for (const action of [
      'createEntry',
      'updateEntry',
      'deleteEntry',
      'restoreEntry',
      'purgeEntry',
      'reorderEntries',
      'restoreRevision',
    ]) {
      expect(store[action], `store.${action} must not exist`).toBeUndefined();
    }
  });

  it('exposes no relation WRITE action on the store', () => {
    setActivePinia(createPinia());
    const store = useKnowledgeStore() as unknown as Record<string, unknown>;

    for (const action of ['createRelation', 'updateRelation', 'endRelation', 'deleteRelation']) {
      expect(store[action], `store.${action} must not exist`).toBeUndefined();
    }
  });

  it('KEEPS the actions that approve or refuse rather than author', () => {
    // The counterpart, and the reason this file is not simply a list of deletions: refusing a
    // draft, dismissing a machine guess and re-queuing a failed index are all still a person's to
    // do. A later cleanup that took these too would have gone past the boundary, not up to it.
    setActivePinia(createPinia());
    const store = useKnowledgeStore() as unknown as Record<string, unknown>;

    for (const action of ['acceptDrafts', 'rejectDraft', 'dismissLink', 'undismissLink', 'retryIndex']) {
      expect(typeof store[action], `store.${action} must survive`).toBe('function');
    }
  });

  // --- WHAT MUST SURVIVE THE NEXT CLEANUP ------------------------------------
  //
  // The entry trash went on purpose; the BASE trash went by accident, riding along in the same
  // deleted file. The result was a base you could throw away and not get back. Bases were never
  // covered by the ban — the owner withdrew manual authorship of ENTRIES and RELATIONS, while a
  // base keeps its whole life cycle and its charter is required by the GDPR procedure.
  //
  // These two assertions are a pair on purpose: one says the entry trash is gone, the other says
  // the base recovery is not. Apart, either could be satisfied by deleting too much.

  it('exposes base RESTORE and PURGE, which the trash removal took with it once', () => {
    setActivePinia(createPinia());
    const store = useKnowledgeStore() as unknown as Record<string, unknown>;

    expect(typeof store.restoreBase, 'store.restoreBase must survive').toBe('function');
    expect(typeof store.forceDeleteBase, 'store.forceDeleteBase must survive').toBe('function');
  });

  it('offers NO entry-level restore alongside it', () => {
    // The distinction the pair exists to hold: recovering a base is not recovering an entry.
    setActivePinia(createPinia());
    const store = useKnowledgeStore() as unknown as Record<string, unknown>;

    expect(store.restoreEntry).toBeUndefined();
    expect(store.purgeEntry).toBeUndefined();
  });
});
