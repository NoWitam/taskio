# HANDOFF: Ujednolicenie nagłówków stron i sub-nawigacji („next") — kontynuacja sesji

> Dokument przekazania dla Claude Code na innym urządzeniu. Poprzednia sesja (2026-07-10)
> przeprowadziła audyt, planowanie z bramką recenzenta i zrealizowała 3 z 8 batchy.
> Przeczytaj CAŁY ten plik przed jakąkolwiek zmianą. Working tree jest jedynym nośnikiem
> stanu — NIC z tej pracy nie jest zacommitowane.

> **AKTUALIZACJA 2026-07-10 (późniejsza sesja, na wyraźną prośbę usera przed przesiadką
> na inne urządzenie):** powyższe zdanie o working tree jest już NIEAKTUALNE. Całe drzewo
> robocze zostało zacommitowane i wypchnięte na `origin/refactor/claude-init`, żeby nic
> nie zostało tylko na jednej maszynie. Zmiany batchy 1–3 tego refactoru weszły do commita
> `a0c0405` (feat(workflows): next frontend …) — są ZMIESZANE z pracą nad modułem Workflows,
> bo dzieliły pliki w working tree i nie dało się ich bezpiecznie rozdzielić po hunkach.
> **Nie wykonuj batchy 1–3 ponownie** — ich stan jest w HEAD. Czysty `git status` NIE
> oznacza utraty pracy. Zasada „nie commituj bez wyraźnej prośby usera" obowiązuje nadal
> dla dalszych batchy.

## 0. Krytyczne zasady tej pracy

- **NIE COMMITUJ NICZEGO bez wyraźnej prośby usera.** Na branchu `refactor/claude-init`
  leży cały nieskomitowany Etap 5/5.1 (moduł Workflows) ORAZ batche tego refactoru.
  User świadomie pominął „Batch 0" (commit Etapu 5.1 przed startem) — commit teraz
  zagarnąłby obie prace naraz.
- Zero zmian backendu — to pure-frontend refactor.
- **W working tree jest też INNA nieskomitowana praca równoległa** (harmonogram 16 rodzin +
  wizard 3 kroków edytora workflow + przebudowa SegmentedControl→selection cards; user
  poprawia kroki wizardu po kolei). NIE dotykaj plików `WorkflowEditorDrawer.vue`,
  `WorkflowScheduleBuilder.vue`, `WorkflowScheduleAssist.vue`, `SegmentedControl.vue` ani
  niczego poza zakresem batchy z tego dokumentu — ryzyko nadpisania cudzej pracy w toku.
- Każdy nowy tekst user-facing przez `t()` + klucze w `resources/js/next/app/i18n/{en,pl}.ts`.
- Akcje tylko przez prymityw `Button` (nigdy surowy `<button>`); skeletony imitują docelowy
  element; każda lista ma FilterBar z Saved Views FilterTabBar w `#top` (nie ruszać).
- Minimalne diffy, styl komentarzy angielski jak w istniejących docblokach, zero nowych zależności.
- Testy/build w WSL (dystrybucja `Ubuntu`, user `postgres`, repo `~/taskio`, Node 22 przez nvm):
  ```
  wsl.exe -d Ubuntu -u postgres bash -lc "source ~/.nvm/nvm.sh && nvm use 22 >/dev/null 2>&1 && cd ~/taskio && npx vitest run <ścieżka-spec>"
  wsl.exe -d Ubuntu -u postgres bash -lc "source ~/.nvm/nvm.sh && nvm use 22 >/dev/null 2>&1 && cd ~/taskio && npm run test:unit"
  ```
  (Jeśli nowa sesja działa bezpośrednio w WSL/Linuksie — po prostu `nvm use 22 && npm run test:unit`.)

## 1. Skąd się to wzięło (audyt + decyzje usera)

