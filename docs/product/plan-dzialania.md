# Plan działania — od stanu obecnego do pełnej AI Content Platform

> Uzupełnienie dokumentu [wizja-produktu.md](wizja-produktu.md). Stan wyjściowy: Etapy 1–5
> z CHECK LIST zrobione (Tasks, Forms, Bot, Approvals, Workflows 5+5.1 — te ostatnie
> **niezacommitowane**). Plan dzieli dalszą pracę na rozdziały; każdy rozdział przechodzi
> pełną sekwencję: planning-agent → backend → UX/UI → frontend → testy → dokumentacja →
> reviewer-agent.

---

# CZĘŚĆ I — PLAN OGÓLNY

Kolejność wynika z zależności technicznych (strzałki = „potrzebuje"):

```
R0 Stabilizacja
   │
R1 Dysk ◀────────────── Generator musi mieć gdzie zapisywać wyniki
   │
R2 Generator treści + Templatki ◀── serce produktu; największy rozdział
   │
   ├─ Moduł Wiedzy (Knowledge) ◀── wstawiony poza numeracją, decyzja właściciela; MVP+ UKOŃCZONY
   │                               (patrz „Moduł Wiedzy (Knowledge)" w CZĘŚCI II, przed R3)
   │
R3 Kalendarz ◀────────── lekki moduł; workflow-step „wydarzenie"; oddech po R2
   │
R4 Publishing Hub ◀───── publikuje wyniki Generatora; trigger „zatwierdzono"
   │
R5 Kampanie ◀─────────── nakładka na Workflows + Generator + Publishing
   │
R6 Dashboard + Powiadomienia + Analityka ◀── ma już co pokazywać
   │
R7 Custom Quality (sandbox, trening stylu, głos/wygląd bota)
   │
R8 Uniwersum + backlog wizji (Trendy, Streamy, Muzyka, kompozytor…)
```

| Rozdział | Cel biznesowy | Odpowiada etapom z CHECK LIST |
|---|---|---|
| **R0. Stabilizacja i spójność** | Zabezpieczyć zrobioną pracę, domknąć długi, ujednolicić UI | domknięcie Etapów 1–5 |
| **R1. Dysk (Zasoby)** | Centralne repozytorium plików dla całej platformy | Etap 6 |
| **R2. Generator treści + Templatki** | Tworzenie treści AI z szablonów, sesje, style botów | Etap 7 |
| **Moduł Wiedzy (Knowledge)** *(poza numeracją, wstawiony przed R3)* | Wspólna, kuratorowana baza faktów dla wszystkich konsumentów AI (dziś: boty) | brak w pierwotnym CHECK LIST — decyzja właściciela w trakcie R2 |
| **R3. Kalendarz** | Oś czasu: wydarzenia, terminy, krok workflow | Etap 8 |
| **R4. Publishing Hub** | Realna publikacja na platformach + kolejka + historia | Etap 9 |
| **R5. Kampanie** | Automatyczne cykle: „co wtorek nowy post" | Etap 10 |
| **R6. Dashboard, Powiadomienia, Analityka** | Widoczność: co się dzieje, co czeka, co działa | konsolidacja (brainstorm: moduły 1 i 11) |
| **R7. Custom Quality** | Jakość postaci: sandbox, trening, głos/wygląd | Etap 11 (do zaplanowania) |
| **R8. Uniwersum + backlog** | Światy narracyjne; Trendy/Streamy/Muzyka | Etap 12 + backlog |

Zasada rytmu: **jeden rozdział = jedna seria wydawnicza** (plan → build → testy → docs →
review → commit/PR). Bez rozpoczynania kolejnego rozdziału przed review poprzedniego.

---

# CZĘŚĆ II — PLAN SZCZEGÓŁOWY

## R0. Stabilizacja i spójność (najpilniejsze)

**Cel:** zero pracy „w powietrzu", spójny frontend, domknięte znane długi.

1. **Commit + PR Etapów 5/5.1** — cała implementacja Workflows (backend 301 testów,
   FE 623) jest niezacommitowana na branchu `refactor/claude-init`. Podzielić na logiczne
   commity (backend / frontend / docs / testy), wystawić PR. *Ryzyko utraty pracy — punkt
   nr 1 całego planu.*
2. **Znane follow-upy Workflows:** hardening sweepa harmonogramów, kolejność bindingów
   (binding-leak), decyzja o „restored-toast orphan".
3. **Dokończenie migracji Forms → next** (pozostałe batche) i inwentaryzacja starego
   frontendu `resources/js/modules` → plan wygaszenia legacy.
4. **Workspaces Batch 2** — członkowie i zaproszenia (w trakcie) + odłożony frontend auth.
5. **Audyt spójności UI** (skill `design-system-audit`): FilterBar + zapisane widoki na
   każdej liście, Button primitive wszędzie, skeletony zamiast spinnerów, ikony modułów,
   i18n bez hardkodów; follow-upy z Approvals (deep-linki, mobile, N+1).
6. **Zadania — drobne domknięcia z Etapu 1:** endpoint countów + wzorzec repozytorium
   z cache; tab „Checklista" (subtaski) — patrz też R2 (bot rozbija zadanie na kroki).

**Wynik:** czysty main z całością Etapów 1–5, lista długów = 0 lub świadomie odłożona.

### Status R0 (2026-07-16) — ZASADNICZO UKOŃCZONY

- **1. Commit/PR Etapów 5/5.1** ✅ — całość zacommitowana i wypchnięta, draft [PR #8](https://github.com/NoWitam/taskio/pull/8).
- **2. Follow-upy Workflows** ✅ — sweep-hardening (`c49b2b8`, izolacja per-tenant + per-workflow + testy), binding-leak reorder (`60d9096`); „restored-toast" rozstrzygnięty decyzją usera → zbudowano kosz+przywracanie workflowów (`d6a6a38`).
- **3. Migracja Forms → next** ✅ — była już KOMPLETNA (4 batche), nie tylko Batch 1. Wykonano **cutover** (`27293af`): `/` → `/next`, `/app/*` → `/next/*`; oraz **wygaszenie legacy** (`67a4a88`): usunięto 291 plików starego frontendu, vite input = samo `main.ts`, tsc 0 total (54 błędy „baseline" były w legacy).
- **4. Workspaces B2** ✅ — members+invitations były już zrobione i zacommitowane (`7750bc6`). Frontend auth: **decyzja usera — nic w R0** (onboarding invite-only; reset hasła MUSI wrócić ≤ R4). Brak konceptu ról (binarnie owner/member) — „zmiana roli" wymaga osobnej decyzji/modelowania.
- **5. Audyt spójności UI** ✅ — 8-agentowy audyt; unifikacja header/subnav potwierdzona jako wdrożona (ADR-0011). HIGH naprawione (`4d4d961`). Pozycje LOW ocenione jako akceptowalne precedensy (kompozytowe triggery, chip-internal X). Follow-upy Approvals (deep-links, mobile, N+1) → osobny chip.
- **6. Zadania — endpoint countów** ✅ — `GET /tasks/counts` + `TasksRepository` z cache per-workspace + inwalidacja przez `TaskObserver` (`46cbf5d`); kanban badge'e z endpointu; wzorzec do reużycia w R6. **Checklista/subtaski świadomie → R2** (naturalne miejsce: bot rozbija zadanie na kroki).

**Pozostałe decyzje usera do rozstrzygnięcia** poza R0: nazwa produktu; timing Streamy/Muzyka; kampanie jako nakładka na Workflows; zakres Custom Quality; 2 pierwsze platformy publikacji.

---

## R1. Dysk / Zasoby (Etap 6)

**Cel:** pełny menedżer plików — nie równoległy system, tylko **rozszerzenie istniejącego
modułu `Disk`** (model `File`, `HasFiles`, upload tymczasowy już istnieją).

**Backend:** foldery (drzewo per workspace), metadane (nazwa, opis, tagi), typy plików
z miniaturami, historia operacji (reuse Changelog), soft-delete/kosz, lustra tabel tenant,
polityki dostępu.
**UX/frontend:** widok listy/siatki folderów i plików z thumbnailami; upload (drag&drop);
podgląd pliku z tabami: 1) dane (nazwa, opis, tagi, metadane), 2) historia, 3) edytor —
**placeholder** per typ pliku (docelowo edytory AI); FilterBar + zapisane widoki.
**Integracje:** `FileInput` w formularzach dostaje opcję „wybierz z dysku"; załączniki
zadań widoczne jako pliki dysku.
**Workflow:** krok `save_to_disk` / warunki na typ pliku — jeśli tanie, dodać od razu.
**Testy/docs:** feature testy CRUD + uprawnień; `docs/backend/disk-api.md`, strona w docs UI.

**Ryzyka:** rozrost zakresu (edytory plików = osobne projekty — świadomie placeholder);
spójność z istniejącymi załącznikami (migracja danych?).

---

## R2. Generator treści + Templatki (Etap 7) — serce produktu

**Cel:** użytkownik (lub workflow) generuje treść z szablonu; sesja jak w czacie;
wynik da się zapisać na dysku, delegować botowi, podpiąć pod akceptacje.