Audyt spójności (8 agentów, 100% pokrycia routera `resources/js/next/app/router/index.ts`)
wykazał 4 warianty nagłówka strony i 5 dialektów sub-nawigacji. Wizualne porównanie:
https://claude.ai/code/artifact/a3ac5cac-182a-41f9-9562-fdcdd6a548fc

Najważniejsze fakty audytu (zweryfikowane w kodzie):
- Kanoniczny `PageHeader` (`resources/js/next/ui/patterns/PageHeader.vue`) używany na 8 stronach
  list; jego slot `#tabs` i prop `breadcrumbs` — zero użyć w aplikacji.
- Forms sub-widoki (Submissions/Reports/Preview) mają ręczne małe nagłówki (h1 `text-next-xl`, bez ikony).
- Detale Bota/Workflowa NIE mają nagłówka — tożsamość (ikona+nazwa+StatusBadge) żyje tylko
  w aside layoutu modułu, a aside jest `hidden` poniżej `next-lg` BEZ zamiennika (mobile: brak
  tytułu i nawigacji sekcji). Skeletony imitują nagłówek, którego nie ma.
- 4 pliki `*ModuleLayout.vue` (forms/approvals/bots/workflows) to copy-paste near-duplikaty;
  3 różne mechanizmy syncu sekcji (Forms child routes; Bots/Workflows `?section=`; Approvals statyczne).
- Navbar renderuje drugie `h1` z `route.meta.titleKey` (duplikat z PageHeaderem stron).
- W jasnym motywie `--color-next-accent` był identyczny z `--color-next-primary-subtle`
  (hover nieaktywnego wyglądał jak aktywny) — JUŻ NAPRAWIONE w Batch 1.
- Globalny sidebar gubił active na child routes (`route.path ===`) — JUŻ NAPRAWIONE w Batch 5.

**Decyzje usera (NIENARUSZALNE):**
- **D1=A:** KAŻDA routowana strona dostaje PageHeader; na detalach tytuł = NAZWA elementu
  (ikona elementu + StatusBadge + akcje), na pod-widokach wariant pomniejszony.
- **D2=B:** aside modułu ZOSTAJE na ≥`next-lg`, wyciągnięty do wspólnych komponentów;
  poniżej `next-lg` fallback = taby.
- **D3:** subtelna tinta/underline = NAWIGACJA; lity pill = FILTR zakresu danych;
  tokeny accent/primary-subtle rozdzielone w light (zrobione).
- **D4=A:** child routes wszędzie; sidebar prefix-match (zrobione); `?tab=` sync (zrobione).
- **D5=A:** Navbar przestaje renderować h1 — breadcrumb/kontekst (nie-h1).
- **D6=B:** docs tylko: waga h1 (StoryPage/TokensPage) + deep-linki StyleguideView.
- Dodatkowe rozstrzygnięcia usera: redirecty starych `?section=` = TAK (z zachowaniem całego
  query); Batch 8 (kontenery/rytm) = TAK; commit Etapu 5.1 przed startem = POMINIĘTY (patrz §0).

## 2. Stan wykonania (co JUŻ jest w working tree)

Baza przed refaktorem: 73 pliki / 674 testy zielone (łącznie z testami Etapu 5.1).
Po batchach 1/5/7: **74 pliki / 674+ testów zielonych** (pełny `npm run test:unit` przeszedł;
dokładnie: 73 pliki przed dodaniem PageHeader.spec — po nim 74; wszystkie zielone).

### Batch 1 — GOTOWE (tokeny + PageHeader API)
- `resources/css/next.css` (light): `--color-next-accent: hsl(320 12% 94%)` (= muted, neutralny
  hover), `--color-next-accent-foreground: hsl(322 12% 12%)` (= fg). Dark NIE ruszany (celowo, wg planu).
- `resources/js/next/ui/patterns/PageHeader.vue`: addytywnie prop `size?: 'md'|'sm'` (sm: bąbelek
  `h-9 w-9` + Icon `text-next-lg`, h1 `text-next-xl` bez skoku responsywnego, gap `gap-next-2`)
  + slot `#meta` renderowany obok headingu (POZA h1 — heading zostaje czysto tekstowy).
- `resources/js/next/docs/pages/PageHeaderPage.vue`: przykłady `size="sm"` + `#meta` (StatusBadge), tabele API.
- NOWY `resources/js/next/ui/patterns/__tests__/PageHeader.spec.ts` (5 testów).

### Batch 5 — GOTOWE (sidebar prefix-match)
- NOWY `resources/js/next/app/router/isPathActive.ts` (czysta funkcja; guard: `/formsX` nie
  matchuje `/forms`; `to === '/'` tylko exact) + spec `__tests__/isPathActive.spec.ts` (6 testów).
- `resources/js/next/pages/AppLayout.vue`: `:active="item.to != null && isPathActive(route.path, item.to)"`.

### Batch 7 — GOTOWE (?tab= sync w Zgłoszeniach/Raportach)
- NOWY `resources/js/next/pages/forms/tabQuery.ts` (`hydrateTab`, `serializeTabQuery` — kopia
  `{...route.query}`, ustawia/usuwa TYLKO `tab`, default 'active' pomijany) + spec
  `__tests__/tabQuery.spec.ts` (5 testów).
- `FormSubmissionsView.vue` + `FormReportsView.vue`: hydratacja w `onMounted` PRZED refetch
  (flaga `hydrating` — bez podwójnych fetchy), `watch(tab) → router.replace`.

### Batch 3 — NIE ZACZĘTY (agent padł na limicie sesji, zero zmian w plikach — zweryfikowane)

## 3. Kolejka pracy (kolejność z zaakceptowanego planu v2)

**3 → review → 4+6 (ATOMOWO) → review → 2 → 8 → docs/ADR-0011 → walidacja końcowa.**
Batche 5 i 7 już wykonane (były niezależne). Po batchach 3 i 4+6 — bramka reviewer-agenta
(diff, regresja tras/testów/a11y: jeden h1/strona, aria-current, query w redirectach).

---

### NASTĘPNY KROK: Batch 3 — migracja `?section=` → child routes (NAJWYŻSZE RYZYKO)

Konsumenci `?section=` (policzeni grep-em, kompletna lista):
- `resources/js/next/app/router/index.ts` — rekordy `next.bots.detail` (~linia 141),
  `next.workflows.detail` (~163, w nieskomitowanym bloku Etapu 5.1).
- `resources/js/next/pages/workflows/WorkflowsModuleLayout.vue` — linie ~51 (workflowId z
  `route.name ===`), ~57 (currentSection z query), ~74-81 (sectionLink), docblock ~14.
- `resources/js/next/pages/bots/BotsModuleLayout.vue` — linie ~47, ~76 (analogiczny wzorzec).
- `resources/js/next/pages/workflows/WorkflowDetailView.vue` — linie ~69-71 (computed sekcji),
  ~271-273 (goToRuns), docblock ~5.
- `resources/js/next/pages/bots/BotDetailView.vue` — computed `route.query.section || 'inbox'`.
- `resources/js/next/pages/workflows/TargetPickerModal.vue` — linia ~114
  (`str(route.query.section) === 'runs'` → refetch runs).
- Pushe po nazwie (dalej mają działać przez named redirect record): `BotsView.vue:309`,
  `WorkflowsView.vue:347`.
- Testy: `workflows/__tests__/WorkflowDetailView.spec.ts` (mock `useRoute` BEZ `name` —
  linie ~40-44, 84; przepisać WSZYSTKIE mounty), `workflows/__tests__/TargetPickerModal.spec.ts`.
- `docs/registry.ts` ma niezwiązane `section:` (taksonomia styleguide) — NIE ruszać.

Specyfikacja implementacji:

1. **NOWY `resources/js/next/app/router/sectionRedirect.ts`** — czysta funkcja
   `sectionRedirect(to, namePrefix, sections, fallback)` → `{ name, params, query }`:
   czyta `to.query.section` (uwaga na tablice — pierwszy element); wartość ∈ sections →
   `namePrefix + wartość`, inaczej `namePrefix + fallback`; query = `{...to.query}` BEZ
   `section` (WSZYSTKIE inne klucze zachowane: run, run_detail, state, origin, bot, workflow,
   fill, edit…); params 1:1. + NOWY spec `__tests__/sectionRedirect.spec.ts`:
   (a) `?section=runs&run_detail=X&state=failed&origin=manual` → name …runs, query zachowuje
   run_detail/state/origin, bez section; (b) brak section → fallback, query nietknięte;
   (c) `section=foo` → fallback + section usunięty; (d) `section=['runs']` → runs; (e) params 1:1.

2. **Router — BOTS** (jeden komponent na wszystkie sekcje, bez zagnieżdżonego RouterView):
   ```
   { path: ':id', children: [
     { path: '', name: 'next.bots.detail', redirect: to => sectionRedirect(to, 'next.bots.detail.', ['inbox','activity','config'], 'inbox') },
     { path: 'inbox',    name: 'next.bots.detail.inbox',    component: BotDetailView },
     { path: 'activity', name: 'next.bots.detail.activity', component: BotDetailView },
     { path: 'config',   name: 'next.bots.detail.config',   component: BotDetailView },
   ]}
   ```
   Rekord `:id` BEZ component (dzieci renderują się w RouterView layoutu modułu). Nazwa
   `next.bots.detail` na rekordzie redirectu — istniejące pushe po nazwie lądują na inbox.
   UWAGA: sibling-rekordy z tym samym komponentem → Vue reuse'uje instancję (bez remount);
   zweryfikować, że BotDetailView nie robi fetchu zależnego od sekcji w onMounted (nie robi —
   sekcja była query).

3. **Router — WORKFLOWS** (zagnieżdżony RouterView; Runs ma własny lifecycle):
   ```
   { path: ':id', component: WorkflowDetailView, children: [
     { path: '', name: 'next.workflows.detail', redirect: to => sectionRedirect(to, 'next.workflows.detail.', ['overview','runs'], 'overview') },
     { path: 'overview', name: 'next.workflows.detail.overview', component: WorkflowOverviewView (NOWY) },
     { path: 'runs',     name: 'next.workflows.detail.runs',     component: WorkflowRunsSection (NOWY wrapper) },
   ]}
   ```

4. **`WorkflowDetailView.vue`** → SHELL: usunąć computed sekcji + v-if Overview/Runs; panele
   Overview (Status/Trigger/Conditions/Steps) przenieść VERBATIM do NOWEGO
   `resources/js/next/pages/workflows/WorkflowOverviewView.vue` (workflow ze store po
   `route.params.id` albo provide z shellu — mniejszy diff, decyzję opisać); przycisk
   „View runs" (goToRuns) → `router.push({ name: 'next.workflows.detail.runs', params: { id }, query: { ...route.query } })`.
   Shell zachowuje: fetch/loading/error + action bar (bez zmian wizualnych — PageHeader to
   Batch 4+6!) + `<RouterView />`.

5. **`WorkflowRunsView.vue`** — NIE zmieniać API (prop `workflowId` ~linia 42; run-detail
   Drawer przez `?run_detail=` ~14-16, 53-59; onUnmounted cleanup). NOWY cienki adapter
   `resources/js/next/pages/workflows/WorkflowRunsSection.vue`: czyta `route.params.id` i
   renderuje `<WorkflowRunsView :workflow-id="String(route.params.id)" />`.

6. **Layouty:** w obu — workflowId/botId po prefiksie `String(route.name).startsWith('next.X.detail')`;
   currentSection z `route.name` (suffiks, default overview/inbox); `sectionLink(section)` →
   `{ name: 'next.X.detail.' + section, params: { id }, query: { ...route.query } }` (defensywnie
   nie kopiować klucza section). Docblocki update.