**Podetapy (osobne PR-y) — numeracja poniżej to REFERENCYJNA numeracja podetapów R2 (wraca
w ADR-ach i w docs/backend/generator-api.md pod nazwą „sub-stage N"), doprecyzowana po
ukończeniu podetapu 1:**
1. **Templatki** ✅ **UKOŃCZONE, w modelu CONTENT-RECIPE** (patrz Status niżej): szablon =
   RECEPTURA na gotowy post — `content_type` (rejestr typów zdefiniowany w kodzie: post /
   post ze zdjęciem / wideo-scenariusz, każdy złożony z typowanych CZĘŚCI) + zadeklarowane
   typowane sloty (bez zmian) + mapa treści per część, autorowana JAKO gotowy post
   (tekst + sloty + pierwszoklasowe bloki `@[ai-text]`; dla obrazu — deklarowany PLAN:
   baza + uporządkowany łańcuch filtrów). *Decyzja projektowa: zmienne szablonu reużywają
   typowanego systemu zmiennych z Workflows 5.1 (katalog po ścieżce) — nie budować
   drugiego; wymagało to najpierw zejścia ze wspólnym silnikiem w dół do modułu
   `Variables`, żeby `Generator` i `Workflows` nie zależały od siebie nawzajem (patrz
   Status → PR-1a).* **Rework (2026-07-27):** pierwotny model „prompt + parametry"
   (pojedynczy `prompt_body` + `type` + `parameters`) został ODRZUCONY przez właściciela
   przed zamknięciem podetapu — nie miał miejsca na plan medialny i traktował „cały post
   przez AI" jako przypadek specjalny zamiast jednego dużego bloku `@[ai-text]`. Zastąpiony
   modelem content-recipe w miejscu (branch niezacommitowany, zero danych) — patrz
   **ADR-0032** (unieważnia zmienione decyzje **ADR-0031**).
2. **Generator (sesje)** ✅ **UKOŃCZONE** (patrz Status niżej): sesja generowania per szablon
   (formularz pod zmienne), historia zmian z możliwością cofnięcia (nie logi!);
   auto-czyszczenie: sesje żyją tydzień → kosz → miesiąc → trwałe usunięcie, archiwizacja
   wyłącza czyszczenie. **Doprecyzowanie sekwencji (po podetapie 1):** to jest pierwszy
   podetap z PRAWDZIWYM wydawaniem AI, więc niósł ze sobą **fundament limitów kosztów AI**
   (przeniesiony tu z dawnego punktu 4 — patrz niżej, zbudowany jako 2a) ORAZ **integrację
   zapisu wyniku na Dysk** (reuse modułu `Disk`/R1, zbudowana jako 2c) — zapis na Dysk był
   integracją WEWNĄTRZ tego podetapu, nie osobnym podetapem/rozdziałem planu.
3. **Boty w generatorze** ✅ **UKOŃCZONE** (patrz Status niżej): delegowanie „niech bot uzupełni
   formularz"; bot jako **autor** (treść generowana w jego stylu — reuse modułu tekstowego bota).
4. ~~Limity kosztów AI~~ — **PRZENIESIONE do podetapu 2** (pierwszy realny wydawca AI —
   podetap 1 nie wykonuje ŻADNEGO prawdziwego wywołania AI: dyrektywa `@[ai-text]` w
   podglądzie szablonu renderuje się jako pusty tekst, celowo).
5. **Workflow:** krok `generate_content` (szablon + mapowanie zmiennych) ✅ **UKOŃCZONE, KOMPLETUJE
   CAŁY ROZDZIAŁ R2** (patrz Status niżej) — od tej chwili workflow potrafi produkować treści.
   Bezcyklowy DZIĘKI podetapowi 1 (PR-1a): `Generator` zależy tylko od `Variables`, nigdy od
   `Workflows`, więc krok `Workflows → Generator` nie tworzy cyklu zależności.
6. **Typy mediów:** start = tekst + obraz; wideo jako scenariusz tekstowy (bez renderu);
   audio/muzyka — decyzja otwarta nr 2 z wizji. Typ szablonu `post_with_image` ma od podetapu 1
   (po rework) pełny, DEKLAROWANY plan obrazu (baza: plik z Dysku / slot plikowy / `ai_generate`
   + uporządkowany łańcuch filtrów pikselowych/AI) — zamodelowany i zwalidowany przy zapisie,
   wiernie podglądany jako streszczenie planu. **WYKONANIE zbudowane w podetapie 2c** ✅:
   rozwiązanie bazy do realnych bajtów (odczyt z Dysku, tenant-scoped) i uruchomienie łańcucha
   (Imagick + `ImageAiService::edit()` dla `ai_edit`) — patrz Status niżej. **Klient text→image
   dla `ai_generate` ZBUDOWANY w podetapie 6** ✅ (`Disk\Services\ImageGenerateService`, metrykowany
   kanał `ai_image_generate`) — baza `ai_generate` jest teraz w pełni RUNNABLE, nie tylko
   zamodelowana. `video_script` (pierwotnie `scene_plan`) przemodelowany na `shot_list` +
   `storyboard` — patrz Status R2 podetap 2 rozszerzenie (Faza A+B, ADR-0035) niżej: `storyboard`
   używa `ai_generate` WEWNĘTRZNIE, per shot, bez autorowanej bazy.

**Ryzyka:** największy rozdział — ciąć na podetapy; koszty API w testach (mock providerów
przez Laravel AI); UX sesji (wzorzec czatu) wymaga solidnego projektu UX przed kodem.

### Status R2 podetap 1 „Templatki" (2026-07-27) — UKOŃCZONY, W MODELU CONTENT-RECIPE (branch, niezacommitowany)

Zbudowane i zielone na branchu `feat/r2-generator-templatki` (backend: ~51 testów Generator +
~828 Workflow + Variables; frontend: ~1537; Pint/build czyste). **Niezacommitowane** — zero
commitów na branchu ponad `main` w chwili pisania (implementacja, testy i dokumentacja gotowe,
czekają na commit/PR).

- **PR-1a (prekursor, czysty refaktor):** wspólny silnik interpolacji przeniesiony w dół, z
  `Workflows` do `Variables` — `WorkflowVariableResolver` → `App\Modules\Variables\Services\
  VariableResolver`; forma-niezależna część katalogu wydzielona do nowego `App\Modules\
  Variables\Services\VariableCatalog`; nowy port `App\Modules\Variables\Contracts\
  AiTextGenerator` (odwrócenie zależności — `WorkflowAiTextService` go implementuje, bindowany
  w module Workflows). Zero zmiany zachowania — test charakteryzacyjny przypina bajt-identyczne
  wyjście przed/po — zero plików frontendu ruszonych. Patrz **ADR-0030**.
- **PR-1b (funkcja, pierwotny model — ODRZUCONY, patrz rework niżej):** nowy moduł
  `App\Modules\Generator` (jednokierunkowo `Generator → Variables`, NIGDY `Workflows` — osobny
  test graniczny, mirror wzorca z modułu `Variables`) + model `Template` w modelu „prompt +
  parametry" (`type` + `prompt_body` + `parameters`). Ten kształt opisuje **ADR-0031** —
  zachowany jako historia, NIE jako aktualny kontrakt.
- **Rework (ten sam podetap, przed pierwszym commitem) → model CONTENT-RECIPE:** `Template`
  przemodelowany w miejscu na `content_type` (rejestr typów **zdefiniowany w kodzie**,
  `ContentTypeRegistry` — 3 typy systemowe: `post`, `post_with_image`, `video_script`, każdy
  złożony z CZĘŚCI o zamkniętym zestawie `kind`: `text_body`/`image_plan`/`script`/`scene_plan`)
  + mapę `content` per część. Treść tekstowa autorowana JAKO gotowy post (pierwszoklasowe bloki
  `@[ai-text]` — „cały post przez AI" to jeden duży blok, nie osobny tryb). Obraz = DEKLAROWANY
  plan: baza (`disk_file` / `from_slot` / `ai_generate` — ten ostatni zamodelowany, ale
  WYŁĄCZONY w edytorze do podetapu 6) + uporządkowany łańcuch filtrów (piksel ∪ `ai_edit`).
  `video_script` rezerwuje TERAZ pełny `scene_plan` (lista scen: narracja + opcjonalny plan
  obrazu). Wierny, PER-CZĘŚĆ podgląd server-side przez TEN SAM silnik (`@[ai-text]` nieaktywny,
  ale OZNACZONY placeholderem `[AI: …]` — bez realnego AI, patrz punkt 4 wyżej). Generator
  celowo ODSPRZĘGNIĘTY od Dysku w tym podetapie (id pliku z Dysku nieprzezroczyste — walidacja
  istnienia dopiero przy wykonaniu, podetap 2). Wspólny, na razie no-op „metered AI call"
  (kontrakt D7) zdefiniowany w module `Variables` — realny licznik kosztów w podetapie 2.
  Endpointy + pełny kontrakt: `docs/backend/generator-api.md`. Patrz **ADR-0032** (unieważnia
  zmienione decyzje **ADR-0031**).

**Świadomie POZA zakresem podetapu 1** (patrz „Doprecyzowanie" wyżej oraz
`docs/backend/generator-api.md` → „Planned / deferred"): sesje generowania, zapis na Dysk,
delegacja do bota, realne wywołania/koszty AI, krok workflow `generate_content`, WYKONANIE planu
obrazu (rozwiązanie bazy + uruchomienie łańcucha filtrów), klient text→image dla `ai_generate`,
walidacja istnienia pliku z Dysku (`disk_file`), typ treści tworzony przez użytkownika.

### Status R2 podetap 2 „Sesje" (2a–2d) — UKOŃCZONY (branch, niezacommitowany)

Zbudowane i zielone na branchu `feat/r2-generator-templatki`, na wierzchu podetapu 1 (backend:
~127 testów Generator + testy `AiCostMeterTest` w module `Variables`; frontend: dodatkowe specy
sesji w `pages/generator/session/**`; Pint/build czyste). **Niezacommitowane** — jak cały branch.
Cztery pod-fazy (2a–2d), każda swój PR-ready krok, razem stanowiące jeden spójny silnik:

- **2a — licznik kosztów AI + zejście generatora tekstu w dół:** seam `MeteredAiCall` (D7,
  zdefiniowany w podetapie 1 jako no-op) podpięty pod prawdziwy licznik-ledger
  (`LedgerMeteredAiCall`) — bramkowanie PRZED wydatkiem na miesięczny limit tokenów per
  workspace (kalendarzowy miesiąc; `0` = wyłączone, zachowanie zgodne wstecz), zapis do
  `AiUsageEvent`. Logika generowania `@[ai-text]` zeszła w dół do `Variables\Services\
  AiTextGenerationService` (dzielona przez Workflows i Generator — każdy tylko cienki dekorator
  z własnym budżetem per-uruchomienie). `ImageAiService` (edycja obrazu na Dysku) przepięty przez
  ten sam seam. Patrz **ADR-0033**.
- **2b — silnik sesji:** model `GenerationSession` (migawka receptury `recipe_snapshot`,
  `slot_values`, `results`, maszyna stanów draft→generating→ready→failed, SoftDeletes,
  HasCreator), asynchroniczny bieg z atomowym „claim" (`GenerationSessionRunManager` +
  `RunGenerationSessionJob` + `GenerationSessionExecutor`), żywy `@[ai-text]` przez
  `GeneratorAiTextService` (budżetowany, metrykowany), CRUD + czat FE.
- **2c — łańcuch obrazu server-side:** `ImagePixelProcessor` (Imagick, wierny `imageOps.ts`,
  łącznie z clampem HDRI), `ImageBaseResolver` (`disk_file`/`from_slot` — jedyna świadoma
  krawędź `Generator → Disk`; `ai_generate` wciąż niewspierany), `ImageChainExecutor`
  (piksele + metrykowany `ai_edit`), `GeneratedImageStore` (wersjonowany), endpoint serwowania +
  `POST …/save-to-disk` (krawędź Generator→Disk w drugą stronę — zapis).
- **2d — pętla dopracowania:** per-część `regenerate`, instruowany `refine` (rewizja bieżącego
  wyniku), synchroniczny wersjonowany `undo`, `part_history`/`version`/`last_op_status`/
  `last_op_error`; reaper cyklu życia (`generator:reap-sessions`: stale→failed, tydzień→kosz,
  miesiąc→trwałe usunięcie + GC blobów, archiwizacja = zamrożenie) + `archive`/`unarchive`.

Patrz **ADR-0034** (silnik sesji: async bieg, refine-jako-rewizja + undo, łańcuch obrazu, cykl
życia) i **ADR-0033** (licznik kosztów AI). Pełny kontrakt API:
`docs/backend/generator-sessions-api.md`. In-app dokumentacja:
`resources/js/next/docs/pages/GeneratorPage.vue` (sekcje §11–§16).

**Świadomie POZA zakresem podetapu 2** (patrz `docs/backend/generator-sessions-api.md` →
„Planned / deferred"): sesje autorowane przez bota (podetap 3 — od tej pory ✅ UKOŃCZONY, patrz
Status niżej), bogatszy UX limitów kosztów AI —
panel/wskaźnik zużycia ponad już istniejący `AiUsageService::cap()/remaining()/warnRatio()`
(podetap 4 — od tej pory ✅ UKOŃCZONY, patrz Status niżej), krok workflow `generate_content`
(podetap 5 — od tej pory ✅ UKOŃCZONY, KOMPLETUJE cały rozdział R2, patrz Status niżej), redo (undo jest jednokierunkowe), osobny limit wydatku PER SESJA (dziś tylko
miesięczny limit workspace'u jest twardym budżetem $; limity per-uruchomienie w sesji ograniczają
tylko fan-out, nie koszt — nadal poza zakresem, patrz Status podetap 4). **Klient text→image dla
`ai_generate` (podetap 6)** ✅ **ZBUDOWANY** — patrz niżej.

### Status R2 podetap 2 rozszerzenie — rework `video_script`: kontekst międzyczęściowy + `shot_list`/`storyboard` (Faza A+B) — UKOŃCZONY (branch, niezacommitowany)

Właściciel ODRZUCIŁ pierwotny wynik `video_script` (`[script, scene_plan]`) jako bezużyteczny dla
realnego krótkiego wideo TikTok — proza scenariusza + luźne narracje scen, bez spójnej struktury
hook/shoty/timing i bez możliwości odwołania się jednej części do WYGENEROWANEGO wyniku innej.
Zbudowane na `feat/r2-generator-templatki`, na wierzchu podetapu 2, w dwóch fazach:

- **Faza A — kontekst międzyczęściowy (`parts.<klucz>`):** OGÓLNY prymityw silnika (nie
  specyficzny dla `video_script`) — część treści może odwołać się do WYGENEROWANEGO wyniku
  WCZEŚNIEJSZEJ części przez nowy korzeń zmiennych `parts.<klucz>` (`VariableResolver::ROOTS`,
  TYLKO TEKST), rozwiązywany dokładnie jak `globals.<klucz>`. Wyłącznie WCZEŚNIEJSZE i acykliczne
  z konstrukcji — egzekwowane niezależnie na trzech warstwach: katalog edytora oferuje tylko
  wcześniejsze klucze, walidator zapisu (`TemplateSlotValidator`) odrzuca odwołanie w przód/do
  siebie/nieznane jako 422, a egzekutor sesji niezależnie ponownie wyprowadza ten sam zakres przy
  renderze. Jeden WSPÓLNY skaner (`VariableResolver::collectReferenceIds()`) jest jedynym
  autorytetem co liczy się jako odwołanie `parts.*` — znajduje je WSZĘDZIE gdzie resolver by je
  rozwiązał (dyrektywa najwyższego poziomu, prompt `@[ai-text]`, warunek/ciało if-bloku, płaski
  token `{{parts.<klucz>}}`) — dwie rundy przeglądu to zahardenowały. Refine/regenerate części
  NADRZĘDNEJ oznacza zależne części NIŻSZE jako `stale: true` (bierna podpowiedź FE, BEZ
  auto-kaskady ponownego uruchomienia — kontrola kosztów); pełny `generate` czyści flagę.
  Podgląd szablonu (`POST /generator/preview`) CELOWO NIE wypełnia `parts` — odwołanie
  `parts.<klucz>` w podglądzie zawsze rozwiązuje się pusto (asymetria podgląd/uruchomienie).
- **Faza B — strukturalny `shot_list` + iterowany przez egzekutor `storyboard`:** `video_script`
  przekomponowany na `[shot_list, storyboard]` (stare `script`/`scene_plan` ZACHOWANE w zamkniętym
  słowniku `PartKind` i nadal renderują/refine'ują istniejącą migawkę sesji — snapshot-authoritative
  — ale nieautorowalne dla nowego szablonu). `shot_list` = autorowany BRIEF kreatywny → JEDNO
  metrykowane wywołanie AI zwracające ustrukturyzowany JSON (hook / uporządkowane shoty z timingiem
  / cta) — celowo PROMPT-AND-PARSE zamiast natywnego structured output `laravel/ai` v0.4.3 (jazda na
  istniejącym, budżetowanym seamie `ai_text`, zero nowego okablowania metrykowania; defensywny parse
  potrzebny tak czy inaczej). `storyboard` = opcjonalny styl + łańcuch filtrów; egzekutor ITERUJE
  shoty rodzeństwa `shot_list` i generuje JEDEN obraz AI na shot (`storyboard.<i>`, adresowanie
  reużywające ISTNIEJĄCY kontrakt per-część regenerate/refine/undo/serve/save-to-disk — zero nowych
  endpointów); wizual shota rozwiązywany TOŻSAMOŚCIOWO (nigdy jako dyrektywa — ochrona przed
  wstrzyknięciem AI→AI). `storyboard_max_shots` (domyślnie 5) to REALNA granica fan-out (przycina
  też sparsowaną listę shotów); `image_generate_max_calls_per_session` podniesiony z 2 na 5 żeby
  pełny storyboard się zmieścił w budżecie jednego uruchomienia; timeout joba (300s) CELOWO BEZ
  ZMIAN — rozumowanie z istniejącej niezmienniczości timeoutu rozszerzone, nie otwarte na nowo.

Patrz **ADR-0035** (pełny zapis decyzji: kontekst międzyczęściowy, wybór prompt-and-parse,
storyboard jako intra-kompozycja, back-compat snapshot-authoritative, wyrównanie budżetu). Pełny
kontrakt: `docs/backend/generator-sessions-api.md` (sekcje „Cross-part context" + „shot_list &
storyboard") i `docs/backend/generator-api.md` (kształty autorowania + walidacja zapisu). In-app
dokumentacja: `resources/js/next/docs/pages/GeneratorPage.vue` (nowe sekcje §18–§19).

### Status R2 podetap 3 „Boty w generatorze" — UKOŃCZONY (branch, niezacommitowany)

Zbudowane na `feat/r2-generator-templatki`, na wierzchu podetapu 2 (+rozszerzenia). Człowiek może
DELEGOWAĆ edytowalną sesję botowi: bot (1) AUTONOMICZNIE wypełnia jej sloty i (2) staje się
AUTOREM treści (generacja w jego głosie), podczas gdy człowiek zostaje WŁAŚCICIELEM (pełny
refine/undo/delete) — odwracalna, migawkowana nakładka, NIE przepisanie `creator`.

- **Nakładka autora, nie `creator`:** nowe kolumny `bot_author_id` (provenance, bez FK) +
  `bot_delegation` (json: `{author, voice, snapshot_at, slot_values_before}`) na
  `generation_sessions` — wszystko-albo-nic, migawkowe (edycja/usunięcie bota PO delegacji nigdy
  nie zmienia głosu już delegowanej sesji).
- **Opaque głos przez `AiVoiceContext`:** `Bot\Services\BotVoiceComposer` składa personę/styl/
  słownik/frazy/zakazy bota w JEDEN nieprzezroczysty dyrektyw, migawkowany przy delegacji;
  nowy ambient seam `Variables\Support\AiVoiceContext` (bliźniak `MeterContext`) niesie go przez
  cały zakres renderu i ZASTĘPUJE (nie dokłada) linię `AiPersona` w `AiTextAgent` — dociera też
  do `ShotListAgent` jako dodatkowa klauzula tonu (kontrakt JSON bez zmian); obraz storyboardu
  BEZ zmian (tylko autorowany `style`).
- **Autonomiczne wypełnianie slotów:** `Bot\Services\BotSlotFillService` + `BotSlotFillAgent` —
  JEDNO metrykowane wywołanie `ai_text` (bramka-przed-wydatkiem, tagowane sesją), defensywny
  parse `{slotName: value}`, każda wartość PONOWNIE walidowana przez `ConstantTypeValidator`
  przed zapisem. Sloty PLIKOWE i głębokie kompozyty NIGDY nie są oferowane botowi (bot nie ma
  dostępu do Dysku i nie może sfałszować referencji pliku) — wymagany slot plikowy trafia do
  `unfilled_required` i sesja zostaje draftem.
- **Odwracalny undo:** `DELETE …/delegate` czyści nakładkę I przywraca `slot_values` sprzed
  delegacji z migawki `slot_values_before` (zero utraty danych); zablokowane tylko przy
  `generating` (409) — delegowana sesja `failed` nadal odwracalna.
- **Jedna nowa krawędź międzymodułowa, jednokierunkowa:** `Bot → Generator + Variables`
  (`SessionDelegationController` w module Bot); `Generator`/`Variables` nie importują NIC z
  `Bot` — przypięte testami granicznymi w obie strony.
- **Domyślnie `auto_generate: false`** (gate-przed-wydatkiem) — delegacja wypełnia sloty i
  zatrzymuje się na `ready` do przeglądu raportu wypełnienia; auto-uruchomienie generacji to
  osobny, jawny opt-in.

Patrz **ADR-0036** (pełny zapis decyzji: nakładka-nie-creator, seam opaque-voice, zakres
autonomicznego wypełniania, krawędź Bot→Generator, odwracalny undo). Pełny kontrakt:
`docs/backend/generator-sessions-api.md` (sekcja „Bot-author delegation overlay") +
`docs/backend/bots-api.md` (endpointy delegate/undo). In-app dokumentacja:
`resources/js/next/docs/pages/GeneratorPage.vue` (§20) i `resources/js/next/docs/pages/BotsPage.vue` (§11).

**Świadomie POZA zakresem podetapu 3:** bot-fill slotów plikowych/głębokich kompozytów, autonomia
bota poza jednorazowym wypełnieniem (regenerate/refine z inicjatywy bota), delegacja podpięta pod
Akceptacje/Publishing.

### Status R2 podetap 4 „Limity kosztów AI" — UKOŃCZONY (branch, niezacommitowany)

Zbudowane na `feat/r2-generator-templatki`, na wierzchu podetapu 2 (+rozszerzenia) i podetapu 3.
Domyka lukę zostawioną świadomie w podetapie 2: `AiUsageService` miał już `cap()`/`remaining()`/
`warnRatio()`, ale gate liczył się w TOKENACH i nie było żadnego UI. Ten podetap przełącza bramkę
na DOLARY per-workspace, dodaje przypisanie wydatku do AKTORA i dokłada front-end limitu.

- **Zmiana bazy bramki: tokeny → dolary.** `estimated_cost` (dotąd zawsze `0.0`) staje się
  OBCIĄŻAJĄCE — `LedgerMeteredAiCall` sumuje miesięczny `estimated_cost` per workspace i odmawia przy
  `>= AiUsageService::cap()`. Nowa mapa cen per-kanał w konfiguracji (`ai.meter.pricing`):
  `ai_text.per_1k_tokens`, `ai_image_edit.per_call`, `ai_image_generate.per_call` — każda
  nadpisywalna przez env. Stary `monthly_token_cap`/`unit_cost` ZOSTAJĄ, ale już nie bramkują —
  telemetria/drugorzędny odczyt tokenów. Każdy $ na ekranie to ESTYMACJA, nigdy prawdziwy rachunek.
- **Limit per-workspace w dolarach (Opcja B).** `ai_monthly_cost_cap` DECIMAL(10,2) na CENTRALNEJ
  tabeli `workspaces` (czyta się poprawnie w obu trybach bazy); `null` = dziedziczy domyślną z env
  (`AI_MONTHLY_COST_CAP`, domyślnie `0.00` = WYŁĄCZONE), wartość dodatnia = limit tego workspace'u,
  `0.00` = jawnie bez limitu. Ustawia właściciel, czyta każdy członek.
- **Przypisanie do aktora.** Nowe polimorficzne `actor_type`/`actor_id` na `ai_usage_events`,
  tagowane przez `MeterContext` + nowy `App\Support\Meter\MeterActorResolver` (mirror precedencji
  `HasCreator`: jawny tag → aktywny bieg workflow → zalogowany user → brak). Żyje w `App\Support`
  (NIE w module `Variables`) — SAME celowe obejście granicy co `HasCreator`, żeby `Variables` mogło
  odwołać się do kontekstu biegu workflow bez naruszenia jednokierunkowej granicy modułu. Nazwy
  rozwiązywane przy ODCZYCIE, nigdy nie zapisywane.
- **Endpointy (moduł Workspaces):** `GET /workspaces/{id}/ai-usage` (dowolny członek) — pełne
  podsumowanie ($, per-kanał, per-aktor, `blocked`/`warn_reached`); `PATCH /workspaces/{id}/ai-usage/cap`
  (tylko właściciel) — ustawia/czyści limit.
- **Bramka 429 PRZED uruchomieniem.** Cztery punkty startu sesji (`generate`, per-część
  `regenerate`/`refine`, delegacja z `auto_generate:true`) teraz ODMAWIAJĄ z góry (HTTP 429,
  `code: 'ai_budget_exceeded'`), ZANIM sesja zostanie zaklejmowana, gdy workspace jest JUŻ nad
  limitem — odrębne od istniejącego fail-soft W TRAKCIE biegu (blok AI cicho rozwiązuje się do `''`,
  bieg i tak kończy `ready`), które zostaje bez zmian dla biegu przekraczającego limit w locie.
- **Front-end:** strona `settings/ai-usage` (wpis w menu użytkownika) z miernikiem (zielony/bursztyn/
  czerwony + caveat „szacowane"), rozbiciem per-kanał + per-aktor, edytorem limitu tylko dla
  właściciela (`can_manage` z serwera); w czacie sesji — plakietka budżetu + baner zablokowania,
  akcje Generuj/kompozytor/regenerate/refine wyłączają się z góry gdy `summary.blocked`.

Patrz **ADR-0037** (pełny zapis decyzji: przełączenie bramki na dolary, limit per-workspace opcja B,
przypisanie do aktora + obejście granicy modułu, bramka 429 vs fail-soft w locie) — częściowo
unieważnia **ADR-0033** (bramka nie liczy się już w tokenach; ADR-0033 oznaczony jako częściowo
superseded). Pełny kontrakt: `docs/backend/workspace-ai-usage-api.md`; zaktualizowana integracja w
`docs/backend/generator-sessions-api.md` (sekcje „Cost meter integration" + nowa „Pre-run 429 budget
gate"). In-app dokumentacja: `resources/js/next/docs/pages/GeneratorPage.vue` (§15, przepisana).

**Świadomie POZA zakresem podetapu 4:** historia/trend zużycia (tylko bieżący miesiąc), estymacja
kosztu PRZED uruchomieniem konkretnej sesji (bramka 429 tylko odmawia gdy już nad limitem, nie
prognozuje), osobny limit wydatku per sesja (nadal tylko limit workspace'u jest twardym budżetem $),
rozszerzenie bramki 429 na Workflows/Disk (te dwa mają nadal tylko fail-soft w locie).

### Status R2 — warstwa kierunku kreatywnego + kontrakty narracyjne (rework jakości nad podetapem 2, UKOŃCZONY, branch, niezacommitowany)

Nie nowy podetap R2 — jakościowy rework nad już zbudowanym silnikiem sesji (podetap 2 + rozszerzenie
Faza A/B), zdiagnozowany empirycznie: brief „film 1–2 minuty" niezmiennie produkował ~15-sekundowy
skrypt (sztywna reguła agenta „3 to 5 SHOTS" nadpisywała deklarowany czas trwania), a każda generacja
w biegu była NIEZALEŻNA — N wywołań AI, które nigdy się nie widziały (obraz posta i jego tekst mogły
opisywać co innego; pięć klatek storyboardu mogło wyglądać jak pięć różnych produkcji).

- **B1 — kontrakt narracyjny (zero kosztu AI).** `ShotListAgent` dostaje ADAPTACYJNĄ liczbę ujęć
  („między 3 a efektywnym limitem" zamiast sztywnego „3 do 5") z jawną PRECEDENCJĄ: deklarowany czas
  trwania i limit ujęć są WIĄŻĄCE, przedział 5–30s na ujęcie to tylko wskazówka (agent wydłuża ujęcia
  ponad 30s zamiast skracać całość). Blok FABUŁY (wątek przewodni + eskalacja + payoff osadzony
  wcześniej + ciągła narracja + spójność opisu bohatera) DEGRADUJE się w trzech wariantach zależnie od
  efektywnego limitu (≥3 / ==2 / ==1) zamiast zostawiać niespełnialną regułę przy niskim limicie.
- **B2 — warstwa kierunku kreatywnego.** Pełny bieg wykonuje JEDNO dodatkowe metrykowane wywołanie
  `ai_text` (`CreativeDirectionService` → `CreativeDirectionAgent`), które zwraca mały ustrukturyzowany
  obiekt (message/goal/audience/tone/through_line/arc_beats/subject/setting/visual_style/
  duration_target_seconds/continuity_notes), zapisywany do nowej nullable kolumny `creative_direction`
  na `generation_sessions` (migracje addytywne, central+tenant). Wywodzony z AUTOROWANEJ receptury
  (no-op podgląd szablonu), NIE z rozwiązanego briefu — unika podwójnego billingu i cudzej parafrazy
  autorskich ograniczeń. Wstrzykiwany WYŁĄCZNIE jako oznaczony blok DANYCH w wiadomości użytkownika
  (nigdy jako instrukcja systemowa) — trzy projekcje per-konsument (`forText`/`forShotList`/`forImage`).
  Derywowany RAZ na pełny bieg (ambient `CreativeDirectionContext`, bliźniak `AiVoiceContext`); izolowane
  operacje częściowe (regenerate/refine) TYLKO odczytują zapisany kierunek. Głos bota (podetap 3) wygrywa
  na tonie. Adaptacyjny limit ujęć (`storyboard_max_shots` 5→8, autorska `content.storyboard.max_shots`)
  w LOCK-STEP z budżetem `image_generate_max_calls_per_session` (5→8).
- **Uczciwe ograniczenie:** kotwica promptowa (`forImage()`) daje spójny świat/styl/paletę/kamerę, ale
  NIE tę samą twarz bohatera w każdej klatce — nazwana ścieżka v2 to łańcuchowanie image-to-image
  (tańsze per-wywołanie, ale sekwencyjne i nadmiernie zachowawcze kompozycyjnie).

Patrz **ADR-0038** (pełny zapis decyzji + alternatywy rozważone: sam kontrakt bez kierunku, kotwica
niesiona przez shot_list, łańcuchowanie image-to-image). Pełny kontrakt:
`docs/backend/generator-sessions-api.md` (sekcje „Narrative contract upgrades" + „Creative direction
layer") i `docs/backend/generator-api.md` (`content.storyboard.max_shots`). In-app dokumentacja:
`resources/js/next/docs/pages/GeneratorPage.vue` (§21).

### Status R2 podetap 5 „Workflow: `generate_content`" — UKOŃCZONY, KOMPLETUJE CAŁY ROZDZIAŁ R2 (branch, niezacommitowany)

Zbudowane na `feat/r2-generator-templatki`, na wierzchu podetapu 2 (+rozszerzenia Faza A/B) i warstwy
kierunku kreatywnego. **To jest ostatni podetap R2** — zamyka rozdział „Generator treści + Templatki"
(Etap 7) w całości: workflow potrafi teraz produkować treść z szablonu bez człowieka w pętli.

- **Silnik suspend/resume (ogólny, w module `Workflows`, nie specyficzny dla generatora).** Krok
  sygnalizuje zawieszenie przez RZUCENIE `StepSuspended(kind, correlationKey, payload)` (sentinel
  return odrzucony — zwrotka kroku jest scalana DOSŁOWNIE do `context.steps.<klucz>`, magiczny klucz
  wyciekłby do widocznego dla użytkownika katalogu zmiennych). Trzy nowe nullable kolumny na
  `workflow_runs`: `waiting_on` (json — kind/step_key/step_type/position/payload/**config JUŻ
  ROZWIĄZANY**/definition_hash/ai_text_calls), `waiting_key` (indeksowany klucz korelacji),
  `waiting_since` (re-stemplowany przy KAŻDYM zawieszeniu). `config` w `waiting_on` jest ODTWARZANY
  dosłownie przy wznowieniu, NIGDY nie rozwiązywany ponownie — dyrektywa kosztowa (`@[ai-text]`) płaci
  raz. Wznowienie = ŚWIEŻY job (`WorkflowRunResumeJob(runId, workspaceId, waitingKey)` — trzy skalary,
  nigdy zserializowana kontynuacja, ten sam idiom co `BotTaskRunManager`), claim ATOMOWY i
  SKORELOWANY na obserwowanym `waiting_key` (podwójne/spóźnione wznowienie = czyste no-op). Fingerprint
  CAŁEJ definicji (SHA1) łapie edycję workflow w dowolnym miejscu podczas oczekiwania, nie tylko na
  zawieszonym kroku. DWA wyzwalacze: listener osiedlenia (optymalizacja latencji, reaguje na zdarzenie
  generatora) + sweep oczekujących biegów (`workflows:reap-stale-runs`, teraz zamiata OBA — stare
  `running` i stare `waiting` — GWARANCJA POPRAWNOŚCI, bo własny reaper generatora osiedla sesję z
  wyczyszczonym kontekstem tenant i CELOWO nie broadcastuje, więc samo zdarzenie by nigdy nie
  wystarczyło). Niezmiennik kolejności timeoutów: job generacji 300s < lock 600s <
  `workflows.run_timeout` 900s < `generator.session_stale_after` 1800s < `workflows.wait_timeout` 2700s
  (ostatnia deska ratunku). `WorkflowRunJob::$timeout` jawnie ustawiony na 720s (wcześniej cicho
  dziedziczył 60s workera).
- **Krok `generate_content`.** Config: `template_id` (wymagany, uuid szablonu scoped do workspace'u),
  `slots` (mapa nazwa slotu → literał lub unia wartość-lub-zmienna, typowana wg WŁASNEGO typu slotu),
  `folder_id` (nullable, folder Dysku dla wyeksportowanych obrazów), `name` (nullable). Wyjścia:
  `session_id`, `content` (TEKST, zmontowany przez nowy `SessionContentProjector` — serwerowy
  bliźniak kompozycji `FinalPostBody.vue`), `image_file_ids` (FILE, konsumowalne przez
  `create_task.attachments`), `status` (praktycznie zawsze `ready` — nieudana sesja twardo wywala
  krok), `has_failed_parts`. Granularne 422 przy autorowaniu: nieodwzorowany wymagany slot, nieznana
  nazwa slotu, pipeline niezgadzający się typem, ORAZ **odmowa slotu KOMPOZYTOWEGO** (dowolny `object`,
  lub `array:true` + `file`) — SKALARNY `file` JEST wspierany (celowa rozbieżność od blankietowej
  odmowy plikowej bota, ADR-0036/ADR-0039 D14). Limit **2** kroki `generate_content` na workflow. Flow:
  rozwiąż szablon (tenant-scoped) → rozwiąż każdy slot → utwórz sesję PUSTĄ → wypełnij pod
  `SlotScopePolicy::Automation` → sprawdź wymagane sloty → `claimAndDispatch` na PRAWDZIWYM połączeniu
  kolejki (`RealQueueConnection` — ucieczka z wymuszonego `sync` drivera pętli biegu, który jest
  NOŚNY, nie przypadkowy: to on utrzymuje `WorkflowRunContext` żywy, żeby `HasCreator` stemplował
  wiersze autorowane przez krok biegiem) → `StepSuspended`. Przy wznowieniu: `null`/`failed` = TWARDA
  porażka; `generating`/`draft` = PONOWNE zawieszenie na TYM SAMYM kluczu; `ready` = projekcja tekstu +
  eksport KAŻDEGO wyprodukowanego obrazu na Dysk — W FAZIE WZNOWIENIA (nie w workerze generacji), żeby
  `HasCreator` przypisał plik do biegu, nie do `null`.
- **Atrybucja.** Sesja tworzona WEWNĄTRZ biegu, więc `HasCreator` stempluje `creator_type='workflow_run'`
  — egzekutor czyta to jako jawnego aktora licznika, więc KAŻDE zdarzenie AI niesie
  `actor_type='workflow_run'`, `actor_id=<id biegu>` BEZ potrzeby przeżycia `WorkflowRunContext` do
  workera generacji (nie przeżywa — to inny job na prawdziwej kolejce).
- **Poprawki błędnej dokumentacji złapane przez adwersarialny przegląd** (patrz też sekcja „Ops notes"
  w `docs/backend/workflows-api.md`): `WorkflowRunState::WAITING` była opisana jako „zarezerwowana,
  nieprodukowana przez silnik MVP" — TERAZ jest produkowana; `create_form_report` była opisana jako
  „genuinely async pod prawdziwą kolejką" — W RZECZYWISTOŚCI zawsze biegła INLINE (wymuszony `sync`
  driver pętli biegu jest NOŚNY, nie efekt uboczny) — to `generate_content` jest jedyną naprawdę
  asynchroniczną furtką (`RealQueueConnection`).

Świadomie POZA zakresem: anulowanie oczekującego biegu (`WorkflowRunState::CANCELLED` nadal
zarezerwowany), żywy push na stronie szczegółów biegu (panel oczekiwania to uczciwy snapshot z
momentu odczytu + jawny Refresh, bez websocketu — inaczej niż czat generatora), granularne wyjścia
per-część (dziś jeden zmontowany `content` + jedna lista `image_file_ids`); ~~bot delegujący generację
uruchomioną przez workflow (`SlotScopePolicy::Bot` i `SlotScopePolicy::Automation` to siostrzane
granice zaufania, nieskomponowane)~~ — **ZREALIZOWANE 2026-07-31**: krok `generate_content` przyjął
opcjonalny `bot_id` (ta sama bramka delegacji co ręczna — głos + wizerunek; sloty nadal od autora
workflow), patrz aneks ADR-0039.

Patrz **ADR-0039** (pełny zapis decyzji: wybór genuine suspend/resume nad synchronicznym inline'em
i alternatywami, sygnał przez throw, replay configu, idiom świeżego joba, skorelowany claim,
fingerprint definicji, event-jako-optymalizacja vs sweep-jako-poprawność, eksport w fazie wznowienia,
odmowa kompozytów + rozbieżność skalarnego pliku od ADR-0036, limit 2 kroków, niezmiennik
timeoutów) — amenduje **ADR-0036** (doprecyzowuje, że odmowa plikowa D-E dotyczy WYŁĄCZNIE granicy
bota). Pełny kontrakt: `docs/backend/workflows-api.md` (sekcje „Steps: `generate_content`" +
„Suspend/resume engine") i `docs/backend/generator-sessions-api.md` (sekcja „Automation seam (R2
sub-stage 5)"). In-app dokumentacja: `resources/js/next/docs/pages/WorkflowsPage.vue` (§7/§12) i
`resources/js/next/docs/pages/GeneratorPage.vue` (§11).

**CAŁY ROZDZIAŁ R2 „Generator treści + Templatki" (Etap 7) JEST TERAZ UKOŃCZONY** — podetapy 1–6
wszystkie ✅, na branchu `feat/r2-generator-templatki`, niezacommitowane. Świadomie odłożone na
później (nie część R2): anulowanie/redo, granularne wyjścia per-część, ~~kompozycja bot+workflow~~
(**ZREALIZOWANE 2026-07-31**, patrz wyżej i aneks ADR-0039), usage history/trend + estymacja kosztu
przed uruchomieniem, rozszerzenie bramki 429 na Workflows/Disk,
typ treści tworzony przez użytkownika, nowy `PartKind`, native structured output dla `shot_list`,
łańcuchowanie image-to-image dla spójności twarzy między klatkami — pełne listy w „Planned / deferred"
odpowiednich dokumentów backendowych. Następny krok w roadmapie: commit stosu (właściciel decyduje
kiedy), potem R3 Kalendarz.

**Cały stos R2 ZACOMMITOWANY** (`ff95b0d`, po zakończeniu powyższych podetapów). Poniższy podetap
buduje NA TYM commicie.

### Status R2 — autor per blok w dyrektywie `@[ai-text]`, zastępujący wybór persony (rework nad podetapami 1+3, UKOŃCZONY, niezacommitowany na wierzchu `ff95b0d`)

ADR-0013 (Etap 5.1) świadomie ODRZUCIŁ system Bot/Character jako „personę" `@[ai-text]` — zostawiony
jako możliwa przyszłość, niezbudowany. Podetap 3 (ADR-0036) zbudował dokładnie to, ale wyłącznie na
poziomie CAŁEJ sesji (delegacja). Ten rework generalizuje ten sam mechanizm o jeden poziom niżej — do
POJEDYNCZEGO bloku `@[ai-text]` — i, ponieważ stary picker persony i nowy picker autora zajmują to samo
miejsce w UI i odpowiadają na to samo pytanie („czyim głosem pisany jest ten tekst?"), właściciel
zdecydował o USUNIĘCIU pickera persony z edytora zamiast trzymać oba obok siebie.

- **Kontraktowy szew, odwrócenie zależności:** `App\Modules\Variables\Contracts\AuthorVoiceResolver`
  (batch `voicesFor(authorIds[], workspaceId)`, jawny workspace, fail-SAFE — nierozwiązany id jest po
  prostu NIEOBECNY w mapie) zadeklarowany w module `Variables`, zaimplementowany jako
  `Bot\Services\BotAuthorVoiceResolver` (bindowany w providerze modułu Bot, nadpisuje domyślny
  `NullAuthorVoiceResolver`). `App\Modules\Bot` dopisany do listy zakazanych importów w
  `VariablesModuleBoundaryTest` obok już istniejących testów granicznych Workflows/Generator.
- **`AiVoiceContext` niesie teraz DWA źródła głosu** — dyrektywę sesji/runu (istniejącą od podetapu 3) i
  mapę per-autor (`authorId → opaque voice`) — a `effectiveDirective(authorId)` jest JEDYNYM miejscem
  pierwszeństwa: własny autor bloku > głos sesji/runu (delegacja) > legacy `personaId` > neutralny.
  „Delegowany bot pisze wszystko, co nie ma przypisanego własnego autora" — delegacja jest FALLBACKIEM,
  nigdy silniejszym roszczeniem niż jawny wybór na poziomie bloku.
- **Generator ZAMRAŻA, Workflows rozwiązuje NA ŻYWO** — świadoma asymetria, nie przeoczenie.
  `GenerationSessionService::create()` skanuje `recipe_snapshot.content` (współdzielony skaner
  `VariableResolver::collectAiTextAuthorIds()`), rozwiązuje WSZYSTKICH autorów w JEDNYM zapytaniu
  wsadowym i zapisuje `recipe_snapshot.author_voices` — klucz WYŁĄCZNIE serwerowy, nigdy niewyświetlany
  na żadnym zasobie. Edycja/usunięcie bota po utworzeniu sesji nigdy nie zmienia jej renderu — ten sam
  niezmiennik co reszta `recipe_snapshot` (ADR-0034 D1). `WorkflowStepRunner::run()` nie ma czego
  zamrozić (bieg zawsze wykonuje definicję TAKĄ, JAKA JEST TERAZ) — rozwiązuje autorów NA ŻYWO, raz na
  przebieg, w tym samym idiomie zapisz/przywróć co kontekst biegu i budżet `@[ai-text]`. Konsekwencja dla
  biegu ZAWIESZONEGO i wznowionego (ADR-0039): wznowiony przebieg ponownie rozwiązuje mapę na żywo, więc
  edycja głosu bota W TRAKCIE oczekiwania dociera do WSZYSTKICH kroków jeszcze przed nim — krok już
  wykonany zachowuje to, co już wygenerował.
- **Atrybucja kosztu BEZ ZMIAN:** autor bloku nie przenosi wydatku `ai_text` na wskazanego bota — płaci
  nadal aktor sesji/runu. Re-atrybucja per blok byłaby furtką do obejścia miesięcznego limitu $
  (ADR-0037) przez samo nazwanie innego aktora w polu tekstowym.
- **Kierunek kreatywny (ADR-0038) rozszerzony na blok:** `GeneratorAiTextService::withDirection()` pyta
  teraz `effectiveDirective(authorId)` zamiast wyłącznie głosu sesji — TON kierunku kreatywnego jest
  tłumiony dla KAŻDEGO bloku ze skutecznym głosem, także w runie niezdelegowanym (wcześniej tłumiła to
  tylko delegacja całej sesji).
- **UI:** picker persony ZNIKNĄŁ z panelu `@[ai-text]` (Generator i kroki workflow) — zastąpiony
  Author `BotSelect` (bot wnosi GŁOS, nigdy wiedzę/narzędzia). Blok z zastanym `personaId` pokazuje
  READ-ONLY pasek „ton zastany" z akcją wyczyszczenia; `personaId`/`ai_personas` działają bez zmian w
  runtime. Pole „Etykiety wiedzy" ukryte (dekodowane i wyrzucane przez runtime — nigdy nie działało;
  dane `labels` w istniejących dokumentach zachowane). Nowy store `app/stores/botDirectory.ts`
  (id→{name,status}, deduplikowany) rozwiązuje `authorId` do wyświetlenia w chipie. `BotSelect` zyskał
  `statusBadge` + przepustki slotów `#empty`/`#value`; `Select` zyskał addytywny slot `#empty`.
  **Uboczna naprawa całej aplikacji:** `Select` rejestruje się teraz w stosie nakładek, więc Escape przy
  otwartej liście zamyka listę, a nie cały Modal/Drawer (wcześniej kasowało to niezapisane zmiany) — dotyczy
  KAŻDEGO Selecta w overlayu.
- **Wire:** `@[ai-text]` zyskał opcjonalne `authorId`/`authorName` (snapshot wyświetleniowy, nigdy
  autorytet), kodowanie EMIT-OR-OMIT — blok bez autora serializuje się bajt w bajt jak dotąd. Backend nie
  ma aliasu dla `authorId` (w przeciwieństwie do `personaId`/`persona`).

Patrz **ADR-0040** (pełny zapis decyzji: szew kontraktowy, zamrożenie-vs-na-żywo, pierwszeństwo,
fail-SAFE zamiast fail-CLOSED, decyzja o NIE zmienianiu atrybucji kosztów, usunięcie persony z UI przy
zachowaniu runtime) — amenduje **ADR-0013 §4** (dopisany aneks, historia decyzji niezmieniona) i
rozszerza **ADR-0038** (tłumienie tonu kierunku kreatywnego per blok). Pełny kontrakt:
`docs/backend/generator-sessions-api.md` (sekcja „Per-block AI-text authors"),
`docs/backend/generator-api.md`, `docs/backend/workflows-api.md` (sekcja c, `@[ai-text]`),
`resources/js/next/ui/editor/README.md` (kontrakt dyrektywy). In-app dokumentacja:
`resources/js/next/docs/pages/GeneratorPage.vue` (§6, §20, §21) i
`resources/js/next/docs/pages/WorkflowsPage.vue` (sekcja SB2 + „Planned/deferred").

### Status R2 — silnik rozproszonych kadrów storyboardu + moduł „Wygląd" bota (rework nad podetapem 2/Faza B + warstwą kierunku kreatywnego, UKOŃCZONY, niezacommitowany na wierzchu `ff95b0d`)

Nie nowy podetap R2 — dwie sprzężone naprawy nad już zbudowanym silnikiem `storyboard` (Faza B) i warstwą
kierunku kreatywnego. Spike GO/NO-GO PRZED implementacją zmierzył koszt: edycja z referencją ~57,8 s
(mediana) vs. generacja tekst→obraz ~33 s — osiem kadrów z choćby jedną edycją nie mieści się w
nienaruszalnym oknie 300 s `RunGenerationSessionJob`, więc właściciel zdecydował: osobne joby, równolegle
(nie większy timeout).

- **Silnik rozproszonych kadrów** (`ADR-0041`). `storyboard` już nie renderuje obrazów inline —
  ANONSUJE każdy kadr (`pending`) i osobny `RenderStoryboardFrameJob` (240 s, `tries=1`) go renderuje,
  wielu równolegle na wielu workerach. Skorelowany claim TOKENEM per DOSTAWA (nie per kadr) —
  `pending → rendering` pod `lockForUpdate()`. Budżet obrazu (`ai_generate_calls`/`ai_edit_calls`)
  przeniesiony z liczników instancji na DWIE utrwalone kolumny + jedna atomowa gwardowana `UPDATE` na
  rezerwację (migracje central+tenant) — liczniki instancji przestały cokolwiek ograniczać, gdy jeden run
  stał się wieloma jobami. Ostatni rozliczony kadr flipuje sesję + JEDEN broadcast (kontrakt FE
  niezmieniony). Reaper kadrów (`frame_stale_after`, domyślnie 900 s) biegnie PRZED reaperem sesji w
  każdym przebiegu. **Blokujący defekt złapany przez przegląd adwersarialny**: token identyfikował KADR,
  nie DOSTAWĘ — redelivery pod `tries=1` mogło rozliczyć żywy, opłacany kadr, przedwcześnie zamknąć run i
  zamrozić resztę kadrów; naprawa głębsza niż rekomendacja, zastosowana RÓWNIEŻ retroaktywnie do
  ISTNIEJĄCEGO joba całej sesji (identyczna luka tam już istniała). Konsekwencja jednego workera: kadry
  RÓWNOLEGLE między workerami, SEKWENCYJNIE na jednym (~58 s/kadr z referencją).
- **Moduł „Wygląd" bota — tożsamość obrazowa postaci** (`ADR-0042`). Pierwsza prawdziwa logika za kolumną
  `bots.visual` (placeholder od migracji początkowej). Właściciel: tożsamość OBRAZOWA (zdjęcie referencyjne),
  nie tekstowa — opisany tekstem charakter nie rysuje się jako ta sama osoba dwa razy. Dwie ścieżki
  tworzenia (z referencji = edycja / z opisu = generacja) na ISTNIEJĄCEJ asynchronicznej maszynerii Dysku
  (`DiskAiEdit` — ten sam dzienny limit, ta sama bramka $, ten sam poll/broadcast) — nowa jednokierunkowa
  krawędź **Bot → Disk** (Disk nigdy nie nazywa Bota), przypięta testami w obie strony. Wygenerowane bajty =
  plik NALEŻĄCY DO BOTA (`fileable_type='bot'`, niewidoczny w przeglądarce Dysku). Delegacja sesji zamraża
  WYGLĄD tak samo jak GŁOS (ADR-0036): opisowe pola do `bot_delegation.visual`, bajty zatwierdzonego
  wizerunku do `SessionIdentityImageStore`, per POSTAĆ (nie per sesja — otwarcie na wiele postaci),
  celowo POZA katalogiem czyszczonym przy pełnym re-runie. Kontrakt shot-listy: `features_character` per
  ujęcie (decyzja MODELU, nie autora — default false); dla pojedynczego `image_plan` autor dostaje jawny
  przełącznik `character: 'auto'|'never'` (default auto — bez `'always'`). Kadr z flagą → baza generowana Z
  REFERENCJI zamiast czystej generacji: REZERWUJE licznik GENERACJI (to nadal baza kadru), ROZLICZA kanał
  EDIT (to realny koszt) — inaczej storyboard z autorskim filtrem traciłby ogon kadrów na współdzielonym
  budżecie edycji. `subject` kierunku kreatywnego PODMIENIONY opisem postaci TYLKO w kadrach z flagą
  (człowiek-skonfigurowany wygrywa z domysłem modelu); estetyka+strój+zakazy jako proza BEZ ogrodzenia
  (obraz nie ma kanału systemowego — ogrodzenie po prostu by się narysowało). Moderacja: `wardrobe` jako
  osobne pole — sukienka przechodzi, strój kąpielowy dla TEJ SAMEJ postaci odrzucany `[sexual]` (odkrycie
  spike'u) — kod `image_safety`/`safety_rejected`, STORED-ONLY (drut: `failed` + addytywny `error_code`).
  Kill switch `generator.visual_identity.enabled` gate'uje TYLKO konsumpcję, nigdy zamrożenia przy
  delegacji. **Dwa defekty WYSOKIE złapane przez przegląd i naprawione**: re-billing przy rzucającym hooku
  materializacji (`ImageAiService::process()` rozbity na PŁATNĄ połowę `produce()`, która utrwala
  `result_image` PRZED hookiem — retry wznawia, nigdy nie płaci drugi raz; korzysta z tego KAŻDY wywołujący
  współdzielonej maszynerii, nie tylko bot) oraz tożsamość tylko-wizerunek (bez żadnego opisanego pola)
  gubiąca referencję na obrazach AUTORSKICH (naprawione: referencja rozwiązywana NIEZALEŻNIE od tego, czy
  jest tekst do dołączenia). **Naprawiony defekt ze spike'u**: `input_fidelity` — komentarz twierdził, że
  wysyłane do dostawcy, payload nigdy go nie zawierał; naprawa dotyczy KAŻDEJ edycji obrazu na Dysku, nie
  tylko wizerunków bota.

Wszystko domyślnie WYŁĄCZONE/FALSE — sesja bez postaci i storyboard bez kadrów-w-osobnych-jobach (gdy pusty)
komponują się bajt w bajt jak przed tymi zmianami (przypięte testami). Patrz **ADR-0041** i **ADR-0042**
(pełne zapisy decyzji, w tym odrzucone warianty: żywa referencja zamiast kopii bajtów, własny magazyn w
module Bot, bezwarunkowa podmiana `subject`, ogólne łańcuchowanie image-to-image jako v2-path ADR-0038 —
ten rework rozwiązuje węższy, inny przypadek i NIE zamyka ogólnej luki). Pełny kontrakt:
`docs/backend/generator-sessions-api.md` (sekcje „Distributed storyboard frames" i „Frozen character visual
identity"), `docs/backend/bots-api.md` (sekcja „Visual identity module ('Wygląd')"),
`docs/backend/disk-api.md` (poll `error_code`/`safety_rejected`, resume-on-retry, `input_fidelity`). In-app
dokumentacja: `resources/js/next/docs/pages/GeneratorPage.vue` (§22, §23) i
`resources/js/next/docs/pages/BotsPage.vue` (§12).

---

## Moduł Wiedzy (Knowledge)

**Status: MVP+ ukończone.** Wstawiony poza pierwotną numeracją R-rozdziałów — decyzja właściciela
(planning-agent rekomendował węższy MVP; owner wybrał wariant MVP+ z chunkingiem i wizualnym grafem
już w pierwszym cięciu, „wbrew rekomendacji"), zbudowany **przed R3 Kalendarz**, bo kilka
zaplanowanych konsumentów wiedzy (boty, docelowo Generator/Workflows) potrzebowało go wcześniej niż
kalendarza. Pełny kontrakt backendu: `docs/backend/knowledge-api.md`. Zapis decyzji:
`docs/decisions/ADR-0043-knowledge-module-design.md`, `ADR-0044-knowledge-index.md`,
`ADR-0045-knowledge-consumption-data-erasure.md`.

**Cel:** trwałe, kuratorowane miejsce faktów, których workspace uczy platformę RAZ, a każdy
konsument AI odczytuje z powrotem — zamiast każdej postaci (bota) trzymającej własną, niewspółdzieloną
listę wpisów.

**Zbudowany zakres:**
- **Baza wiedzy** — twardy kontener workspace'u z kartą tożsamości (charter) i typowanym schematem
  metadanych (na deskryptorach modułu Variables, nie na formacie Forms); rewersyjna kaskada kosza
  baza→wpisy.
- **Wpis** — hasło encyklopedyczne (tytuł + treść ≤ 40 000 znaków + metadane + status
  draft/proposed/approved/archived + flaga „nieaktualny" `stale_at`, ortogonalna do statusu); slug
  stabilny przy zmianie tytułu; pełna historia wersji append-only z przywracaniem (dopisuje, nie
  cofa) i blokadą optymistyczną (409 przy równoległym zapisie).
- **Wikilinki i czerwone linki (duchy)** — `[[slug]]`/`[[slug|etykieta]]` budują graf wpis→wpis;
  nierozwiązany link to duch, automatycznie „ożywiany", gdy powstanie wpis o pasującym slugu.
- **Indeks semantyczny** — chunking (dzielenie wpisu na fragmenty po strukturze dokumentu),
  różnicowe indeksowanie po digestach (edycja jednego akapitu = 1 wywołanie embeddingu, nie
  ponowne indeksowanie całości), degradacja budżetowa nigdy nie blokująca zapisu treści.
- **Wyszukiwarka hybrydowa** — leg słów kluczowych + leg wektorowy, fuzja przez reciprocal-rank,
  uczciwa o tym, kiedy leg wektorowy nie zadziałał (limit AI, wyłącznik, awaria dostawcy) zamiast
  udawać błąd lub ciszej zwracać mniej wyników bez wyjaśnienia.
- **Graf** — widok całej bazy (huby) i widok ego (sąsiedztwo jednego wpisu), deterministyczny layout
  SVG bez zewnętrznej zależności (`knowledgeGraphLayout.ts`, czysta funkcja).
- **Powiązanie z botem** — `PUT`/`DELETE /bots/{bot}/knowledge-binding` + jednorazowa migracja
  wbudowanej wiedzy bota do prawdziwej bazy (`POST /bots/{bot}/knowledge/migrate`); powiązanie ma
  **pierwszeństwo bezwzględne** nad starym polem `bots.knowledge` — kolumna zostaje jako trwały
  fallback dla niezmigrowanych botów, bez ustalonego terminu wygaszenia.
- **Usuwanie danych osoby (RODO, część)** — `php artisan knowledge:purge-subject`: skanuje i
  (na żądanie, `--apply`) usuwa wszystko w module Wiedza noszące podane frazy (wpisy, historię
  wersji, embeddingi, czerwone linki); dry-run domyślny, limit szerokości dopasowania, potwierdzenie
  wpisywane ręcznie. **Zasięg tylko Wiedza** — nie jest to pełne prawo do bycia zapomnianym w całej
  aplikacji (osobny, większy temat).

**Etap: Kreator AI — status: ukończone.** Właściciel przejrzał manualny edytor po zbudowaniu R1/R2 w
innych modułach i zdecydował o pivocie: wpisy **nie powstają już ręcznie** — „Nowy wpis" w całej
aplikacji (czytnik, tabela, graf, czerwony link) otwiera kreator AI zamiast pustego formularza; edytor
zostaje wyłącznie do edycji **istniejących** wpisów. Trzy decyzje właściciela zapisane wprost:
AI-only w warstwie UI (nie w API — `POST /entries` zostaje, bo to ta sama ścieżka, którą wywołuje
publikacja szkicu), zmiana istniejącego wpisu publikuje się od razu (bez dodatkowej bramki akceptacji
ponad tę, którą MVP już miało — każdy członek workspace'u mógł i może edytować dowolny wpis), oraz
osobny kanał licznika kosztu AI (`ai_knowledge`) tak, żeby wydatek kreatora dało się odróżnić w
raporcie zużycia od innych powierzchni tekstowych. Pełny kontrakt:
`docs/backend/knowledge-api.md` → „The AI composer". Zapis decyzji:
`docs/decisions/ADR-0046-knowledge-ai-composer.md`.

- **Sesja kreatora** — jeden tekst źródłowy wklejony przez użytkownika → agent (jedno metrowane
  wywołanie na sesję/poprawkę) → 1–8 proponowanych wpisów (`status: proposed`) do przeglądu.
  Szkic jest **zwykłym wierszem `knowledge_entries`** niosącym `draft_session_id` — niewidzialny
  wszędzie w produkcie przez globalny scope modelu (`WithoutDraftsScope`), z jednym, jawnym wyjątkiem
  (`withDrafts()`) w samym kreatorze. Akceptacja to jeden zapis kolumny, nie kopiowanie wiersza.
  Sesja settluje się na zdarzeniu realtime (`knowledge-draft-session.updated` na kanale
  `knowledge.workspace.{workspaceId}`) — **zero pollingu**, zgodnie ze stojącym wymogiem właściciela.
- **Nowelizacje (szkice-cienie)** — kreator, widząc zamrożony na starcie sesji kontekst (istniejące
  wpisy, których wklejony materiał może dotyczyć), może zaproponować AMENDMENT zamiast nowego wpisu:
  `targets_entry_id` + zamrożona `target_revision_id` (rewizja, którą model faktycznie widział),
  odtwarzana jako token blokady optymistycznej przy akceptacji — człowiek, który edytował target w
  międzyczasie, dostaje konflikt zamiast cichego nadpisania. Cel spoza zamrożonej listy degraduje się
  do zwykłego „stwórz", nigdy nie ginie po cichu. Adres szkicu-cienia jest zarezerwowany
  (`__shadow-<ulid>`) i pilnowany bazodanowym `CHECK`-em, nie tylko kodem aplikacji.
- **Podgląd powiązań i diff** — panel powiązań to REUSE tego samego kanwasu/layoutu grafu (nie druga
  wizualizacja): węzeł-szkic ma `is_draft`, wpis-nowelizowany ma `amended_by` (adnotacja, nie
  krawędź). Panel diffa porównuje trzy możliwe bazowe wersje (oryginał / poprzedni szkic / wersja w
  bazie — trzecia wyłącznie dla nowelizacji) przez współdzielony komponent `TextDiffView`.
- **Usuwanie danych osoby — rozszerzone.** Sesje kreatora (surowy tekst źródłowy + historia
  poleceń poprawek) i nieprzyjęte szkice są teraz w zasięgu `knowledge:purge-subject` — trafienie
  frazy w sesję porzuca ją w całości (jedyna uczciwa granulacja dla wklejonego, nieindeksowanego
  tekstu). Naprawiona asymetria z pierwszej wersji skanu (szkic-wpis był niewidzialny, ale jego
  historia rewizji już nie — usuwanie kasowało historię i zostawiało żywy, nieoznaczony tekst szkicu).

**Etap: Wiki-Graf (relacje typowane) — status: ukończone.** Osobna tabela `knowledge_relations` (nie
kolumna/`source` w `knowledge_links` — link to kasowany-i-odtwarzany CACHE, relacja jest opłacona i
zatwierdzona przez człowieka i żaden sweep nie może jej zmieść) + własny, append-only log zdarzeń
(`knowledge_relation_events`, celowo NIE moduł Changelog — cztery niezależne powody, pierwszy
rozstrzygający: `changelogs` nie ma lustra tenantowego). Zamknięty słownik **15 typów relacji** w kodzie
(allow-lista per baza jako podzbiór) + **8 typów encji** wyprowadzonych wstecz z czasowników, jakich
używają relacje (`work` ≠ `product`, bo biorą inne czasowniki; `null` ≠ `other` — null to „nikt nie
powiedział", `other` to świadoma odpowiedź). Macierz par typów jest **doradcza**, gdy typ któregoś końca
jest nieznany — odrzuca tylko, gdy OBA końce są typowane i para jest spoza macierzy; świadome
odstępstwo od strict-mode reszty modułu, bo wszystkie istniejące wpisy mają dziś `null`, a twarde
odrzucanie kasowałoby poprawne relacje z powodu brakującej klasyfikacji. LLM **nigdy nie usuwa** —
kontrakt maszynowy zna wyłącznie `create`/`update`/`end`; `retract` („to nigdy nie było prawdą", wiersz
PRZETRWA) i twarde, nieodwracalne `DELETE` są afordancją wyłącznie człowieka, z osobnego panelu relacji.
Graf zyskał piątą warstwę krawędzi (`edges[].kind: 'relation'`, dyskryminator, nie druga wizualizacja).
Bramka przeglądu obejmuje WSZYSTKO (decyzja właściciela) — żadnego trybu auto-akceptacji, ścieżka
zapisu jest dokładnie ta, co przed etapem. Usuwanie danych osoby rozszerzone o szóstą powierzchnię:
relacja, której własny `description`/`properties` nazywa podmiot, niezależnie od tego, czy któraś z
dwóch encji, które łączy, w ogóle o nim wspomina. Pełny kontrakt: `docs/backend/knowledge-api.md` →
„Typed relations". Zapis decyzji: `docs/decisions/ADR-0047-knowledge-typed-relations.md`.

**Świadomie odłożone po Wiki-Grafie (kandydaci na kolejny podetap, nie zaplanowane):**
1. **Faza wykrywania relacji jako osobny, metrowany krok.** Dziś ekstrakcja relacji dzieje się WEWNĄTRZ
   zwykłej kompozycji/poprawki — to samo wywołanie, które proponuje wpisy, proponuje też `graph_ops`. Nie
   ma osobnego przycisku, osobnego wydatku ani pola-odpowiednika `context_expanded_at`
   (`relations_detected_at` nie istnieje). Wzorowany na `expand-context` osobny krok „wykryj relacje" —
   z własnym, jawnym kosztem, uruchamiany na żądanie zamiast przy każdej generacji — jest realnym
   kandydatem na kolejny podetap, nie przybliżeniem tego etapu.
2. **Scalanie zduplikowanych encji.** Moduł nie ma dziś żadnego mechanizmu wykrywania ani łączenia
   dwóch wpisów, które w rzeczywistości opisują tę samą osobę/organizację/rzecz — poza tym, co panel
   duplikatów kreatora (próg podobieństwa wektorowego) i tak już ostrzega przy tworzeniu NOWYCH wpisów.
   Zewnętrzny research (`docs/ai/reference-links.md` → Knowledge module, wpis 8) notuje konkretną,
   sourcowaną receptę na przyszłość (podobieństwo embeddingu ~0.97 + odległość edycyjna, scalanie
   wyłącznie zatwierdzane przez człowieka) — nieprzyjętą teraz, bo moduł nie ma dziś problemu
   duplikatów encji do rozwiązania.
3. **Wnioskowanie po grafie relacji.** Typowane relacje są dziś wyłącznie tym, co ktoś jawnie zapisał —
   nic nie wyprowadza tranzytywnych ani pośrednich faktów z istniejących relacji (np. „A pracuje nad
   projektem B, B jest częścią C, więc A pośrednio pracuje nad C"). Żaden krok nie odpytuje grafu w ten
   sposób, ani przy kompozycji, ani przy odczycie.
4. **Community detection / klastrowanie grafu.** Zwykły (bez LLM) Leiden clustering jest zapisany jako
   opcja w rezerwie na przyszły tryb przeglądania/klastrowania (`docs/ai/reference-links.md` → wpis 7),
   niezależnie od pytania o ekstrakcję relacji — nie zaplanowany, nie zbudowany. Pełny GraphRAG
   (podsumowania społeczności, hierarchiczne raporty Leiden) jest świadomie odrzucony jako
   nieproporcjonalny do skali mini-wiki per-workspace.

**Świadomie odłożone po Kreatorze AI (kandydaci na kolejny podetap, nie zaplanowane):**
1. **Estymata kosztu pojedynczej operacji.** Serwer nigdy nie zwraca „ten przebieg będzie kosztować
   ~$X" — tylko stan budżetu workspace'u (wykorzystanie/limit/data odnowienia). Świadomie: długość
   odpowiedzi modelu nie jest znana z góry, a wymyślona liczba szkodzi bardziej niż jej brak.
2. **Afordancja wielu równoległych nowelizacji jednego wpisu.** Drut już to wyraża
   (`amended_by` to lista), ale UI czyta wyłącznie pierwszy element listy przy akcji „otwórz
   nowelizację" — dwie sesje proponujące zmianę tego samego wpisu naraz są dziś rzadkie, ale kształt
   odpowiedzi już na nie czeka.
3. ~~**LLM-owa ekstrakcja relacji.**~~ **ZBUDOWANE w etapie Wiki-Graf (patrz wyżej), ta pozycja jest
   nieaktualna.** W momencie spisania tej listy żaden krok nie pytał modelu o typowane relacje między
   encjami wcale — dziś ten sam wywołanie, które proponuje wpisy, proponuje też `graph_ops` (relacje z
   zamkniętego, 15-werbowego słownika, laundrowane w kodzie, nigdy nie ufając własnej pewności modelu).
   Nieaktualne, bo **niedotyczące** typowanych relacji, jest wciąż: `podgląd powiązań kreatora`
   (`GET …/draft-sessions/{session}/relations`, panel `KnowledgeDraftRelationsPanel.vue`) — krawędzie
   MIĘKKIE między szkicami (podobieństwo/wzmianki/wikilinki) liczy DALEJ wyłącznie deterministycznie i
   wektorowo, bez osobnego rozumowania modelu „czy te dwa fragmenty mówią o tym samym" — to jest inny
   mechanizm niż typowane relacje i pozostaje bez zmian.
4. **Propozycje zmian inicjowane automatycznie, nie z ręcznie wklejonego materiału.** Kreator dziś
   startuje wyłącznie z decyzji człowieka, który coś wkleja do pola źródłowego. Nic w tym module nie
   proponuje nowelizacji z własnej inicjatywy — na podstawie zakończonego zadania bota, przebiegu
   workflow czy innej aktywności platformy. To istotnie większa funkcja niż samouzupełnianie pola
   tekstowego i naturalnie łączy się z punktami 2–3 poniższej listy Etapu 2 (krok workflow, web-research).

**Świadomie odłożone na Etap 2 (kolejny podetap Wiedzy, do zaplanowania osobno):**
1. **Śluza propozycji** — dziś każdy członek workspace'u może od razu zapisać/edytować wpis; etap 2
   ma dodać opcjonalny przepływ „propozycja → akceptacja" dla baz, które tego wymagają (dziś status
   `proposed` istnieje w słowniku, ale nic go nie bramkuje — patrz `KnowledgeEntryStatus` w
   `docs/backend/knowledge-api.md`).
2. **Krok workflow czytający/piszący do Wiedzy** — dziś jedynym konsumentem jest Bot; Workflows
   (krok w stylu `read_knowledge`/`propose_entry`) to naturalne następne rozszerzenie tej samej
   krawędzi konsumpcji (`KnowledgeBindingService` już jest zaprojektowane pod wielu konsumentów).
3. **Web-research** — automatyczne zasilanie bazy z zewnętrznych źródeł (np. przez istniejące
   narzędzie `fetch_url`/`web_search` bota) zamiast wyłącznie ręcznego wpisywania.
4. **Ingestia z Dysku** — import pliku (PDF, dokument tekstowy) z modułu Disk jako gotowy wpis lub
   zestaw wpisów, zamiast kopiowania treści ręcznie.
5. **Tryb „odpowiedz"** — interaktywny tryb pytań do bazy wiedzy (czat/Q&A) ponad istniejącym
   wyszukiwaniem hybrydowym, zamiast tylko listy wyników.
6. **CommandPalette** — szybkie wyszukiwanie/skok do wpisu wiedzy z globalnej palety poleceń
   aplikacji (dziś wyszukiwarka Wiedzy jest osobnym ekranem).

**Ryzyka:** operacyjne, nie produktowe — patrz `docs/backend/knowledge-api.md` → „Production caveat"
(rozszerzenie `pgvector` wymaga uprawnień superusera; do potwierdzenia przez DBA przed pierwszym
wdrożeniem produkcyjnym) i ADR-0045 → „Consequences — known limitations" (re-migracja bota osiera-ca
starą bazę zamiast ją scalać; `rag` nie ma własnego progu podobieństwa; koszowana-i-przywrócona baza
nie sygnalizuje w UI, że kiedykolwiek była w koszu).

---

## R3. Kalendarz (Etap 8)

**Status: backend zbudowany.** Pełny kontrakt: `docs/backend/calendar-api.md`. Zapis decyzji:
`docs/decisions/ADR-0051-calendar-module-design.md`.

**Cel:** wspólna oś czasu dla całej platformy.

**Backend:** moduł NISKI z rejestrem źródeł (`CalendarSource` + `CalendarSourceRegistry`) —
Kalendarz **nie zna** żadnego modułu źródłowego; każdy moduł, który ma coś do pokazania, sam
implementuje kontrakt i sam się rejestruje ze swojego providera (przepis krok po kroku:
`docs/backend/calendar-api.md` → „The Calendar knows nobody — how to add a new source"). R4
Publikacje dołożą się jako piąte źródło bez zmiany ani jednej linii w `app/modules/Calendar`
— przypięte testem (`CalendarModuleBoundaryTest`).

**Poprawka względem pierwotnego sformułowania powyżej:** „powiązanie polimorficzne ze
źródłem" było mylącym sformułowaniem i zostało w praktyce ODRZUCONE (patrz ADR-0051, decyzja
D2) — prowadziłoby wprost do materializacji projekcji cronem do wierszy, czyli dokładnie
tego, przed czym ostrzega akapit Ryzyka poniżej. To, co faktycznie powstało: model
wydarzenia (tytuł, opis, rozróżnik całodniowe-dzień/chwila-UTC — **nigdy nie konwertowany
strefowo**, kolor z zamkniętego słownika) ze wskaźnikiem `subject_type`/`subject_id` jako
**miękkim linkiem** — nullable, bez `morphTo()`, nigdy niededereferencjonowanym przez
Kalendarz — plus mapowanie ISTNIEJĄCYCH modeli na wystąpienia przez ten sam kontrakt: task
z deadlinem (moduł Tasks), zaplanowane uruchomienia workflow jako READ-ONLY projekcje **tylko
w przyszłość** (moduł Workflows) ORAZ realne uruchomienia jako historia **tylko w przeszłość**
— dwa OSOBNE źródła, bo jedna projekcja kłamałaby o historii (workflow mógł zostać
wyłączony/edytowany, harmonogram ma doktrynę zużytego slotu).

**Frontend:** *nie zbudowany w tym batchu* — widok miesiąca/agendy, kreator wydarzenia i
nawigacja do obiektów źródłowych pozostają do zrobienia (backend→UX/UI→frontend zgodnie z
kolejnością refaktoru z CLAUDE.md).
**Workflow:** krok `create_event` ✅ zbudowany (kontrakt: `docs/backend/workflows-api.md` →
„Steps" → `create_event`); trigger czasowy już był (harmonogram z 5.1) — kalendarz tylko
**wizualizuje** też przyszłe odpalenia harmonogramów, nigdy nie duplikuje wyzwalacza: **żaden
trigger nie czyta `calendar_events`** — ogrodzenie definicyjne wpisane w model (ADR-0051,
decyzja D4): wydarzenie to adnotacja na osi czasu, nic nigdy nie wykonuje się dlatego, że
wydarzenie istnieje.
**Świadomie POZA zakresem, nie z oszczędności:** cykliczność wydarzeń — wymagałaby drugiego
silnika rekurencji obok kompilatora harmonogramów, który mieszka w Workflows, a którego
Kalendarz nie może nazwać (ADR-0051, decyzja D5). Kto potrzebuje cyklicznej adnotacji, ma już
narzędzie: workflow na harmonogramie z krokiem `create_event`.
**Później (backlog):** import świąt/eventów zewnętrznych jako inspiracje dla kampanii; kosz
wydarzeń ma tylko soft-delete, bez ekranu przywracania (endpoint bez ekranu byłby kontraktem
trzymanym za darmo — do zrobienia razem z UI).

**Ryzyka:** zaadresowane, nie „niskie z założenia" — kalendarz jest PROJEKCJĄ (rejestr +
zapytanie o ograniczone okno przy każdym odczycie), nigdy drugim źródłem prawdy: brak
jakiejkolwiek materializacji cronem do wierszy (dokładnie ten scenariusz, przed którym
ostrzegał ten akapit, został rozważony i odrzucony — ADR-0051), żaden trigger nie odczytuje
`calendar_events`. Koszt odczytu „na żywo" (setki automatyzacji projektowanych przy każdej
nawigacji miesiąca) zaadresowany osobno filtrem `next_due_at` + limitem pozycji per źródło,
NIE cache'owaniem kompilacji harmonogramu (próbowano, zmierzono, usunięto — kompilacja to
<2% kosztu projekcji; liczby i uzasadnienie w ADR-0051, decyzja D8). Otwarty (nieautomatyczny)
punkt: ogrodzenie „żaden trigger nie czyta wydarzeń" pilnowane dziś tylko przeglądem kodu, nie
testem granicznym.

---

## R4. Publishing Hub (Etap 9)

**Cel:** treść wychodzi z aplikacji w świat i wraca jako statystyki.

**Podetapy:**
1. **Model publikacji:** mapowanie wyniku Generatora na publikację per platforma
   (tytuł, opis, tagi, miniaturka, format); statusy: draft → scheduled → publishing →
   published/failed.
2. **Konta platform:** panel integracji w Ustawieniach (OAuth), bezpieczne przechowywanie
   tokenów, widok podglądu kont. Start: **YouTube + Instagram/Facebook** (decyzja otwarta
   nr 5), architektura adapterowa pod TikTok/X.
3. **Kolejka:** zaplanowane publikacje (+ miejsce na AI-sugestie terminów), failed
   z powodem i przyciskiem retry.
4. **Historia + statystyki:** pomyślne publikacje, zbieranie metryk (wyświetlenia,
   reakcje), streszczenie AI, porównanie z innymi treściami, sugestie.
5. **Workflow:** krok `publish` + **trigger `approval_completed`** („treść zatwierdzona →
   publikuj") — brakujące ogniwo automatyzacji end-to-end z wizji.

**Ryzyka:** najwyższe w planie — zewnętrzne API (przeglądy aplikacji dev u platform,
limity, wygasanie tokenów); zaplanować konta testowe/sandbox wcześnie, bo proces przeglądu
u Mety/Google potrafi trwać tygodnie. Joby publikacji: idempotencja + retry z backoff
(reuse wzorców run-managera z modułu Bot).

---

## R5. Kampanie (Etap 10)

**Cel:** „ustaw raz, publikuje się samo" — agregator nad Workflows.

**Decyzja architektoniczna (planning-agent, przed kodem):** kampania = domenowa nakładka
na Workflows (schedule-trigger + generate_content + akceptacja + publish), a nie osobny
silnik. Kreator kampanii pyta o: platformę, główny zamysł (brief), częstotliwość,
przypięty pipeline akceptacji i templatkę — i **kompiluje to do workflow**.

**Zakres:** kreator + lista kampanii ze statusami; widok szczegółowy z historią
wygenerowanych treści; filtrowanie Publishing Hub po kampanii; widok analizy kampanii
(dane z R4); pauza/wznowienie kampanii.

**Ryzyka:** przeciek abstrakcji (użytkownik edytuje workflow „pod spodem" i psuje
kampanię) — zdefiniować własność: kampania zarządza swoim workflow jako zasobem ukrytym.

---

## R6. Dashboard + Powiadomienia + Analityka przekrojowa

**Cel:** widoczność operacyjna dla całej platformy.

1. **Powiadomienia (najpierw):** model + dzwonek + centrum powiadomień; zdarzenia:
   „czeka na Twoją akceptację", „bot ukończył zadanie", „publikacja failed", „workflow
   error", zaproszenia. Start: in-app (polling); realtime/e-mail — później.
2. **Dashboard:** widgety per brainstorm — moje zadania, oczekujące akceptacje, nadchodzące
   terminy/kampanie (z Kalendarza), aktywność postaci, sugestie AI, ostatnio otwierane.
   Zastępuje placeholder `DashboardView.vue`.
3. **Analityka przekrojowa (feedback loop):** panel skuteczności ponad Publishing Hub
   (kampanie/kanały/treści), rekomendacje AI („co działa i dlaczego"), raporty.

**Ryzyka:** niskie technicznie; dashboard tylko z realnych danych (bez wydmuszek).

---

## R7. Custom Quality (Etap 11 — wymaga sesji planistycznej)

**Proponowany zakres (do potwierdzenia):**
- **Sandbox postaci:** czat testowy z botem odseparowany od produkcji, historia testów.
- **Trening stylu:** dodawanie przykładowych wypowiedzi (tweety, komentarze) → strojenie
  stylu; podgląd zmian stylu przed/po.
- **Kryteria jakości:** definiowalne wytyczne jakości treści per workspace/kampania,
  używane przez AI-recenzenta w akceptacjach (stąd nazwa „custom quality").
- ~~**Moduł wizualny bota:** zdjęcie placeholdera — generowanie wyglądu (warianty).~~ —
  **ZBUDOWANE WCZEŚNIEJ niż planowano**, w R2 zamiast tutaj: moduł „Wygląd" (tożsamość obrazowa,
  spójność obraz-do-obrazu dla sesji delegowanych do bota) — patrz sekcja R2 „silnik rozproszonych
  kadrów storyboardu + moduł »Wygląd« bota" wyżej, `ADR-0042`. Kept struck through so a reader of an
  older snapshot understands the change.
- **Moduł głosowy bota:** zdjęcie placeholdera — głos (ElevenLabs). Wciąż w R7 — *albo tutaj, albo
  osobny rozdział; decyzja na planowaniu.*

---

## R8. Uniwersum (Etap 12) + backlog wizji

**Uniwersum (wymaga sesji planistycznej):** światy → miejsca, postacie (powiązane
z botami!), historie; edytor scenariuszy (Tiptap już w stacku) z wersjonowaniem i diffem;
kontynuacja fabuły z pamięcią narracyjną; generator scenariusza → wejście dla Generatora
treści (wideo-scenariusz).

**Backlog (kolejność do ustalenia po R8):** Trendy & Inspiracje → Monitoring komentarzy →
Muzyka (typ „audio" w Generatorze + edytor) → Streamy (shoty, podsumowania, AI-widzowie) →
Kompozytor multimedialny → wielojęzyczność treści → eksporty PDF/DOCX/ID3.

---

# CZĘŚĆ III — ZASADY WYKONAWCZE (każdy rozdział)

1. **Sekwencja:** planning-agent → akceptacja planu → backend → UX/UI → frontend → testy →
   docs → reviewer-agent → commit/PR. Małe diffy, podetapy jako osobne PR-y.
2. **Definicja ukończenia rozdziału:** testy backend + frontend zielone; i18n PL/EN
   kompletne; FilterBar + zapisane widoki na nowych listach; lustra tabel tenant; strona
   w docs + ADR dla decyzji; wpis w changelogu; review zaliczone; **zacommitowane**.
3. **Workflow-first:** każdy moduł kończy się pytaniem „jakie triggery/warunki/kroki
   dokłada do Workflows?" — to miara integracji.
4. **Koszty AI:** od R2 każda funkcja AI raportuje koszt i respektuje limity workspace'u.
5. **Bez nowych pakietów bez uzasadnienia; bez równoległych implementacji** — najpierw
   szukamy istniejącego mechanizmu (Disk, Changelog, FilterTabs, run-manager z Bot,
   typowane zmienne z Workflows).