7. **`TargetPickerModal.vue:114`:** `route.name === 'next.workflows.detail.runs'` (reszta warunku
   — `runsStore.workflowId === workflow.value.id` — bez zmian).

8. **Testy:** przepisać WSZYSTKIE mounty w `WorkflowDetailView.spec.ts` (mock route z `name` +
   params + query; asercje Overview przenieść na bezpośredni mount WorkflowOverviewView LUB
   shell z lokalnym routerem; żadnej asercji nie usuwać bez raportu). Zaktualizować
   `TargetPickerModal.spec.ts` (name zamiast query.section). NOWY spec botów (dziś ŻADEN nie
   istnieje): `bots/__tests__/botDetailSection.spec.ts` — mapowanie route.name → renderowana
   sekcja. Test napięcia 7: mount WorkflowRunsView z query `{ run_detail: 'X' }` → drawer
   otwarty; unmount nie rzuca. Spec sectionRedirect jak w pkt 1. Na końcu PEŁNY `npm run test:unit`.

9. **Sweep:** grep `query.section` / `'?section'` po `resources/js/next` — raport każdego miejsca.

---

### Batch 4+6 — SCALONE ATOMOWO (po Batch 3 + review)

(Uzasadnienie scalenia: osobno powstaje okno podwójnej lub zerowej tożsamości.)

- NOWE `resources/js/next/ui/layout/ModuleAside.vue`: props `mode: 'list'|'detail'`, `backLink?`,
  `items`, `activeMatch`. Detail-mode: back-link + nav — **BEZ bloku tożsamości** (rozstrzygnięcie
  napięcia 1: tożsamość żyje raz, w PageHeaderze; StatusBadge w dwóch miejscach = rozjazd).
  List-mode: module-header + nav. Style: aktywny `bg-next-primary-subtle
  text-next-primary-subtle-foreground font-next-medium`, nieaktywny `text-next-fg
  hover:bg-next-accent hover:text-next-accent-foreground` (tokeny już rozdzielone).
- NOWE `resources/js/next/ui/layout/ModuleTabs.vue`: `Tabs variant="underline"`, `next-lg:hidden`,
  te same items co aside, sync przez routę. Renderowany w layoutach NAD `<RouterView>`
  (rozstrzygnięcie napięcia 2 — NIE slot #tabs PageHeadera).
- Refaktor 4 layoutów (`forms/approvals/bots/workflows`) do konsumpcji obu komponentów; usunąć
  zduplikowany chrome + detail-mode identity block; zostaje per-moduł: warunek trybu (v-if na
  `:id`) + hosting drawerów + query helpery. Warunkowa tinta bąbelka w Forms znika.
- PageHeader na stronach:
  - `BotDetailView.vue`: PageHeader (icon = ikona bota, title = nazwa, `#meta` = StatusBadge,
    `#actions` = back/toggle/edit z obecnego slim action-bara); ustawić `route.meta.contextLabel`
    = nazwa (dla breadcrumba Navbara — Batch 2); skeleton dostosować do realnego nagłówka.
  - `WorkflowDetailView.vue` (shell): analogicznie (akcje run/toggle/edit; skeleton ~300-310).
  - `FormSubmissionsView.vue`: ręczny h1 → PageHeader `size="sm"` icon=`inbox` title=t('forms.submissions.title').
  - `FormReportsView.vue`: PageHeader `size="sm"` icon=`file-text`.
  - `FormPreviewView.vue`: PageHeader `size="sm"` icon=`eye`.
  (Hierarchia tytułów — napięcie 3: na pod-widokach Forms h1 = etykieta SEKCJI, nie nazwa
  formularza; nazwa formularza w back-linku aside + breadcrumbie Navbara. Na detalach
  bota/workflowa h1 = NAZWA elementu. Asymetria zamierzona, wynika z D1.)
- i18n: `common.moduleNav` aria-label (PL+EN).
- Specs: ModuleAside (active state, back-link, mode), ModuleTabs (route sync, hidden ≥lg),
  detale (PageHeader z nazwą + StatusBadge; aside detail-mode NIE renderuje tożsamości),
  Submissions (PageHeader size=sm).

### Batch 2 — Navbar h1 → breadcrumb (PO 4+6!)
- `AppLayout.vue` (~197): zamiast `<h1 pageTitle>` breadcrumb (reużyć
  `ui/navigation/Breadcrumbs.vue`); trail z `route.meta.titleKey` + `route.meta.contextLabel`.
  **BEZ importu feature-store do AppLayout** (importuje tylko auth + approvalQueue — celowo).
- `Navbar.vue`: doc-komentarz. Test: brak h1 w navbarze, obecny `nav aria-label`.

### Batch 8 — kontenery/rytm (ostatni)
- `MembersView.vue`: usunąć `Container as=main` bez flush (double gutter + zagnieżdżony main).
- Forms sub-views `gap-next-5` → `gap-next-6` (builder `gap-next-4` zostaje).
- `WorkflowsView.vue`: drugi opis (Alert info pod PageHeaderem) → decyzja w batchu
  (do `#description` albo zostaje).

### Docs + ADR (przekrojowo, po 4+6)
- Strona docs ModuleAside/ModuleTabs + wpis w `docs/registry.ts`; wzmianka w docs Tabs
  (underline jako module-nav).
- D6-min: `docs/StoryPage.vue` h1 `font-next-semibold` (~linia 14; przez Tailwind preflight h1
  renderuje się dziś wagą 400!) + to samo w ręcznym nagłówku `TokensPage.vue`;
  `StyleguideView.vue`: deep-link sync (`activeId` ↔ URL — dziś czysty ref, refresh resetuje).
- NOWY `docs/decisions/ADR-0011-next-header-subnav-unification.md` — D1–D6 + rozstrzygnięcia
  napięć 1–7 (numery 0008–0010 zajęte).

### Walidacja końcowa (kryteria akceptacji z planu)
- Każda routowana strona = dokładnie JEDEN h1 (PageHeader); Navbar bez h1.
- Detale: h1 = nazwa + ikona elementu + StatusBadge + akcje; aside bez tożsamości.
- Redirect `?section=` zachowuje CAŁE query; deep-link `/workflows/:id/runs?run_detail=X&state=failed`
  renderuje Runs z otwartym drawerem i filtrem; przełączenie Overview↔Runs nie psuje drawera.
- TargetPickerModal refetchuje runs po `route.name`.
- `<lg`: ModuleTabs nad treścią; `≥lg`: ModuleAside.
- `npm run test:unit` w pełni zielony; `npm run build` przechodzi; zero hardcoded stringów.
- Raport zmian dla usera (lista plików + dlaczego + komendy walidacji + luki testowe).

## 4. Kontekst procesowy

- Pamięć poprzedniej sesji (maszyna Windows, katalog `~/.claude/projects/...taskio/memory/`)
  NIE jest dostępna na innym urządzeniu — ten plik jest jedynym pełnym nośnikiem stanu.
  Jeśli na nowej maszynie istnieje memory-dir, załóż wpis o tej pracy na bazie tego pliku.
- Gotcha z poprzednich etapów: `orchestrator-agent` w tym repo bywał bezużyteczny — orkiestruj
  bezpośrednio; agenci FE potrafią wymyślać klucze payloadów — dawaj precyzyjne briefy
  i weryfikuj diffy samodzielnie.
- Po batchach 3 i 4+6: bramka reviewer-agenta na diffie (trasy, query w redirectach, a11y:
  jeden h1, aria-current).
- Ten plik (docs/ai/handoff-header-subnav-2026-07-10.md) usuń z working tree na koniec pracy
  (przed finalnym raportem) — to dokument tymczasowy, nie dokumentacja projektu; jego treść
  merytoryczną przejmie ADR-0011.
