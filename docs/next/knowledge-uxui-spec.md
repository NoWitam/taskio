# Moduł Wiedza (Knowledge) — specyfikacja UX/UI

> **Batch B3** planu modułu Wiedza. Dokument jest kontraktem dla `frontend-agent`
> (batche B4 / B5 / B5b). Wszystko, co tu opisano, ma być zbudowane z **istniejących**
> komponentów `resources/js/next/ui/**` — każde odstępstwo jest jawnie nazwane
> i uzasadnione w sekcji [1.4 Decyzje projektowe](#14-decyzje-projektowe).
>
> **Nic w tym dokumencie nie jest logiką biznesową ani kontraktem backendu.**
> Miejsca, w których UI zakłada kształt danych, są zebrane w sekcji
> [19. Pytania do backendu](#19-pytania-do-backendu-kontrakt-do-potwierdzenia) —
> frontend **nie wymyśla** kluczy payloadu (znany błąd z Etapu 5, patrz pamięć projektu).

---

## Spis treści

1. [Fundamenty](#1-fundamenty)
2. [Architektura informacji](#2-architektura-informacji)
3. [Ekran 1 — Lista baz wiedzy](#3-ekran-1--lista-baz-wiedzy)
4. [Ekran 2+4 — Czytnik wiki i karta wpisu (jedna powierzchnia)](#4-ekran-24--czytnik-wiki-i-karta-wpisu-jedna-powierzchnia)
5. [Ekran 3 — Tryb tabeli wpisów](#5-ekran-3--tryb-tabeli-wpisów)
6. [Ekran 5 — Edytor wpisu](#6-ekran-5--edytor-wpisu)
7. [Ekran 6 — Ustawienia bazy](#7-ekran-6--ustawienia-bazy)
8. [Ekran 7 — Graf](#8-ekran-7--graf)
9. [Ekran 8 — Wyszukiwarka](#9-ekran-8--wyszukiwarka)
10. [Ekran 9 — Kosz](#10-ekran-9--kosz)
11. [Wzorce przekrojowe: stany](#11-wzorce-przekrojowe-stany)
12. [Badge'e i mapy statusów](#12-badgee-i-mapy-statusów)
13. [Responsywność](#13-responsywność)
14. [Dark mode i kontrast](#14-dark-mode-i-kontrast)
15. [Dostępność — checklista](#15-dostępność--checklista)
16. [Ikony](#16-ikony)
17. [Inwentarz komponentów](#17-inwentarz-komponentów-reuse--extend--create)
18. [Załącznik: klucze i18n (PL + EN)](#18-załącznik-klucze-i18n-pl--en)
19. [Pytania do backendu](#19-pytania-do-backendu-kontrakt-do-potwierdzenia)
20. [Czego NIE ma w MVP](#20-czego-nie-ma-w-mvp)
21. [Ryzyka spójności](#21-ryzyka-spójności)
22. [Handoff do frontend-agent](#22-handoff-do-frontend-agent)
23. [Erraty po implementacji](#23-erraty-po-implementacji)
24. [Ekran 10 — Kreator AI (`compose`)](#24-ekran-10--kreator-ai-compose) ← **B13**
25. [Errata B13 — konwersja modułu na AI-only](#25-errata-b13--konwersja-modułu-na-ai-only) ← **B13**
26. [Errata B15a/B15b — kontrakt kreatora zweryfikowany w zbudowanym kodzie](#26-errata-b15ab15b--kontrakt-kreatora-zweryfikowany-w-zbudowanym-kodzie) ← **B15a/B15b**
27. [Wiki-Graf — typowane relacje semantyczne](#27-wiki-graf--typowane-relacje-semantyczne) ← **G6**
28. [Errata G9 — Wiki-Graf zweryfikowany w zbudowanym kodzie](#28-errata-g9--wiki-graf-zweryfikowany-w-zbudowanym-kodzie) ← **G9**

> **B13 — pivot na AI-only.** Wpisy **nie powstają już ręcznie**: „Nowy wpis" otwiera **kreator**
> (§24), a edytor (§6) obsługuje wyłącznie **edycję istniejących** wpisów. Sekcje 1–22 opisują stan
> sprzed pivotu i **nie są przepisywane wstecznie** — pełny wykaz różnic jest w §25.1.

---

## 1. Fundamenty

### 1.1 Słownik (PL ↔ dane)

| PL (UI) | EN (kod / klucz) | Co to jest |
| --- | --- | --- |
| Baza wiedzy | knowledge base | Twardy kontener. Ma **kartę tożsamości** (charter), **schemat metadanych** (deskryptor) i **język**. |
| Karta tożsamości | charter | Jedno pole tekstowe opisujące bazę: czym jest, dla kogo, ton, zakres, czego nie zawiera. |
| Schemat metadanych | metadata schema | `VariableDescriptorField[]` — lista `{ key, label, descriptor }`. |
| Wpis | entry | Tytuł + treść markdown (≤ 40 000 znaków) + metadane + status + flaga „nieaktualny". |
| Wikilink | wikilink | `[[slug]]` lub `[[slug\|etykieta]]` w treści. |
| Czerwony link / duch | ghost link | Wikilink do nieistniejącego sluga. |
| Indeks semantyczny | semantic index | Async liczenie embeddingów na fragmentach (chunkach). |
| Fragment | chunk | Kawałek wpisu, na którym liczony jest indeks; ma ścieżkę nagłówków. |
| Krawędź | edge | Relacja wpis→wpis: `wikilink` / `similarity` / `manual`. |
| Powiązanie z botem | binding | Sposób, w jaki konsument AI dostaje wiedzę. |

### 1.2 Doktryna produktu (musi być słyszalna w copy)

1. **Wpis = hasło encyklopedyczne, nie dokument.** Jeden temat, jedno hasło.
   Dłuższy materiał dzielimy na hasła i łączymy wikilinkami.
2. **Baza to nie folder.** Baza ma tożsamość (karta) i wspólny schemat metadanych.
   Wpis bez bazy nie istnieje.
3. **Linkuj, nie kopiuj.** Czerwony link jest zaproszeniem, nie błędem.
4. **Indeks jest asynchroniczny i kosztuje.** Użytkownik zawsze widzi, na jakim
   etapie jest indeks i dlaczego stoi.

### 1.3 Twarde reguły projektu, których ta specyfikacja przestrzega

| Reguła | Jak zastosowana |
| --- | --- |
| Ikona modułu na jego stronie: biało-na-primary | `PageHeader` renderuje bąbel `bg-next-primary text-next-primary-foreground` **z automatu** — wystarczy podać `icon`. **Nie ma propa `iconClass`; nie dodawaj własnej klasy.** |
| Każdy ekran listy: `FilterBar` + `FilterTabBar` w `#top` | Ekrany 1, 3, 8, 9 (patrz wyjątek D14 dla Kosza). |
| Skeletony imitują realny element, kilka sztuk | Sekcja 11.1 — osobny kształt dla artykułu, grafu, wiersza, kafla. |
| Akcje przez `Button` (nigdy `<button>`) | Wszędzie, w tym zamykanie modali/drawerów. Kompaktowe: `size="icon-sm"` / `icon-xs`. |
| Kolejność afordancji trailing | Warunkowy `✕` **przed** stałym chevronem/kebabem. |
| Wszystko i18n, PL + EN | Załącznik 18. `en.ts` jest źródłem typu (`MessageSchema`), `pl.ts` musi mieć 1:1 te same klucze — inaczej `vue-tsc` i test parity padają. |
| Kolor nigdy jedynym sygnałem | Każdy badge = ikona/kropka + tekst; typy krawędzi w grafie = wzór linii, nie kolor. |
| Importy **względne** | `import PageHeader from '../../ui/patterns/PageHeader.vue';`. **`@next/*` istnieje w `tsconfig`, ale NIE ma aliasu w Vite — nie używać.** |

### 1.4 Decyzje projektowe

Każda decyzja: **co**, **dlaczego**, **co odrzucono**.

---

**D1 — Moduł jest „shellem z aside", a nie płaską listą stron.**
Baza wiedzy jest **zasobem** w rozumieniu `ui/layout/ModuleAside.vue` (dokładnie jak bot
w `BotsModuleLayout.vue` czy sesja w `GeneratorModuleLayout.vue`). Blok modułu: *Bazy wiedzy*,
*Szukaj*, *Kosz*. Blok zasobu (po otwarciu bazy): *Czytnik*, *Tabela*, *Graf*, *Ustawienia*.
→ Zero nowego wzorca nawigacji; `ModuleTabs` daje fallback poniżej `next-lg` za darmo.
*Odrzucone:* własny pasek zakładek na stronie bazy — duplikowałby `ModuleAside` i łamał ADR-0011.

**D2 — Sekcje bazy to CHILD ROUTES, nie `?section=`.**
Zgodnie z `app/router/sectionRedirect.ts` i wzorcem workflows/bots.
Nazwa bazowa `next.knowledge.base` przekierowuje na domyślną sekcję `reader`.
→ Deep-linki działają, stary `?section=` też (gdyby powstał).

**D3 — Spis treści w czytniku jest PŁASKI, bez `ui/data/Tree.vue`.**
Kolejność = `position`; opcjonalne **grupowanie po jednym polu metadanych typu `enum`**
(wybór w nagłówku spisu). Powód: dane wpisów są płaskie — nie ma pola „rodzic".
Drzewo obiecywałoby hierarchię, której model nie ma, a `Tree.vue` nie ma dziś **żadnego
produkcyjnego użycia** (tylko galeria) — pierwsze użycie w module o tak dużej
powierzchni to zły moment na debiut.
*Odrzucone:* `Tree.vue` z grupami jako węzłami-rodzicami — to drzewo o głębokości 2,
czyli lista z nagłówkami, przebrana za drzewo.

**D4 — Wikilink zostaje ZWYKŁYM TEKSTEM w markdownie.**
Żadnego nowego węzła Tiptap, żadnej rejestracji w `markdown.ts`.
Powody: (a) treść zostaje przenośna i „greppowalna", (b) backend i tak parsuje `[[…]]`,
żeby zbudować krawędzie — dwie prawdy o linku byłyby rozjazdem, (c) zero ryzyka
dla kontraktu FORMAT (dyrektywy `@[…]`), (d) kopiuj-wklej między wpisami działa.
*Odrzucone:* atomowy węzeł `wikilink` jak `mention` — droższy, wymaga serializacji,
a wartość (chip zamiast tekstu) jest kosmetyczna.
*Opcjonalnie w B5b (nie MVP):* dekoracja ProseMirror (`Decoration.inline`) podświetlająca
zakresy `[[…]]` bez zmiany dokumentu.

**D5 — Renderowanie wikilinków = addytywny, domyślnie WYŁĄCZONY prop `wikilinks`
w `ui/editor/MarkdownViewer.vue`.**
Transformacja musi zajść **po** `DOMPurify.sanitize`, wewnątrz istniejącego potoku:
`ALLOWED_ATTR` nie zawiera `class` ani `data-*` (`ALLOW_DATA_ATTR: false`), więc żaden
`<a data-wikilink>` wstrzyknięty *przed* sanitizacją nie przetrwa. Kotwice budujemy
**programowo** (`document.createElement('a')` + `textContent`) już po sanitizacji, więc
nie osłabiamy zabezpieczeń ani o jotę.
*Odrzucone:* fork sanitizera do modułu Wiedza (dryf konfiguracji bezpieczeństwa).
*Odrzucone:* podmiana tekstu w DOM po renderze z komponentu-wrappera — miga surowy
`[[slug]]` przy każdym re-renderze `v-html` i walczy z Vue.
**Wymóg regresyjny:** przy `wikilinks` nieustawionym wyjście `MarkdownViewer` musi być
**bajt w bajt** takie jak dziś (test przypina).

**D6 — Podgląd na hover to JEDEN, zawsze zamontowany panel, nie `Tooltip.vue`.**
Kotwic nie da się owinąć komponentem — powstają z `v-html`. Wzorzec: dokładnie ten
z `ui/editor/extensions/mention.ts` — syntetyczna kotwica zwracająca `getBoundingClientRect()`
elementu + `useAnchoredPosition`. Delegowane listenery `pointerover` / `pointerout` /
`focusin` / `focusout` na kontenerze artykułu.
→ **Otwiera się także z klawiatury** (`focusin`), co `Tooltip` dałby, ale tylko dla
owiniętych triggerów.

**D7 — Metadane jadą na `VariableDescriptor` + `ui/variables/TypedLiteralInput.vue`,
nie na `InputRenderer` z modułu Forms.**
`InputRenderer` służy definicji formularza (sekcje, walidacja, warunki, wysyłka).
Tutaj mamy **typowany literal na pole** — to jest dokładnie model, który obsługuje
`TypedLiteralInput` (`text → TextInput`, `number → NumberInput`, `boolean → Switch`,
`date → DatePicker`, `enum → Select`). Wzorzec złożenia: gałąź `object` w
`pages/generator/session/SlotValuesForm.vue` (linie 102–120).
*Odrzucone:* Forms — inny model danych, inny cykl życia, natychmiastowy dług.

**D8 — Graf: deterministyczny layout SVG na tokenach, bez fizyki i bez zależności.**
`package.json` nie ma d3/cytoscape/elk/dagre i nie będzie miał. Layout = **czysta funkcja**
`knowledgeGraphLayout.ts` (testowalna Vitestem). Determinizm jest wymaganiem UX:
ten sam graf ma wyglądać tak samo po odświeżeniu, inaczej użytkownik traci mapę mentalną.
Symulacja siłowa dodatkowo animuje bez końca, co łamie `prefers-reduced-motion`.
*Odrzucone:* force-directed (nowa zależność albo 200 linii niedeterminizmu).

**D9 — Graf NIGDY nie jest jedynym nośnikiem treści.**
SVG jest `aria-hidden="true"` + `focusable="false"`. Prawdziwą powierzchnią dostępności
jest **równoległa lista sąsiadów** (`role="listbox"`, `aria-activedescendant`, wzorzec
`ui/variables/VariableBrowser.vue`). Lista i graf czytają i piszą **ten sam** stan
zaznaczenia. Sterowanie zoomem to realne `Button`y poza SVG.

**D10 — Czytnik i karta wpisu to JEDNA powierzchnia, nie dwa ekrany.**
Trzy kolumny: spis treści · artykuł · panele relacji. Poniżej `next-xl` panele
schodzą pod artykuł jako `Accordion` (czyli konwencja „jedna kolumna paneli `Surface`"
z `BotDetailView.vue` jest tu **fallbackiem**, nie wyjątkiem).
*Odrzucone:* osobna trasa „karta wpisu" — dwie strony renderujące ten sam artykuł to
gwarantowany rozjazd.

**D11 — Edytor wpisu to własna TRASA (pełna szerokość), nie `Drawer`.**
Konwencja Taskio to drawer-edytor (`?workflow=`, `?bot=`), ale tu limit to
**40 000 znaków**: drawer tworzy drugi kontener przewijania nad artykułem, a znana
regresja Escape jest w drawerach najbardziej bolesna. Trasa `…/edit/:slug?`.
Wyjście strzeżone `onBeforeRouteLeave` + `useConfirm`.

**D12 — Licznik znaków jest miękki, ale zapis nad limitem jest zablokowany
Z JAWNYM POWODEM.**
`MarkdownEditor` ma `counter` + `maxlength` (miękkie — licznik czerwienieje).
Przycisk **Zapisz** dostaje `aria-disabled` + `Tooltip` z powodem oraz stały
`Alert variant="warning"` z copy doktrynalnym. Nigdy „wyszarzony przycisk bez wyjaśnienia".

**D13 — Odrzucenie podobieństwa idzie przez Toast z „Cofnij", nie przez `ConfirmDialog`.**
Akcja jest odwracalna i o niskiej stawce; modal na taką akcję to nadużycie
(anatomia modala: modal = przerwanie, zarezerwowane dla decyzji nieodwracalnych).
`ConfirmDialog` zostaje dla: usunięcia wpisu/bazy, **usunięcia trwałego**, przywrócenia wersji.

**D14 — Kosz: wnioskowany wyjątek od Saved Views.**
Reguła „każdy ekran listy ma `FilterTabBar`" jest w projekcie bezwarunkowa, ale
istnieje precedens udokumentowanego wyjątku (sekcja Uruchomień per-workflow).
Kosz jest ekranem **przejściowym i destrukcyjnym** — zapisane widoki zachęcałyby do
mieszkania w koszu. **Propozycja:** `FilterBar` (szukaj + typ + data usunięcia) **bez**
`FilterTabBar`. **Do zatwierdzenia przez właściciela**; domyślka przy braku decyzji =
dodać `FilterTabBar` (reguła wygrywa).

**D15 — „Przenieś wiedzę z bota" nigdy nie jest wyszarzonym przyciskiem.**
Przycisk jest aktywny i otwiera modal migracji. Jeśli B6 jeszcze nie ma endpointu,
modal pokazuje `EmptyState` z uczciwym „wkrótce" — wyjaśnienie należy do celu,
nie do wyszarzenia.

> **B13 — D15 rozszerzone na kreator.** Ta sama zasada rządzi teraz przyciskiem **„Nowy wpis"**:
> pozostaje **aktywny nawet przy wyczerpanym budżecie AI**, a powód (budżet / kill-switch / brak
> wektorów), datę odnowienia i link do zużycia AI pokazuje **ekran docelowy** (§24.8).
> Dostępność jest sprawdzana **przed renderem** formularza, a nie po kliknięciu — użytkownik nie
> ma pisać 20 000 znaków, żeby dowiedzieć się, że nie ma za co ich przetworzyć.

**D16 — `EntryListInput` awansuje do design systemu.**
`pages/bots/EntryListInput.vue` jest już generyczny (`primaryKey`/`secondaryKey`).
Edytor opcji `enum` w schemacie potrzebuje dokładnie tego. **Zalecane:** przenieść do
`ui/forms/EntryListInput.vue`, przepiąć 3 użycia w botach, przenieść blok i18n
`bots.editor.entryForm.*` → `entryForm.*`. *Fallback, jeśli właściciel chce zerowego
ruchu w botach:* wiersze `key`/`label` inline w `KnowledgeSchemaBuilder.vue`, wzorowane
na `ConstantEditorDrawer.vue` (który już tak robi dla enumów) — wtedy **nie** powstaje
nowy komponent generyczny.
> Uwaga: `ConstantEditorDrawer.vue` renderuje wiersze opcji enum **inline**, więc
> fallback jest zgodny z istniejącą konwencją. To sprawia, że D16 jest optymalizacją,
> a nie warunkiem koniecznym.

---

## 2. Architektura informacji

### 2.1 Nawigacja globalna

`nav.knowledge` = **„Wiedza"** / „Knowledge", ikona `book-open` (nowa, sekcja 16),
w sekcji `nav.sectionWorkspace`, pomiędzy `nav.generator` a `nav.bots`.

### 2.2 Trasy

Plik: `resources/js/next/app/router/index.ts`. Konwencja `next.<moduł>[.<pod>][.<sekcja>]`,
`meta: { requiresAuth: true, titleKey: 'nav.knowledge' }` na **każdym** rekordzie.

```
path: 'knowledge'                       component: pages/knowledge/KnowledgeModuleLayout.vue
├─ ''            name: next.knowledge                    → KnowledgeBasesView.vue
├─ 'search'      name: next.knowledge.search             → KnowledgeSearchView.vue   (statyczne PRZED :baseId)
├─ 'trash'       name: next.knowledge.trash              → KnowledgeTrashView.vue    (statyczne PRZED :baseId)
└─ ':baseId'     component: pages/knowledge/KnowledgeBaseView.vue   (shell: fetch bazy + slim bar)
   ├─ ''                    name: next.knowledge.base
   │                        redirect: sectionRedirect(to, 'next.knowledge.base.',
   │                                   ['reader','table','graph','settings'], 'reader')
   ├─ 'reader/:slug?'       name: next.knowledge.base.reader    → KnowledgeReaderView.vue
   ├─ 'table'               name: next.knowledge.base.table     → KnowledgeEntriesTableView.vue
   ├─ 'graph/:slug?'        name: next.knowledge.base.graph     → KnowledgeGraphView.vue
   ├─ 'settings'            name: next.knowledge.base.settings  → KnowledgeBaseSettingsView.vue
   └─ 'edit/:slug?'         name: next.knowledge.base.edit      → KnowledgeEntryEditorView.vue
```

**Klucze zapytania (query):**

| Klucz | Właściciel | Znaczenie |
| --- | --- | --- |
| `?q`, `?status`, `?stale`, `?index`, `?incomplete`, `?meta.<key>` | strona (tabela / szukaj) | stan listy; hydratacja na `mount`, `router.replace` przy zmianie, **z zachowaniem kluczy nakładek** |
| `?history=<entryId>` | `KnowledgeBaseView` | otwiera `Drawer` historii wersji |
| `?migrate=bot` | `KnowledgeModuleLayout` | otwiera modal migracji z bota |
| `?depth=1\|2` | `KnowledgeGraphView` | głębokość ego-grafu |
| `?title=<tekst>` | `KnowledgeEntryEditorView` | prefill tytułu (z CTA czerwonego linku) |
| `#h-<anchor>` (hash) | czytnik | skok do fragmentu |

**Deep-link wpisu jest po SLUGU, nie po id** — wikilinki operują slugami, więc
`/next/knowledge/<baseId>/reader/<slug>` jest jedynym adresem, który da się
wygenerować z treści bez dodatkowego zapytania.

### 2.3 Shell modułu — `KnowledgeModuleLayout.vue`

Kopiuje szkielet z `pages/bots/BotsModuleLayout.vue`:

```vue
<div class="flex min-h-0 flex-1 gap-next-4">
  <ModuleAside
    module-icon="book-open"
    :module-title="t('knowledge.title')"
    :module-hint="t('knowledge.module.hint')"
    :module-items="moduleItems"
    :resource-items="resourceItems"
    :resource="resource"
    :resource-placeholder="{ icon: 'book-open', label: t('knowledge.module.pickBase'),
                             hint: t('knowledge.module.pickBaseHint'), to: { name: 'next.knowledge' } }"
    :resource-back="{ label: t('knowledge.module.backToList'), to: { name: 'next.knowledge' } }"
    :active-match="isItemActive"
  >
    <template #resource-meta>
      <StatusBadge v-if="base" :status="base.index_status" :status-map="indexStatusMap" size="sm"
                   :label="indexLabel(base)" />
    </template>
  </ModuleAside>

  <div class="flex min-h-0 min-w-0 flex-1 flex-col gap-next-4 overflow-y-auto">
    <ModuleTabs :items="tabItems" :active-match="isItemActive" :aria-label="t('knowledge.module.tabs')" />
    <RouterView />
  </div>

  <!-- nakładki należące do shella -->
  <KnowledgeVersionsDrawer v-model:open="historyOpen" … />
  <KnowledgeBotMigrationModal v-model:open="migrateOpen" … />
</div>
```

- `moduleItems`: `{ key:'bases', icon:'book-open' }`, `{ key:'search', icon:'search' }`,
  `{ key:'trash', icon:'trash' }`.
- `resourceItems`: `reader` (`book-open`), `table` (`table`), `graph` (`network`),
  `settings` (`settings`).
- Shell ustawia etykietę kontekstu strony przez `app/lib/pageContext.ts`
  (`setPageContextLabel(base.name)`), jak `BotsModuleLayout`.

### 2.4 Nagłówki stron (ADR-0011)

Na stronach sekcji `<h1>` nazywa **sekcję**, a nie rekord — tożsamość bazy niesie
`ModuleAside`. Wyjątek: **czytnik**, gdzie `<h1>` to tytuł wpisu (patrz 4.3), bo to
prawdziwy nagłówek treści; czytnik dlatego **nie używa `PageHeader`**, tylko slim baru
(precedens: slim action bar w `WorkflowDetailView.vue`). To pilnuje jednego `<h1>` na stronę.

---

## 3. Ekran 1 — Lista baz wiedzy

**Plik:** `resources/js/next/pages/knowledge/KnowledgeBasesView.vue`
**Trasa:** `next.knowledge` (`/next/knowledge`)

### 3.1 Układ

```vue
<div class="flex flex-col gap-next-6">
  <PageHeader :title="t('knowledge.title')" :description="t('knowledge.subtitle')" icon="book-open">
    <template #actions>
      <Button leading-icon="plus" @click="openCreate">{{ t('knowledge.bases.new') }}</Button>
    </template>
  </PageHeader>

  <FilterBar
    v-model:search="search"
    :search-placeholder="t('knowledge.bases.filters.search')"
    :active-filters="decoratedFilters"
    :clear-all-label="t('knowledge.filters.clearAll')"
    @remove-filter="removeFilter" @clear-all="clearAll" @restore-filter="onRestoreFilter"
  >
    <template #top>
      <FilterTabBar … />           <!-- OBOWIĄZKOWE; kontekst zapisanych widoków: 'knowledge_bases' -->
    </template>
    <div class="min-w-0 flex-1 basis-40">
      <Select v-model="indexStatus" :options="indexOptions" :placeholder="t('knowledge.filters.index')" />
    </div>
    <div class="min-w-0 flex-1 basis-40">
      <Select v-model="language" :options="languageOptions" :placeholder="t('knowledge.filters.language')" />
    </div>
    <div class="min-w-0 flex-1 basis-40">
      <Switch v-model="onlyWithGhosts" :label="t('knowledge.filters.withGhosts')" size="sm" label-position="leading" />
    </div>
  </FilterBar>

  <div class="grid grid-cols-1 gap-next-4 next-sm:grid-cols-2 next-xl:grid-cols-3">…</div>
</div>
```

Siatka kart = ta sama, którą mają Przepływy i Boty
(`grid-cols-1 next-sm:grid-cols-2 next-xl:grid-cols-3`).

### 3.2 Karta bazy — `KnowledgeBaseCard.vue` na `ui/patterns/EntityCard.vue`

| Region | Zawartość |
| --- | --- |
| `#leading` | `Icon name="book-open"` w bąbelku `bg-next-primary-subtle text-next-primary-subtle-foreground` |
| `title` | nazwa bazy |
| `subtitle` | **pierwsze zdanie karty tożsamości** (`subtitleLines=2`); brak → `t('knowledge.bases.noCharter')` w `text-next-muted-foreground` |
| `#status` | `StatusBadge` stanu indeksu (mapa 12.2), `size="sm"` |
| `meta` (`EntityMetaItem[]`) | `{icon:'file-text', label: t('knowledge.bases.meta.entries'), value: String(entries_count)}`, `{icon:'type', label: t('knowledge.bases.meta.language'), value: languageLabel}`, `{icon:'unlink', label: t('knowledge.bases.meta.ghosts'), value: String(ghost_links_count)}` (tylko gdy > 0), `{icon:'clock', label: t('knowledge.bases.meta.updated'), value: relativeDate}` |
| `#actions` | `DropdownMenu` (kebab `more-vertical`, `size="icon-sm"`): Otwórz · Ustawienia · Duplikuj schemat · Przenieś do kosza (`danger`) |
| `to` | `{ name:'next.knowledge.base.reader', params:{ baseId } }` |

**Stopka metadanych karty** (`meta`) jest tym samym pasem `ikona + etykieta + wartość`
co w każdej innej karcie w aplikacji — świadomie żadnych kart-jednorazówek.
Element `ghosts` renderuje się **tylko gdy > 0** i wtedy jest sygnałem do działania,
a nie stałym zerem szumiącym w każdej karcie.

**Kolejność trailing:** warunkowy `StatusBadge` przed stałym kebabem; kebab nigdy się
nie przesuwa (reguła projektowa).

### 3.3 Stany

| Stan | Realizacja |
| --- | --- |
| Ładowanie początkowe | 6 × `<EntityCard loading />` w tej samej siatce |
| Doładowanie (kursor) | 3 × `<EntityCard loading />` + `sentinelRef` (`useInfiniteScroll`) |
| Błąd doładowania | `Alert variant="danger" size="sm"` + `Button variant="outline"` „Spróbuj ponownie" |
| Błąd początkowy | `EmptyState variant="error"` + `#action` Ponów |
| Pusto po filtrach | `EmptyState variant="search"`, `#action` = „Wyczyść filtry" |
| **Pusto — pierwsze uruchomienie** | patrz 3.4 |

### 3.4 Pusty stan pierwszego uruchomienia — dwie ścieżki

> ⚠ **B13:** obie ścieżki zostają, ale **po utworzeniu bazy lądujemy w kreatorze**
> (`next.knowledge.base.compose`), nie w czytniku — pusta baza nie ma czego w czytniku pokazać.
> Patrz §25.1.

`EmptyState size="md" icon="book-open"`:

- `title`: **„Nie masz jeszcze żadnej bazy wiedzy"**
- `description`: „Baza wiedzy to mini-encyklopedia Twojego zespołu. Zacznij od jednej
  bazy na jeden obszar — na przykład „Marka" albo „Produkt"."
- `#action`: `<Button leading-icon="plus">Utwórz bazę</Button>`
- `#secondary`: `<Button variant="outline" leading-icon="sparkles">Przenieś wiedzę z bota</Button>`
  → `router.replace({ query: { …, migrate: 'bot' } })` (D15 — **przycisk zawsze aktywny**)

### 3.5 Tworzenie bazy — `KnowledgeBaseEditorDrawer.vue`

`Drawer side="right" size="lg"`. Zawartość = **dokładnie** te same pola co Ustawienia
(sekcja 7), w kolejności: Nazwa → Język → Karta tożsamości → Schemat metadanych.
Jeden komponent formularza `KnowledgeBaseForm.vue` używany w drawerze (tworzenie)
i na stronie Ustawień (edycja) — zero duplikacji.

### 3.6 Modal migracji z bota — `KnowledgeBotMigrationModal.vue`

`Modal size="md"`, `role="dialog"`. Anatomia: tytuł mówi CO, treść mówi CO SIĘ STANIE,
przycisk główny jest czasownikiem.

- Tytuł: „Przenieś wiedzę z bota"
- Treść: `Select` bota (opcje = boty z `knowledge.entries.length > 0`; puste → `#empty`
  „Żaden bot nie ma zapisanych wpisów wiedzy") → podgląd „Wpisy do przeniesienia: {count}"
  → `TextInput` nazwy nowej bazy (prefill: nazwa bota).
- `Alert variant="info" size="sm"`: „Wpisy zostaną **skopiowane** do nowej bazy.
  Konfiguracja bota pozostanie bez zmian."
- Akcje: `Button variant="ghost"` Anuluj · `Button :loading="submitting"` **Przenieś**
  (stan ładowania: spinner zastępuje ikonę wiodącą, etykieta zostaje, szerokość stała,
  `aria-busy`).
- Brak endpointu (B6 nie gotowe) → w miejscu `Select` `EmptyState size="sm"` z uczciwym
  „Migracja będzie dostępna wkrótce", akcja główna ukryta.

---

## 4. Ekran 2+4 — Czytnik wiki i karta wpisu (jedna powierzchnia)

**Pliki:**
`pages/knowledge/KnowledgeReaderView.vue` (orkiestracja),
`pages/knowledge/reader/KnowledgeTocPanel.vue`,
`pages/knowledge/reader/KnowledgeArticleBody.vue`,
`pages/knowledge/reader/KnowledgeEntryRail.vue`,
`pages/knowledge/reader/KnowledgeEntryPreview.vue`
**Trasa:** `next.knowledge.base.reader` (`…/reader/:slug?`)

### 4.1 Siatka

```
next-xl:   [ TOC 17rem ] [ artykuł 1fr, max-w-[72ch] ] [ panele 20rem ]
next-lg:   [ TOC 17rem ] [ artykuł 1fr ]                        ← panele jako Accordion pod artykułem
< next-lg: [ artykuł ]                                          ← TOC w Drawer, panele w Accordion
```

Klasy: `flex min-h-0 gap-next-4`, TOC `hidden w-[17rem] shrink-0 next-lg:flex`,
rail `hidden w-80 shrink-0 next-xl:flex`, artykuł `min-w-0 flex-1`.
Miara wiersza artykułu: `max-w-[72ch]` (czytelność — to jedyna arbitralna wartość
w tej specyfikacji i jest świadoma).

### 4.2 Slim bar (zamiast `PageHeader` — D10/2.4)

Pasek `sticky top-0 z-[var(--z-next-sticky)]` w `Surface bg="card" border radius="lg"`,
`px-next-4 py-next-2`, `flex items-center gap-next-2`:

`[Button icon-sm 'menu' — TOC, tylko < next-lg]`
`[SegmentedControl: Czytnik | Tabela]`
`—— spacer ——`
`[Button icon-sm 'chevron-left' Poprzedni] [Button icon-sm 'chevron-right' Następny]`
`[Button variant="outline" leading-icon="pencil" Edytuj]`
`[DropdownMenu kebab: Historia wersji · Duplikuj · Przenieś do kosza(danger)]`

`SegmentedControl` (`ui/forms/SegmentedControl.vue`) jest tu poprawny, bo to **wybór
trybu prezentacji**, a nie przełącznik paneli — zgodnie z rozróżnieniem
`SegmentedControl` vs `Tabs` w matrycy komponentów.

### 4.3 Spis treści — `KnowledgeTocPanel.vue`

`Surface bg="card" border elevation="sm" radius="lg"`, wewnątrz:

1. Nagłówek: `Text variant="caption"` „Spis treści" + licznik „Wpisy: {count}".
2. `TextInput type="search"` filtr lokalny (nie strzela do API; filtruje po tytule).
3. `Select` **grupowania** — opcje: „Bez grupowania" + każde pole schematu o
   `descriptor.base === 'enum' && !array`. Wybór trzymany w `localStorage`
   pod `knowledge:toc-group:<baseId>` (preferencja widoku, nie stan aplikacji).
4. Lista: `<nav aria-label="…"><ul>` z `RouterLink` na wpis.
   - Grupowanie ON → `<li>` z `role="presentation"` nagłówkiem grupy
     (`Text variant="caption"`, `sticky top-0 bg-next-card`) i zagnieżdżonym `<ul>`.
     Wartości bez przypisania → grupa „Bez wartości" **na końcu**.
   - Wiersz: tytuł (`truncate`) + prawy blok znaczników: `Badge` statusu jeśli
     ≠ `approved` (`size="sm" tone="subtle"`), ikona `alert-triangle` gdy `stale`
     (z `aria-label`), kropka `bg-next-warning` **nigdy sama** — zawsze z `aria-label`.
   - Aktywny wiersz: `bg-next-primary-subtle text-next-primary-subtle-foreground` +
     `aria-current="page"`.
5. Kolejność = `position` (serwer). **Bez drag&drop w MVP** — zmiana kolejności
   przez `↑`/`↓` w kebabie wiersza (klawiatura first, jak reorder w `FilterTabBar`).

### 4.4 Artykuł — `KnowledgeArticleBody.vue`

**Nagłówek artykułu** (nad treścią):
- `<h1>` tytuł wpisu (`Heading level="1"`).
- Pod nim rząd znaczników: `StatusBadge` statusu (mapa 12.1) · `Badge` „Nieaktualny"
  (gdy `stale`, `variant="warning" tone="subtle" icon="alert-triangle"`) ·
  `StatusBadge` indeksu (mapa 12.2, z `N/M` gdy `partial`) · `CreatorBadge size="xs"` +
  „zaktualizowano {date}".

**Treść:**
```vue
<MarkdownViewer
  :source="entry.content"
  :wikilinks="wikilinkOptions"      <!-- NOWY, addytywny prop — D5 -->
  :aria-label="t('knowledge.reader.articleLabel')"
/>
```

`wikilinkOptions` (kontrakt nowego propa):

```ts
interface WikilinkOptions {
  /** Zwraca cel dla sluga; null ⇒ czerwony link (duch). */
  resolve: (slug: string) => { href: string; title: string } | null;
}
```

Zachowanie transformacji (w `MarkdownViewer`, **po** `DOMPurify.sanitize`, w oderwanym
`<template>`, przed odczytem `innerHTML`):

1. Przejdź `TreeWalker`-em po węzłach tekstowych **z pominięciem** `code`, `pre`, `a`.
2. Dopasuj `/\[\[([^\[\]|]+?)(?:\|([^\[\]]+?))?\]\]/g`.
3. Dla każdego trafienia zbuduj element **programowo**:
   - istnieje → `<a href data-wikilink="<slug>">`, `textContent` = etykieta lub tytuł;
   - nie istnieje → `<a role="link" tabindex="0" data-wikilink="<slug>" data-ghost="true">`
     bez `href`, `textContent` = etykieta lub slug, `aria-label` = „{label} — wpis nie istnieje".
4. Nie ruszaj niczego innego. **Gdy `wikilinks` nie podano — nie uruchamiaj kroku 1.**

Style (w `next.css`, obok `.next-md-prose`):

```css
.next-root .next-md-prose a[data-wikilink] { text-decoration-style: solid; }
.next-root .next-md-prose a[data-ghost] {
  color: var(--color-next-danger);
  text-decoration-line: underline;
  text-decoration-style: dashed;     /* wzór ≠ kolor — druga cecha rozróżniająca */
  text-underline-offset: 3px;
}
.next-root .next-md-prose a[data-ghost]::after {
  content: '';                        /* ikona 'unlink' jako maska, 0.9em, currentColor */
  …
}
```

**Nawigacja bez przeładowania:** jeden delegowany `@click` na kontenerze:
`event.target.closest('[data-wikilink]')` → `preventDefault()` →
duch: `router.push({ name:'next.knowledge.base.edit', params:{ baseId }, query:{ title: slug } })`
(po potwierdzeniu w popoverze, patrz 4.5) / istniejący: `router.push(reader/:slug)`.
Obsłuż też `keydown.enter` dla ducha (nie ma `href`, więc nie ma domyślnej akcji).

**Kotwice nagłówków (deep-link do fragmentu):** po każdym renderze (`watch(source)` +
`nextTick`) wrapper nadaje `h1..h3` `id="h-<slugify(text)>"` oraz `tabindex="-1"`.
Wejście z hashem: `scrollIntoView({ block:'start' })` + `focus({ preventScroll:true })` +
klasa `is-jump-target` (tło `bg-next-primary-subtle`) usuwana po 2 s.
Pod `prefers-reduced-motion` brak animacji przewijania — skok natychmiastowy.

**Nawigacja poprzedni/następny:** wg `position` w bieżącym porządku spisu (z uwzględnieniem
grupowania). Skrót: `[` / `]`. Na końcach przyciski `aria-disabled` + `Tooltip` z powodem.

### 4.5 Popover podglądu — `KnowledgeEntryPreview.vue` (D6)

**Jeden** instancja panelu na stronę, teleportowana do `<body>`, `--z-next-tooltip`.

| Aspekt | Reguła |
| --- | --- |
| Otwarcie | `pointerover` **lub** `focusin` na `[data-wikilink]`; opóźnienie 250 ms |
| Zamknięcie | `pointerout` / `focusout` / `Escape` / scroll poza widok; opóźnienie 150 ms |
| Pozycja | `useAnchoredPosition` z syntetyczną kotwicą (`getBoundingClientRect` → rect `<a>`), `placement:'top'`, `flip` |
| Rola | `role="tooltip"`, stabilne `id`; na czas otwarcia kotwica dostaje `aria-describedby` |
| Interaktywność | **brak** — żadnych przycisków w popoverze (to podpowiedź, nie dialog) |
| Dane | `GET` podglądu wpisu po slugu; **cache per slug w store** (jedno zapytanie na slug na sesję) |
| Ładowanie | skeleton: linia tytułu 60 % + 2 linie akapitu |
| Duch | zamiast treści: `Text` „Ten wpis jeszcze nie istnieje" + `Text variant="caption"` „Kliknij, aby go utworzyć" |
| Błąd | jedna linia „Nie udało się wczytać podglądu" — **bez** przycisku ponów (to hint) |

**Ten sam komponent** (`KnowledgeEntryPreview.vue`) renderuje panel boczny grafu (8.6);
różnica jest wyłącznie w propie `:actions="true"`, który dokłada slot akcji poniżej
treści (w popoverze slot pozostaje pusty). Jedna prawda o „skróconej karcie wpisu".

### 4.6 Panele relacji — `KnowledgeEntryRail.vue`

Na `next-xl` kolumna `w-80` z panelami `Surface`; poniżej — `ui/disclosure/Accordion.vue`
(`type="multiple"`) pod artykułem. **Domyślnie zwinięte** poza pierwszym.
Zwartość każdego panelu identyczna w obu układach (jeden komponent na panel).

| Panel | Ikona | Domyślnie | Zawartość |
| --- | --- | --- | --- |
| **Metadane** | `braces` | rozwinięty | 4.7 |
| **Podobne** | `sparkles` | zwinięty, **leniwy** | 4.8 |
| **Linkuje do** | `link-2` | zwinięty | lista `RouterLink` do celów wikilinków |
| **Linkowane z** | `link` | zwinięty | backlinki; puste → „Żaden wpis jeszcze tu nie linkuje" |
| **Czerwone linki** | `unlink` | rozwinięty **gdy > 0**, inaczej ukryty | 4.9 |
| **Pochodzenie** | `user` | zwinięty | 4.10 |
| **Historia wersji** | `clock` | zwinięty | przycisk otwierający Drawer (4.11) |

Nagłówek panelu: `Icon` + tytuł + `Badge` z licznikiem (`variant="neutral" tone="subtle"`).
Licznik jest **stały** (nie chowa się przy 0) poza panelem czerwonych linków.

**Leniwość:** panel „Podobne" strzela do API dopiero przy pierwszym rozwinięciu
(`@expand` na `AccordionItem`, na `next-xl` — `IntersectionObserver` albo natychmiast,
bo panel jest widoczny; zalecane: pierwszy `expand`/wejście w widok).

### 4.7 Panel „Metadane"

**Podgląd** — `ui/data/DescriptionList.vue`, `layout="grid"`, `columns={1}` w railu /
`{2}` w Accordionie, `size="sm"`. Wartości **nigdy nie jako surowy tekst** — każda
przez slot `#value-<key>`:

| `descriptor.base` | Render wartości |
| --- | --- |
| `enum` | `Badge variant="neutral" tone="subtle"` z etykietą opcji (nie kluczem) |
| `boolean` | `Badge` `check-circle` „Tak" (success·subtle) / `x-circle` „Nie" (neutral·subtle) |
| `date` | sformatowana data + `<time datetime>` |
| `number` | `tabular-nums` |
| `text` | `truncate` + pełna wartość w `title` |
| brak wartości | `emptyValue` = `—` (`DescriptionList` robi to sam) |
| pole spoza schematu (osierocone) | wiersz z `Badge variant="warning" tone="subtle" icon="alert-triangle"` „Poza schematem" |

To jest świadome zastosowanie zasady „lepszy sposób prezentacji niż pole:wartość”:
zostaje semantyka `<dl>` (dobra dla czytników ekranu), ale wartości niosą znaczenie
wizualnie, a nie jako gołe stringi.

**Edycja** — `Button variant="ghost" size="icon-sm" icon="pencil"` w nagłówku panelu →
przełącza panel w tryb formularza: `KnowledgeMetadataForm.vue`.

```vue
<!-- KnowledgeMetadataForm.vue — wzorzec: SlotValuesForm.vue, gałąź `object` -->
<FormField v-for="field in schema" :key="field.key" :label="field.label || field.key">
  <div class="flex flex-col gap-next-2">
    <Switch v-if="field.descriptor.nullable" :model-value="isNull(field.key)" size="sm"
            label-position="leading" :label="t('knowledge.metadata.noValue')"
            @update:model-value="(v) => setNull(field.key, v)" />
    <TypedLiteralInput
      v-if="!isNull(field.key)"
      :model-value="model[field.key]"
      :base="field.descriptor.base"
      :options="field.descriptor.options ?? []"
      :aria-label="field.label || field.key"
      :invalid="!!errorFor(field.key)"
      @update:model-value="(v) => setValue(field.key, v)"
    />
  </div>
</FormField>
```

- Pola `array` → repeater (wiersz + `Button icon-xs 'trash'` + `Button variant="outline"`
  „Dodaj element”) — dokładnie jak w `SlotValuesForm`.
- Bazy `time` / `file` / `object` **nie są dozwolone w schemacie metadanych** —
  builder ich nie oferuje (7.4). Gdyby przyszły z serwera: wiersz read-only +
  `Alert variant="warning" size="sm"` „Ten typ pola nie jest edytowalny w tej wersji".
- Błędy: klienta trzymamy jako **klucze i18n** (konwencja `ConstantEditorDrawer`),
  a błąd serwera **ma pierwszeństwo**:
  `const err = computed(() => serverErrors?.[`metadata.${key}`] ?? (clientErr ? t(clientErr) : null))`.
- Stopka: `Button variant="ghost"` Anuluj · `Button :loading="saving"` Zapisz.

### 4.8 Panel „Podobne"

Wiersz wyniku (`KnowledgeSimilarRow.vue`):

```
[tytuł — RouterLink, truncate]                                    [ 87% ]  [✕]
[Progress size="sm" value=87  aria-label="Dopasowanie: 87%"]
[Text variant="caption"]  Rozdział › Podrozdział                         ← ścieżka nagłówków chunka
[Text variant="ui" clamp=2]  …fragment dopasowanego chunka…
[Button variant="link" size="xs"]  Dlaczego podobne?                     ← rozwija dowód
```

- **Procent zawsze jako tekst**, pasek jest dodatkiem (nigdy sam pasek — kontrast/dostępność).
- „Dlaczego podobne?" rozwija `Surface bg="muted"` z dowodem krawędzi
  (pola z backendu; UI renderuje `label: value`, bez wymyślania).
- `✕` = `Button variant="ghost" size="icon-xs" icon="x"` + `aria-label`
  „Odrzuć podobieństwo: {title}”. Po kliknięciu: wiersz znika,
  `useToast()` z akcją **Cofnij** (D13). Bez modala.
- Sortowanie malejąco po score; cap 10 + `Button variant="link"` „Pokaż więcej".
- Pusto: `EmptyState size="sm" icon="sparkles"` „Nie znaleźliśmy podobnych wpisów".
- Indeks nie gotowy: **nie** pusty stan, tylko `Alert variant="info" size="sm"`
  z aktualnym stanem indeksu i copy z 11.4.

### 4.9 Panel „Czerwone linki"

> ⚠ **B13:** CTA „Utwórz wpis" prowadzi teraz do **kreatora z ziarnem**
> (`compose?seed=<slug>`), a nie do edytora z prefillem tytułu. Etykieta bez zmian. Patrz §25.4.

Widoczny tylko gdy `ghost_links.length > 0`. Nagłówek: `Badge variant="danger" tone="subtle"`
z liczbą. Wiersz: `[slug]` w `font-next-mono text-next-xs` + `Button variant="outline" size="xs"
leading-icon="plus"` **„Utwórz wpis"** → `edit?title=<slug>`.
Stopka panelu: `Button variant="link" size="xs"` „Pokaż wszystkie czerwone linki w bazie"
→ tabela z filtrem.

### 4.10 Panel „Pochodzenie"

`DescriptionList layout="horizontal" size="sm"`:
Utworzył (`CreatorBadge size="xs"`) · Utworzono (`<time>`) · Ostatnia zmiana
(`CreatorBadge` + `<time>`) · Źródło (`Badge`: Ręcznie / Import z bota / Wygenerowane AI) ·
Slug (`font-next-mono` + `Button icon-xs 'copy'` z toastem „Skopiowano").

`CreatorBadge` obsługuje polimorfizm `user | workflow_run | bot` z automatu —
**nie budować własnego przełącznika**.

### 4.11 Historia wersji — `KnowledgeVersionsDrawer.vue`

Otwierany przez `?history=<entryId>` (właściciel: shell modułu).
`Drawer side="right" size="md"`, `#title` „Historia wersji", `#description` = tytuł wpisu.

Ciało: `ui/patterns/Timeline.vue` + `TimelineItem`:
- `#node`: `CreatorBadge glyphOnly size="sm"`
- `title`: „Wersja {n}" + `Badge` „aktualna" przy najnowszej
- `time` + `datetime`
- opis: podsumowanie zmiany z serwera (`clampLines=2`)
- `#actions`: `Button variant="outline" size="xs" leading-icon="rotate-ccw"` **Przywróć**
  (ukryty na wersji aktualnej)

Przywracanie → `useConfirm()`:
- tytuł: „Przywrócić wersję {n}?"
- treść: **„Przywrócenie utworzy nową wersję. Nic nie zostanie utracone —
  obecną treść nadal znajdziesz w historii."**
- `confirmLabel`: „Przywróć", `variant: 'default'` (to **nie** jest akcja destrukcyjna)

Stany: `loading` → skeletony `Timeline` (kilka pozycji); pusto →
„Ta wersja jest pierwsza — nie ma jeszcze historii"; błąd → `EmptyState variant="error"`.

~~**Diffu nie ma w MVP** (sekcja 20).~~
> ⚠ **B13 — NIEAKTUALNE.** `ui/data/TextDiffView.vue` jest komponentem design systemu (awans w B14),
> a treść wpisu nie może zawierać dyrektyw (blokuje je strażnik po stronie backendu), więc powód
> odłożenia diffa odpadł. Drawer dostaje akcję „Porównaj z bieżącą" — patrz **§25.3**.

---

## 5. Ekran 3 — Tryb tabeli wpisów

**Plik:** `pages/knowledge/KnowledgeEntriesTableView.vue`
**Trasa:** `next.knowledge.base.table`

> `ui/data/Table.vue` ma dziś dokładnie **jedno** produkcyjne użycie
> (`pages/settings/MembersView.vue`). To jest zamierzone drugie: dane wpisów są
> jednorodne i kolumnowe, a `Table` ma już skeletony wierszy, `aria-sort`,
> `responsive="stack"` i sloty komórek. Nie budować własnej tabeli.

### 5.1 Układ

`PageHeader` (`title` = `t('knowledge.entries.title')` — czyli **sekcja**, nie nazwa bazy;
`icon="table"`; `#actions`: `Button leading-icon="plus"` „Nowy wpis”, `SegmentedControl`
Czytnik/Tabela) → `FilterBar` + `FilterTabBar` (kontekst `knowledge_entries`) → `Table`.

### 5.2 Filtry

| Kontrolka | Typ | Uwagi |
| --- | --- | --- |
| szukaj | `v-model:search` (debounce 300) | tytuł + treść |
| Status | `Select` multiple | draft / proposed / approved / archived — chipy **wielowartościowe**, nie „N wybranych" |
| Stan indeksu | `Select` multiple | 6 wartości z 12.2 |
| Nieaktualne | `Switch` | trójstan niepotrzebny — off = bez filtra |
| Niekompletne metadane | `Switch` | wchodzi też z bannera Ustawień (7.5) |
| Autor | `UserSelect` | istniejący wrapper |
| Aktualizacja | `DateRangeFilter` | jedna kontrolka daty na ekran (reguła FilterBar) |
| Metadane `enum` | `Select` per pole schematu | max 3 pierwsze pola `enum`; reszta pod „Więcej filtrów” |

### 5.3 Kolumny

| `key` | `label` | Render |
| --- | --- | --- |
| `title` | Tytuł | `#cell-title`: `RouterLink` (waga `medium`) + pod spodem `Text variant="caption"` ze slugiem `font-next-mono`; `truncate` |
| `status` | Status | `StatusBadge size="sm"` (mapa 12.1), `width: '10rem'`, `align:'start'` |
| `stale` | Aktualność | `Badge warning·subtle icon="alert-triangle"` „Nieaktualny" **albo** `Text variant="caption"` „—” (nigdy pusta komórka) |
| `index` | Indeks | `StatusBadge size="sm"` (mapa 12.2); `partial` → etykieta „Częściowo {done}/{total}" |
| `creator` | Autor | `CreatorBadge size="xs"` |
| `updated_at` | Aktualizacja | `<time>` względna + pełna w `title` |
| `actions` | *(pusta etykieta)* | `#row-actions`, `width: '7rem'`, `align:'end'` |

`sortable`: `title`, `status`, `updated_at`. Sortowanie **zewnętrzne**
(`v-model:sort` → refetch); `aria-sort` obsługuje `Table`.

### 5.4 Akcje w wierszu (wzorzec zwarty)

> ⚠ **B13:** z kebaba **wypada „Duplikuj"** (była jedyną ścieżką tworzenia wpisu omijającą
> kreator); w jego miejsce wchodzi **„Zaproponuj zmianę przez AI"** → `compose?amend=<entryId>`.
> Przycisk „Nowy wpis" w `PageHeader` (§5.1) celuje w kreator. Patrz §25.1 i §25.4.

```
[ Button icon-xs 'eye'    Otwórz  ]   ← zawsze widoczny, główna akcja
[ Button icon-xs 'pencil' Edytuj  ]   ← ujawniany
[ DropdownMenu kebab 'more-vertical' ] ← Historia · Duplikuj · Zmień status ▸ · Przenieś do kosza (danger)
```

Reguły:
- Akcje ujawniane są **zawsze w DOM**; ukrywane wyłącznie `opacity-0`, z
  `group-hover:opacity-100 focus-within:opacity-100 next-md:opacity-0` — nigdy `v-if`
  i nigdy `display:none` (inaczej znikają dla klawiatury i czytników).
- Poniżej `next-md` (tryb `stack`) akcje są **zawsze widoczne** (brak hovera na dotyku).
- Każdy przycisk ikonowy ma `aria-label` z nazwą wpisu:
  `t('knowledge.entries.actions.openAria', '', { title })`.
- Maksymalnie **2** przyciski inline; wszystko powyżej idzie do kebaba.

### 5.5 Responsywność i stany

- `responsive="stack"` — poniżej `next-md` każdy wiersz staje się kartą etykieta/wartość
  (to prawdziwa transformacja, nie ścieśniona tabela).
- `stickyHeader`, `hoverable`, `zebra={false}`, `dense={false}`.
- Ładowanie: `:loading` + `loadingRows={8}` (skeletony wierszy z automatu).
- Doładowanie: `useInfiniteScroll` + sentinel pod tabelą + 3 skeletonowe wiersze
  w `#footer`.
- Pusto: `#empty` → `EmptyState size="sm"`; wariant `search` gdy są filtry,
  `default` („Ta baza nie ma jeszcze wpisów" + „Nowy wpis") gdy nie ma.
- Błąd: `#error` → `EmptyState variant="error"` + Ponów.

---

## 6. Ekran 5 — Edytor wpisu

> ⚠ **B13 — TRYB TWORZENIA USUNIĘTY.** Ta sekcja opisuje edytor sprzed pivotu na AI-only.
> Edytor obsługuje **wyłącznie edycję istniejących** wpisów; trasa to `…/edit/:slug`
> (slug **obowiązkowy**), a wejście bez sluga przekierowuje do kreatora.
> Dokładna lista usunięć: **§25.2**. Tworzenie wpisów: **§24**.

**Plik:** `pages/knowledge/KnowledgeEntryEditorView.vue`
**Trasa:** `next.knowledge.base.edit` (`…/edit/:slug?`), nowy wpis = brak `:slug`

### 6.1 Układ

```
[ sticky pasek akcji ]
next-lg: [ kanwa 1fr ] [ rail 20rem ]
< next-lg: [ Accordion „Ustawienia wpisu" ] [ kanwa ]
```

**Pasek akcji** (`sticky top-0`, `Surface bg="card" border`):
`[Button ghost icon-sm 'chevron-left' Wróć]` `[Text: Nowy wpis | Edycja: {title}]`
— spacer — `[Text variant="caption" — status zapisu]` `[Button ghost Anuluj]`
`[Button :loading="saving" :aria-disabled="!canSave" Zapisz]`

### 6.2 Kanwa

```vue
<FormField :label="t('knowledge.editor.titleLabel')" :error="titleError" required>
  <TextInput v-model="title" size="lg" :placeholder="t('knowledge.editor.titlePlaceholder')" />
</FormField>

<MarkdownEditor
  v-model="content"
  :counter="true"
  :maxlength="40000"
  :wikilinks="wikilinkSuggest"     <!-- NOWA opcja funkcji PART 2, patrz 6.3 -->
  min-height="60vh"
  :error="contentError"
  :placeholder="t('knowledge.editor.contentPlaceholder')"
/>
```

`MarkdownEditor` bez `maxHeight` → rośnie z treścią, przewija strona (a nie kontener
wewnątrz kontenera).

**Doktryna limitu (D12).** Gdy `charCount > 40000`:
- licznik czerwienieje (zachowanie wbudowane),
- pod edytorem `Alert variant="warning"`:
  **„Ten wpis przekracza 40 000 znaków. Wpis to hasło encyklopedyczne — jeśli materiał
  jest dłuższy, podziel go na kilka haseł i połącz je wikilinkami `[[…]]`."**
  `#actions`: `Button variant="outline" size="sm"` „Jak dzielić wpisy?" (link do docs).
- `Zapisz` dostaje `aria-disabled="true"` + `Tooltip` „Zmniejsz treść do 40 000 znaków,
  aby zapisać" — **nie** `disabled` bez wyjaśnienia.

### 6.3 Autouzupełnianie wikilinków po `[[`

**Plik:** `ui/editor/extensions/wikilink.ts` + `ui/editor/extensions/WikilinkSuggest.vue`
(nowe, ale **w całości** na istniejącym mechanizmie sugestii).

```ts
export interface WikilinkOptions {
  /** Async źródło podpowiedzi (debounce po stronie hosta). */
  fetch: (query: string) => Promise<WikilinkItem[]>;   // { slug, title, status }
  /** Czy oferować wiersz „Utwórz wpis …". Domyślnie true. */
  allowCreate?: boolean;
}
```

**To NIE jest węzeł (D4)** — rozszerzenie rejestruje wyłącznie plugin ProseMirror
z triggerem, bez `Node.create`, bez `registerMarkdownNode`, bez
`registerInlineDirective`.

Kontrakt triggera (odpowiednik `mention.ts`, ale inna gramatyka):

```ts
const before = newState.doc.textBetween(Math.max(0, pos - 120), pos, '\n', '￼');
const match  = before.match(/\[\[([^\[\]|\n]*)$/);   // zapytanie MOŻE zawierać spacje
// range = { from: pos - query.length - 2, to: pos }
```

Wstawienie:
`insertContentAt(range, { type:'text', text: '[[' + item.slug + ']]' })`
(gdy użytkownik wpisał ręcznie etykietę po `|`, plugin się nie aktywuje — regex
wyklucza `|`).

**Klawiatura:** `↑`/`↓`/`Enter`/`Escape` forwardowane z `handleKeyDown`
(wirtualny fokus — DOM focus zostaje w ProseMirror, karetka nie ucieka).

> **KRYTYCZNE — znana regresja.** Plugin **musi** wołać
> `createSuggestionOverlay(close)` z `ui/editor/extensions/suggestionStore.ts`
> i `overlay.acquire()` przy otwarciu / `overlay.release()` w `close()` i `destroy()`.
> Bez tego Escape zamknie stronę/drawer i **skasuje pracę użytkownika** zamiast
> zamknąć listę. Dokładnie ten błąd trafił już `@`-wzmianki i `Select` (patrz komentarz
> `createSuggestionOverlay` w kodzie).

**Popup `WikilinkSuggest.vue`** (klon `MentionSuggest.vue`):
- wiersz: `Icon 'file-text'` + tytuł + `Badge` statusu (`size="sm"`) + slug
  `font-next-mono text-next-2xs text-next-muted-foreground`
- `role="listbox"` / `role="option"` / `aria-activedescendant`
- ładowanie → **skeletony wierszy** (ikona-kółko + linia), nigdy spinner
- pusto + `allowCreate` → ostatni wiersz `Icon 'plus'` **„Utwórz wpis «{query}»"**
  (wstawia `[[<slugify(query)>]]`; wpis powstanie dopiero z CTA czerwonego linku —
  edytor **nigdy** nie tworzy encji w tle)
- pusto bez `allowCreate` → „Brak dopasowań"

### 6.4 Rail edytora

`Surface` panele (na `next-lg`) / `Accordion` (poniżej):

1. **Status** — `Select` (draft / proposed / approved / archived) + `Text variant="caption"`
   z konsekwencją wybranego statusu (patrz copy 18).
2. **Metadane** — `KnowledgeMetadataForm.vue` (ten sam komponent co 4.7).
3. **Ustawienia wpisu** — slug (`TextInput`, auto ze sluga tytułu, nadpisywalny;
   wzorzec `keyModel` z `ConstantEditorDrawer.vue`: `get` liczy ze źródła dopóki
   użytkownik nie „dotknął" pola), `Switch` „Oznacz jako nieaktualny", `position`
   (`NumberInput`).

Slug: walidacja klienta `^[a-z0-9]+(?:-[a-z0-9]+)*$`, komunikat jako **klucz i18n**.

### 6.5 Zapis, konflikt 409, wyjście

**Zapis:** `Button :loading` — spinner podmienia ikonę wiodącą, etykieta zostaje,
szerokość stabilna, `aria-busy="true"`. Sukces → toast + `router.replace` na czytnik
tego wpisu.

**409 (ktoś zapisał równolegle)** — `KnowledgeStaleWriteModal.vue`, `Modal size="md"`,
**nie** `ConfirmDialog` (trzy wyjścia, nie dwa):

- Tytuł: **„Ktoś zapisał ten wpis w międzyczasie"**
- Treść: „{author} zapisał(a) nowszą wersję {time}. Twoje zmiany nie zostały jeszcze
  zapisane." + `Alert variant="info" size="sm"` „Twoja treść jest bezpieczna —
  dopóki nie wybierzesz opcji, nic nie znika."
- Akcje (kolejność = rosnące ryzyko, główna najbezpieczniejsza):
  1. `Button` **„Zostaw moje zmiany"** (zamyka modal, wraca do edytora — użytkownik
     kopiuje treść ręcznie) — **primary**
  2. `Button variant="outline"` **„Otwórz najnowszą wersję w nowej karcie"**
  3. `Button variant="danger"` **„Nadpisz moją wersją"** → `useConfirm()` drugiego stopnia
     („Nadpiszesz zmiany {author}. Ich wersja zostanie w historii.")
- Zamknięcie scrimem/Escape = opcja 1 (najbezpieczniejsza) — nigdy utrata pracy.

**Wyjście z niezapisanymi zmianami:** `onBeforeRouteLeave` + `useConfirm({ variant:'danger',
title:'Porzucić niezapisane zmiany?', confirmLabel:'Porzuć' })`. Dotyczy też przycisku
„Wróć" i wikilinków (klik w link w PODGLĄDZIE nie występuje — edytor nie renderuje
nawigowalnych wikilinków).

---

## 7. Ekran 6 — Ustawienia bazy

**Pliki:** `pages/knowledge/KnowledgeBaseSettingsView.vue`,
`pages/knowledge/KnowledgeBaseForm.vue`, `pages/knowledge/KnowledgeSchemaBuilder.vue`
**Trasa:** `next.knowledge.base.settings`

`PageHeader :title="t('knowledge.settings.title')" icon="settings"` (h1 = sekcja).
Ciało: pojedyncza kolumna paneli `Surface bg="card" border elevation="sm" radius="lg" p-next-6`
(konwencja `BotDetailView.vue`).

### 7.1 Panel „Tożsamość"

- `TextInput` Nazwa (wymagane).
- `Select` **Język bazy** — opcje z serwera; fallback `['pl','en']`.
  `#description`: „Język bazy steruje indeksowaniem i podpowiedziami AI."

### 7.2 Panel „Karta tożsamości" (charter)

**Jedno** pole `Textarea` `autoGrow`, `rows={10}`, bez podziału na 5 pól
(5 pól = ankieta; jedno pole = tekst, który AI faktycznie zje).

- `#description` (`FormField`): „Opisz bazę tak, jak wytłumaczył(a)byś ją nowej osobie."
- `placeholder` (widoczny, gdy puste — 5 pytań doktryny):

```
Czym jest ta baza?
Dla kogo jest przeznaczona?
Jakim tonem mówi?
Co obejmuje?
Czego świadomie NIE zawiera?
```

- Pod polem `Alert variant="info" size="sm"` (zwijalny, `dismissible`):
  „Karta tożsamości trafia do modeli AI korzystających z tej bazy. Im konkretniej
  opiszesz zakres i wykluczenia, tym mniej zmyśleń."
- Licznik znaków (`counter`), limit miękki 2 000.

### 7.3 Panel „Schemat metadanych" — `KnowledgeSchemaBuilder.vue`

Model: `VariableDescriptorField[]` = `{ key, label, descriptor }[]`.
Wzorzec budowania = **sekcja „Typ" w `pages/variables/ConstantEditorDrawer.vue`**
(rozdz. „Section 2 — Type builder”), przeniesiony na listę pól.

Wiersz pola (`Surface bg="muted/20" rounded-next-md p-next-3`):

```
┌────────────────────────────────────────────────────────────────────────┐
│ [⋮⋮ nieaktywne]  Pole 1                              [↑] [↓] [🗑 icon-xs]│
│ ┌ Klucz i etykieta ────────────────────────────────────────────────┐   │  ← JEDNO FormField,
│ │ [ product_line        │ Linia produktowa                       ] │   │    FieldShell `segmented`
│ └──────────────────────────────────────────────────────────────────┘   │
│ Typ:  [SegmentedControl: Tekst | Liczba | Warunek | Data | Wybór ]      │
│ [Switch] Opcjonalne        [Switch] Lista wartości                      │
│ ── gdy Typ = Wybór ────────────────────────────────────────────────     │
│   [klucz] [etykieta] [🗑]   … + [Dodaj opcję]                            │
└────────────────────────────────────────────────────────────────────────┘
```

- **Pola złączone (jointed fields)** — `key` i `label` w **jednym** `FormField`
  z `FieldShell :segmented`, jedną etykietą i jedną linią stanu. To jest bezpośrednie
  zastosowanie zasady upraszczania dwukolumnowych formularzy i mechanizm istnieje
  (`FieldShell` `segmented` z dzielnikami śledzącymi stan).
- `key` auto-wyprowadzany z `label` (slug) do momentu ręcznej edycji (wzorzec `keyModel`).
  Walidacja klienta **1:1 z backendem**: `/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/` + unikalność.
- **Typ:** `SegmentedControl` tylko nad **5** bazami: `text`, `number`, `boolean`,
  `date`, `enum`. Ikony z `BASE_ICON` w `pages/variables/consts.ts`
  (`type`, `hash`, `check-circle`, `calendar`, `list`). **`object`, `file`, `time`
  nie są oferowane** — metadane wpisu to płaskie, filtrowalne pola; kontener zabiłby
  filtry i tabelę.
- `nullable` („Opcjonalne") i `array` („Lista wartości") jako **ortogonalne** `Switch`e.
- Opcje enum: `ui/forms/EntryListInput.vue` (po D16) z `primaryKey="key"`,
  `secondaryKey="label"`; fallback = wiersze inline jak w `ConstantEditorDrawer`.
- Kolejność pól: `↑`/`↓` (`Button size="icon-xs"`), klawiatura-first, bez drag&drop.
- Pusto: `EmptyState size="sm" icon="braces"` „Ta baza nie ma jeszcze schematu metadanych"
  + „Dodaj pole".
- Stopka: `Button variant="outline" leading-icon="plus"` „Dodaj pole”.

### 7.4 Zmiana schematu przy istniejących wpisach — bez blokującej migracji

Nad builderem, gdy `entries_count > 0`:

`Alert variant="info" size="sm"`:
**„Zmiana schematu nie zmienia istniejących wpisów. Nowe pole będzie puste we wpisach,
które już istnieją; usunięte pole przestaje być pokazywane, ale jego wartości zostają."**

Po zapisie schematu, gdy serwer zwróci liczbę braków — `Banner variant="warning"`
na górze strony Ustawień **oraz** w czytniku bazy:

- treść: „Wpisy z brakami metadanych: {count}"
- `#actions`: `Button variant="outline" size="sm"` **„Pokaż te wpisy"** →
  `next.knowledge.base.table` z `?incomplete=1`
- `dismissible` — schowanie zapamiętane w `localStorage` per `baseId` **i** per
  `schema_version` (nowa zmiana schematu pokazuje banner ponownie).

### 7.5 Panel „Strefa niebezpieczna"

`Surface` z `border-next-danger/40`. Jedna akcja:
`Button variant="danger" leading-icon="trash"` „Przenieś bazę do kosza” → `useConfirm`
(`variant:'danger'`, treść: „Baza i **{count}** wpisów trafią do kosza.
Możesz je przywrócić.").

---

## 8. Ekran 7 — Graf

**Pliki:**
`pages/knowledge/KnowledgeGraphView.vue` (orkiestracja + URL),
`pages/knowledge/graph/KnowledgeGraphCanvas.vue` (SVG),
`pages/knowledge/graph/KnowledgeGraphNeighbourList.vue` (dostępność — D9),
`pages/knowledge/graph/KnowledgeGraphLegend.vue`,
`pages/knowledge/graph/knowledgeGraphLayout.ts` (**czysta funkcja**, testy Vitest)
**Trasa:** `next.knowledge.base.graph` (`…/graph/:slug?`)

> W repo **nie ma dziś żadnej wizualizacji grafowej ani biblioteki layoutu**
> (`viewBox` występuje tylko w `Icon`, `Spinner`, `CircularProgress`). To jest
> nowa zdolność — dlatego layout jest wydzielony jako czysta funkcja i przypięty testami.

### 8.1 Układ strony

```
[ PageHeader: title = t('knowledge.graph.title'), icon="network",
              #actions: SegmentedControl głębokości + Button „Dopasuj" ]
[ pasek filtrów typów krawędzi + legenda ]
next-lg:  [ płótno 1fr, aspect ~4/3, min-h-[28rem] ] [ panel boczny 20rem ]
< next-lg: [ SegmentedControl: Lista | Graf ]  → domyślnie **Lista**
```

### 8.2 Dwa tryby

| Tryb | Kiedy | Zawartość |
| --- | --- | --- |
| **Przeglądowy** | brak `:slug` | top-N wpisów wg stopnia, **cap 60 węzłów**; nad płótnem `Text variant="caption"`: „Pokazano 60 z {count} wpisów o największej liczbie połączeń” + `Button variant="link"` „+{n} dalszych” → przechodzi do tabeli |
| **Ego** | jest `:slug` | wybrany wpis w centrum + sąsiedztwo 1 lub 2 kroki (`?depth`) |

Przejścia: **klik** węzła = zaznaczenie (panel boczny, bez zmiany trasy).
**Dwuklik / Enter na liście** = re-centrowanie → `router.push(graph/:slug)` — deep-link.

### 8.3 Layout — kontrakt `knowledgeGraphLayout.ts`

**Wymagania:** czysta funkcja, **deterministyczna** (żadnego `Math.random`,
żadnej symulacji, żadnego czasu), stabilna względem kolejności wejścia
(sortowanie wewnątrz).

```ts
export interface GraphNodeIn  { id: string; slug: string; title: string; degree: number; updatedAt: string; }
export interface GraphEdgeIn  { source: string; target: string; kind: 'wikilink'|'similarity'|'manual'|'ghost'; score?: number; }
export interface GraphPlacement { id: string; x: number; y: number; r: number; ring: number; }
export interface GraphLayout { nodes: GraphPlacement[]; overflow: number; width: number; height: number; }

export function layoutOverview(nodes: GraphNodeIn[], edges: GraphEdgeIn[], opts: { cap?: number }): GraphLayout;
export function layoutEgo(centerId: string, nodes: GraphNodeIn[], edges: GraphEdgeIn[], depth: 1|2): GraphLayout;
```

**Algorytm przeglądowy — pierścienie koncentryczne + barycentrum:**

1. Sortuj węzły: `degree` malejąco → `updatedAt` malejąco → `id` rosnąco (**pełny
   porządek, zero remisów**).
2. Weź pierwsze `cap` (domyślnie 60); `overflow = total - cap`.
3. Pierścienie: `ring0` = pierwsze `min(9, ceil(cap*0.15))`, `ring1` = kolejne ~35 %,
   `ring2` = reszta.
4. Promienie: `R = min(w,h)/2 - margin`; `r_ring = [0.16, 0.46, 0.84] * R`
   (dla `ring0` o 1 węźle → `x=y=0`).
5. Kąt startowy w pierścieniu: `i / n * 2π`.
6. **Dwa przebiegi barycentrum** (zewnętrzny→wewnętrzny, potem odwrotnie): dla każdego
   węzła policz kołową średnią kątów już umieszczonych sąsiadów, posortuj pierścień
   po tej średniej i **rozłóż równomiernie** (utrzymanie odstępów > przypięcie do kąta —
   inaczej węzły się nakładają).
7. Promień węzła: `r = clamp(6 + 3*sqrt(degree), 6, 18)` px.

**Algorytm ego:** centrum `(0,0)`; sąsiedzi 1. stopnia równomiernie na `0.45R`,
posortowani wg: `wikilink` → `manual` → `similarity` (malejąco po `score`) → `ghost`,
remis po `title`; sąsiedzi 2. stopnia na `0.85R` w **sektorze kątowym swojego rodzica**
(szerokość sektora = udział rodzica w liczbie dzieci).

### 8.4 Rysowanie SVG

`<svg :viewBox="vb" preserveAspectRatio="xMidYMid meet" aria-hidden="true" focusable="false">`
— cały SVG jest ukryty przed AT (D9).

**Krawędzie — rozróżnialne BEZ koloru:**

| Typ | Linia | Znacznik |
| --- | --- | --- |
| `wikilink` | ciągła, `stroke-width: 1.5` | grot strzałki na końcu (kierunek) |
| `similarity` | **kropkowana** `stroke-dasharray="2 3"`, `stroke-width: 1 + score` (1–2.5) | brak |
| `manual` | ciągła, `stroke-width: 2` | mały wypełniony **kwadrat** w połowie długości |
| `ghost` | **przerywana** `stroke-dasharray="5 4"` | cel = okrąg `fill="none"` + `stroke-dasharray="3 3"` |

Kolor krawędzi: `var(--color-next-border)` w spoczynku,
`var(--color-next-primary)` dla krawędzi zaznaczonego/najechanego węzła.
**Kolor niesie stan, wzór niesie typ** — żadnego przeciążenia.

**Węzły:** `<circle>` `fill: var(--color-next-primary-subtle)`,
`stroke: var(--color-next-border)`; zaznaczony: `stroke: var(--color-next-primary)`,
`stroke-width: 2` **oraz** pierścień zewnętrzny (kształt + kolor).
Duch: `fill="none"`, obrys przerywany, `stroke: var(--color-next-danger)`.

**Etykiety:** `<text>` pod węzłem, `font-size: var(--text-next-2xs)`,
`fill: var(--color-next-fg)`; pokazywane gdy `degree >= próg` **albo** zoom > 1.4
**albo** hover/zaznaczenie; skracane do 24 znaków + `…`.
Pod etykietą półprzezroczysta `<rect>` w `--color-next-bg` (czytelność nad krawędziami).

### 8.5 Pan / zoom / sterowanie

- Stan: `{ vx, vy, vw, vh }` → `viewBox`. Zoom = skalowanie `vw`/`vh` wokół pozycji
  kursora; pan = przeciąganie (`pointerdown/move/up` z `setPointerCapture`).
- Zakres zoomu `0.5×`–`4×`; „Dopasuj do ekranu" liczy bbox rozmieszczenia + 8 % marginesu.
- Sterowanie to realne `Button`y **poza** SVG (`icon-sm`: `plus`, `minus`, tekstowy
  „Dopasuj"). Klawiatura na płótnie **nie jest wymagana** — cała nawigacja jest na liście.
- Bez animacji przy zmianie `viewBox` pod `prefers-reduced-motion`.

### 8.6 Panel boczny

Klik węzła → `KnowledgeEntryPreview.vue` (**ten sam** komponent co popover hover, 4.5)
z `:actions="true"`:
`Button` „Otwórz wpis” · `Button variant="outline"` „Wyśrodkuj tutaj” ·
`Button variant="ghost" size="icon-sm" icon="x"` „Odrzuć podobieństwo” (tylko gdy
zaznaczono krawędź `similarity`).
Poniżej `next-lg` panel to `Drawer side="bottom" size="md"`.

### 8.7 Lista sąsiadów — powierzchnia dostępności (D9)

`KnowledgeGraphNeighbourList.vue`, wzorzec **`ui/variables/VariableBrowser.vue`**:

- Dokładnie **jeden** element focusowalny: kontener `tabindex="0"`, `role="listbox"`,
  `aria-activedescendant="<id aktywnego wiersza>"`. **Fokus DOM nigdy nie wędruje
  między wierszami** (virtual focus).
- Wiersze `role="option"`, `:aria-selected`, `:id="rowId(slug)"` z sanitizacją
  (`path.replace(/[^\w-]/g,'_')` — slugi mogą zawierać `-`, ale bezpiecznik zostaje).
- Grupy po typie krawędzi: `role="group"` + `aria-label` („Wikilinki", „Podobne",
  „Ręczne", „Nieistniejące").
- Klawiatura:
  | Klawisz | Akcja |
  | --- | --- |
  | `↑` / `↓` | przesuń aktywny wiersz (z clampem, jak `move(±1)` w `VariableBrowser`) |
  | `Home` / `End` | pierwszy / ostatni |
  | `Enter` | **re-centruj** → `router.push(graph/:slug)` |
  | `Space` | otwórz panel boczny (zaznaczenie bez nawigacji) |
  | `Escape` | wyczyść zaznaczenie |
  | znak drukowalny | type-ahead (bufor 600 ms, `startsWith`, zawijanie) |
- `scrollIntoView({ block:'nearest' })` po `id`, nigdy po ref (wzorzec `VariableBrowser`).
- Hover myszą ustawia ten sam kursor co klawiatura (`@mouseenter="setActive"`).
- Wiersz niesie **tę samą treść** co węzeł + panel: tytuł, typ relacji (tekstem!),
  `%` dla `similarity`, `Badge` statusu.

### 8.8 Filtry typów krawędzi + legenda

> ⚠ **Zaległość B10 + B13:** rodzajów krawędzi jest **pięć**, nie cztery — backend rysuje
> `mention` **domyślnie**, a frontend nie ma dla niego ani nazwy, ani wiersza legendy, ani chipa.
> Odrzucalność ma czytać `can_be_dismissed` z drutu, a nie wnioskować z rodzaju.
> Pełny wykaz naprawy: **§23 / „B10 (krawędzie `mention`)"**.

Rząd przełączników: `Button variant="subtle"` / `"outline"` z `aria-pressed`
(nie `SegmentedControl` — to wielokrotny wybór, nie jeden z zestawu).
Etykiety: „Wikilinki (N)”, „Podobne (N)”, „Ręczne (N)”, „Nieistniejące (N)”.

`KnowledgeGraphLegend.vue`: 4 wiersze `[próbka linii 24×2 px w inline SVG] [nazwa typu]`.
Legenda jest **obowiązkowa** i zawsze widoczna — wzory kreskowania wymagają dekodowania.

### 8.9 Stany grafu

| Stan | Realizacja |
| --- | --- |
| Ładowanie | **skeleton „kropki-węzły"**: 12 × `Skeleton variant="circle"` o średnicach 10–18 px, rozmieszczonych deterministycznie na okręgu (`position:absolute`, `left/top` z tego samego layoutu), + 3 poziome `Skeleton variant="rect" height="1px"` jako krawędzie; wrapper z `label` (`role="status"`) |
| Pusto (za mało linków) | `EmptyState icon="network"`, tytuł „Za mało połączeń, żeby narysować graf”, opis „Dodaj wikilinki `[[…]]` w treści wpisów albo poczekaj na zakończenie indeksowania — podobieństwa pojawią się same.”, `#action` „Otwórz czytnik”, `#secondary` link do docs |
| Pusto (filtry wyłączyły wszystko) | `EmptyState variant="search"` + „Włącz z powrotem typy połączeń” |
| Indeks niegotowy | `Banner variant="info"` nad płótnem z copy stanu indeksu (11.4) — graf i tak rysuje wikilinki |
| Błąd | `EmptyState variant="error"` + Ponów |
| Przepełnienie | `Text variant="caption"` z licznikiem + „+{n} dalszych” |

---

## 9. Ekran 8 — Wyszukiwarka

**Pliki:** `pages/knowledge/KnowledgeSearchView.vue`,
`pages/knowledge/search/KnowledgeSearchResultCard.vue`
**Trasa:** `next.knowledge.search` (globalna). W obrębie bazy ten sam komponent jest
osadzony w `Drawer` otwieranym skrótem z czytnika (`/`), z bazą przypiętą w filtrach.

### 9.1 Układ

`PageHeader :title="t('knowledge.search.title')" icon="search"`
→ `FilterBar` (`v-model:search` = zapytanie; `searchDebounce=400`) + `FilterTabBar`
(kontekst `knowledge_search` — zapisane widoki są tu **zapisanymi wyszukiwaniami**,
co jest naturalne)
→ `#results`: „Wyniki: {count}"
→ lista kart wyników (`<ul class="flex flex-col gap-next-3">`).

Filtry: `Select` bazy (multiple), `Select` statusu, `Switch` „tylko zatwierdzone”,
`DateRangeFilter` aktualizacji.

### 9.2 Karta wyniku

```
┌───────────────────────────────────────────────────────────────────────┐
│ [Icon file-text]  Tytuł wpisu (RouterLink)          [StatusBadge sm]  │
│ Baza · Rozdział › Podrozdział                        ← ścieżka        │
│ …tekst chunka z podświetlonym dopasowaniem…                           │
│ [Progress sm  ▓▓▓▓▓░░░]  Dopasowanie: 87%   ·  [Button link „Skocz do │
│                                                    fragmentu"]        │
└───────────────────────────────────────────────────────────────────────┘
```

- **Podświetlenie snippetu jest budowane z offsetów, NIGDY przez `v-html`.**
  Backend zwraca `snippet: string` + `highlights: [start,end][]`; FE tnie string na
  segmenty i renderuje `<span>` z `bg-next-primary-subtle text-next-primary-subtle-foreground
  rounded-next-xs px-next-0_5`. Wstrzykiwanie HTML z serwera jest zakazane.
- Ścieżka nagłówków: `Text variant="caption"`, separator `›`, `aria-label`
  „Ścieżka nagłówków”. **Nie** `Breadcrumbs` (to nie nawigacja, to lokalizacja).
- „Skocz do fragmentu” → `reader/:slug#h-<anchor>` (4.4).
- Score: pasek + **liczba**; `aria-label` „Dopasowanie: 87 procent”.

### 9.3 Stany

| Stan | Realizacja |
| --- | --- |
| Brak zapytania (start) | `EmptyState icon="search"` „Zacznij pisać, żeby przeszukać wiedzę” + 3 przykładowe zapytania jako `Button variant="outline" size="sm"` |
| Ładowanie | 4 × skeleton karty (linia tytułu 45 %, linia ścieżki 30 %, 2 linie snippetu, `rect` 6×80 px na pasek score) |
| Brak wyników | `EmptyState variant="search"` + „Wyczyść filtry”; gdy indeks niegotowy — **dodatkowo** `Alert variant="info"` z 11.4 |
| Błąd | `EmptyState variant="error"` + Ponów |
| Doładowanie | `useInfiniteScroll` + 2 skeletony kart |

> **Tryb „odpowiedz” (RAG z cytowaniami) jest poza MVP** — sekcja 20.
> W UI nie ma po nim żadnego śladu (żadnych wyszarzonych zakładek).

---

## 10. Ekran 9 — Kosz

**Plik:** `pages/knowledge/KnowledgeTrashView.vue`
**Trasa:** `next.knowledge.trash`

Wzorzec: kosz Dysku (`pages/disk/DiskView.vue`, poziom `sys:trash`) — z jedną różnicą:
kosz Wiedzy ma **własną trasę**, bo nie ma tu drzewa folderów, do którego dałoby się
go doczepić jako poziom.

### 10.1 Układ

`PageHeader :title="t('knowledge.trash.title')" icon="trash"`
→ `ui/navigation/Tabs.vue` `variant="pills"`: **Wpisy** / **Bazy**
(to zakres danych, nie nawigacja — dlatego pills, zgodnie z regułą z `ModuleTabs`)
→ `FilterBar` (szukaj, `Select` bazy, `DateRangeFilter` „usunięto”) — patrz **D14**
→ lista wierszy.

### 10.2 Wiersz kosza

`[Icon file-text|book-open] [nazwa] [Text caption: baza · usunął(a) CreatorBadge · {date}]`
— spacer — `[Button variant="outline" size="xs" leading-icon="rotate-ccw"] Przywróć`
`[Button variant="ghost" size="icon-xs" icon="trash"]` (danger, `aria-label`
„Usuń trwale: {name}”).

- **Przywróć** — jedno kliknięcie, bez dialogu (odwracalne), toast z potwierdzeniem.
  Jeśli baza wpisu też jest w koszu → przycisk `aria-disabled` + `Tooltip`
  „Najpierw przywróć bazę «{name}»”.
- **Usuń trwale** — `ConfirmDialog variant="danger"`:
  - tytuł: **„Usunąć trwale?"**
  - treść: „«{name}» zostanie usunięty **bezpowrotnie**, razem z historią wersji
    i indeksem. Tej operacji **nie da się cofnąć**."
    Dla bazy dodatkowo: „Usuniesz również **{count}** wpisów tej bazy."
  - `confirmLabel`: „Usuń trwale”, `:loading` na czas żądania.
- **Opróżnij kosz** w `#actions` `PageHeader` — `Button variant="danger" variant`
  z `ConfirmDialog` o jeszcze mocniejszym copy i licznikiem w treści.

### 10.3 Stany

Pusto: `EmptyState icon="trash"` „Kosz jest pusty” + „Usunięte wpisy i bazy trafiają tutaj”.
Reszta jak w 11.

---

## 11. Wzorce przekrojowe: stany

### 11.1 Skeletony (nigdy spinner + „Ładowanie")

| Miejsce | Kształt |
| --- | --- |
| Lista baz | 6 × `<EntityCard loading />` (doładowanie: 3) |
| Spis treści | 8 wierszy: `Skeleton variant="text"` na przemian `width="70%"` / `"50%"` |
| **Artykuł** | `Skeleton text width="55%" height="1.8rem"` (tytuł) + 2 `text width="7rem"` (badge) + **3 akapity** po 4 linie o szerokościach `95% / 100% / 88% / 62%`, `gap-next-3` między akapitami |
| Tabela | `Table :loading :loadingRows="8"` |
| **Graf** | 12 × `Skeleton variant="circle"` (10–18 px) na deterministycznym okręgu + 3 × `rect height="1px"` jako krawędzie |
| Wyniki szukania | 4 × karta: tytuł 45 %, ścieżka 30 %, 2 linie snippetu, `rect 6×80px` |
| Panele relacji | 3 wiersze: `text 60%` + `text 90%` |
| Popover podglądu | `text 60%` + 2 × `text 100%` |
| Historia wersji | `Timeline` w stanie `loading` (kilka pozycji) |
| Lista podpowiedzi `[[` | wiersze opcji: `circle` 16 px + `text 70%` |

Każdy blok skeletonów opakowany jednym regionem z `label` (`role="status"`, polite),
kształty `aria-hidden`.

### 11.2 Puste stany

| Ekran | Ikona | Tytuł | Akcja |
| --- | --- | --- | --- |
| Lista baz (pierwsze uruchomienie) | `book-open` | „Nie masz jeszcze żadnej bazy wiedzy" | Utwórz bazę + Przenieś z bota |
| Lista baz (po filtrach) | — (`variant="search"`) | „Brak baz spełniających filtry" | Wyczyść filtry |
| Nowa baza (czytnik) | `file-text` | „Ta baza jest pusta" | „Napisz pierwszy wpis" |
| Tabela wpisów | `file-text` / `search` | jw. | jw. |
| Graf | `network` | „Za mało połączeń, żeby narysować graf" | Otwórz czytnik |
| Szukaj (start) | `search` | „Zacznij pisać, żeby przeszukać wiedzę" | 3 przykłady |
| Szukaj (0 wyników) | — (`variant="search"`) | „Brak wyników" | Wyczyść filtry |
| Podobne | `sparkles` | „Nie znaleźliśmy podobnych wpisów" | — |
| Backlinki | `link` | „Żaden wpis jeszcze tu nie linkuje" | — |
| Kosz | `trash` | „Kosz jest pusty" | — |

### 11.3 Błąd + ponów

- **Poziom strony:** `EmptyState variant="error"` (`role="alert"` z automatu) +
  `#action` `Button variant="outline" leading-icon="rotate-ccw"` „Spróbuj ponownie".
- **Poziom panelu / doładowania:** `Alert variant="danger" size="sm"` z akcją w `#actions`.
- **Poziom akcji:** toast `danger` + pozostawienie formularza otwartego (nigdy nie
  zamykaj modala/drawera po błędzie — użytkownik straciłby wpisane dane).

### 11.4 Stany indeksu — jawne, zawsze z powodem

Copy jest **obowiązkowe** — użytkownik nigdy nie ma zgadywać, czemu wyszukiwanie
nie działa:

| Stan | Badge | Komunikat kontekstowy (`Alert`/`Banner`, `variant`) |
| --- | --- | --- |
| `pending` | „Oczekuje na indeks” · `clock` · neutral | info: „Ten wpis czeka w kolejce do zindeksowania. Wyszukiwanie semantyczne obejmie go za chwilę.” |
| `indexing` | „Indeksowanie” · `loader` · info | info: „Trwa indeksowanie. Wyniki mogą być niepełne.” |
| `indexed` | „Zaindeksowany” · `check-circle` · success | brak |
| `partial` | „Częściowo: {done}/{total}” · `alert-triangle` · warning | warning: „Zindeksowano {done} z {total} fragmentów. Reszta jest w kolejce.” |
| `pending_budget` | „Wstrzymane — budżet AI” · `wallet` · warning | warning: **„Indeksowanie wstrzymane — budżet AI wyczerpany. Dokończymy automatycznie po odnowieniu limitu.”** `#actions`: `Button variant="outline" size="sm"` „Zobacz zużycie AI” → `next.settings.aiUsage` |
| `failed` | „Błąd indeksowania” · `x-circle` · danger (solid) | danger: „Nie udało się zindeksować tego wpisu.” `#actions`: „Ponów indeksowanie” |

Stan indeksu **bazy** = agregat; `partial` bazy pokazuje `{done}/{total}` liczone
we wpisach, nie we fragmentach (różne jednostki — nie mieszać).

### 11.5 Toasty (kiedy toast, a kiedy dialog)

| Sytuacja | Wzorzec |
| --- | --- |
| Zapis wpisu / metadanych / schematu | toast `success` |
| Odrzucenie podobieństwa | toast `success` **z akcją Cofnij** (D13) |
| Przywrócenie z kosza | toast `success` |
| Przeniesienie do kosza | `ConfirmDialog` → toast **z akcją Cofnij** |
| Usunięcie trwałe | `ConfirmDialog` (mocne copy) → toast, **bez** Cofnij |
| Przywrócenie wersji | `ConfirmDialog` (uspokajające copy) → toast |
| Konflikt 409 | `Modal` (trzy wyjścia — 6.5) |

---

## 12. Badge'e i mapy statusów

Obie mapy jako `StatusMap` w `pages/knowledge/statusMaps.ts`, przekazywane do
`StatusBadge`/`EntityCard` przez `:status-map`. **Etykiety zawsze z i18n przez prop
`label`** (mapa trzyma tylko wariant/ton/ikonę + fallback EN).

### 12.1 Status wpisu

| `status` | variant · tone | ikona | PL |
| --- | --- | --- | --- |
| `draft` | neutral · subtle | `pencil` | Szkic |
| `proposed` | info · subtle | `inbox` | Zaproponowany |
| `approved` | success · subtle | `check-circle` | Zatwierdzony |
| `archived` | neutral · subtle | `archive` | Zarchiwizowany |

> `proposed` istnieje w danych, ale **nie ma skrzynki propozycji w MVP** (sekcja 20).
> Dlatego `Tooltip` przy tym badge'u: „Zaproponowany przez bota — zatwierdź w edytorze wpisu.”
> Bez tego użytkownik szuka nieistniejącej kolejki.

### 12.2 Stan indeksu

| `index_status` | variant · tone | ikona | PL |
| --- | --- | --- | --- |
| `pending` | neutral · subtle | `clock` | Oczekuje na indeks |
| `indexing` | info · subtle | `loader` | Indeksowanie |
| `indexed` | success · subtle | `check-circle` | Zaindeksowany |
| `partial` | warning · subtle | `alert-triangle` | Częściowo: {done}/{total} |
| `pending_budget` | warning · subtle | `wallet` | Wstrzymane — budżet AI |
| `failed` | danger · **solid** | `x-circle` | Błąd indeksowania |

### 12.3 Znaczniki poza `StatusBadge`

| Znacznik | Komponent |
| --- | --- |
| Nieaktualny (`stale`) | `Badge variant="warning" tone="subtle" icon="alert-triangle"` „Nieaktualny” |
| Czerwony link | `Badge variant="danger" tone="subtle" icon="unlink"` + licznik |
| Pole poza schematem | `Badge variant="warning" tone="subtle" icon="alert-triangle"` „Poza schematem” |
| Wersja aktualna | `Badge variant="primary" tone="subtle"` „Aktualna” |

---

## 13. Responsywność

| Breakpoint | Lista baz | Czytnik | Tabela | Graf | Szukaj |
| --- | --- | --- | --- | --- | --- |
| base (< 40 rem) | 1 kolumna kart | tylko artykuł; TOC w `Drawer` (`side="left"`, przycisk `menu`); panele = `Accordion` pod artykułem | `responsive="stack"` (karty etykieta/wartość), akcje **zawsze widoczne** | **domyślnie Lista**; graf pod `SegmentedControl` | karty pełnej szerokości |
| `next-sm` (40 rem) | 2 kolumny | — | — | — | — |
| `next-md` (48 rem) | — | — | prawdziwa tabela; akcje ujawniane na hover/focus | — | — |
| `next-lg` (64 rem) | — | TOC + artykuł; `ModuleAside` widoczny (`ModuleTabs` znika) | — | płótno + panel boczny | — |
| `next-xl` (80 rem) | 3 kolumny | TOC + artykuł + rail paneli | — | — | — |

Zasady twarde:
- Poniżej `next-lg` nawigację modułu przejmuje `ModuleTabs` (`ModuleAside` jest
  `hidden next-lg:flex` — nic nie trzeba robić).
- **Panele relacji mają identyczną zawartość** w railu i w `Accordionie` — jeden
  komponent na panel, dwa opakowania.
- Graf nigdy nie jest domyślny na wąskim ekranie (D9).
- Miara wiersza artykułu `max-w-[72ch]` obowiązuje od `next-lg` w górę.

---

## 14. Dark mode i kontrast

Wszystko przez tokeny `--color-next-*` — **zero surowych `hex`/`hsl`**, zero
`dark:` z ręcznymi kolorami (schemat ciemny to nadpisanie tokenów w `.next-root.dark`).

Punkty wymagające uwagi:

1. **SVG grafu** czyta wyłącznie tokeny (`var(--color-next-border)`,
   `var(--color-next-primary)`, `var(--color-next-primary-subtle)`,
   `var(--color-next-danger)`, `var(--color-next-fg)`) — dzięki temu tryb ciemny
   działa bez ani jednej linii dodatkowego kodu.
2. **Podświetlenie snippetu** = `bg-next-primary-subtle` + `text-next-primary-subtle-foreground`
   (w ciemnym to ciemny magenta + jasny tekst — kontrast zachowany; **nigdy** żółte tło).
3. **Duch / czerwony link** — `--color-next-danger` jest w ciemnym rozjaśniony
   (`hsl(356 72% 58%)`); to wystarcza. Rozróżnienie i tak nie polega na kolorze
   (przerywane podkreślenie + ikona).
4. **Etykiety w grafie** dostają podkład `--color-next-bg` — bez niego tekst na
   krawędziach traci kontrast w obu motywach.
5. **Pasek score** — `Progress` tone `primary`; procent **zawsze tekstem**, więc
   kontrast paska nie jest krytyczny.
6. Panele w railu na `bg-next-card` (nie `bg-next-bg`) — w ciemnym głębia bierze się
   z jaśniejszej powierzchni + obramowania, nie z cienia.
7. Sprawdzić kontrast `text-next-muted-foreground` na `bg-next-muted` w wierszach
   spisu treści — jeśli < 4.5:1, użyć `text-next-fg` z `opacity` **nie** wolno;
   użyć `--color-next-fg` i odróżnić rozmiarem/wagą.

---

## 15. Dostępność — checklista

**Struktura**
- [ ] Dokładnie **jeden** `<h1>` na stronę: w czytniku to tytuł wpisu, na pozostałych
      sekcjach `PageHeader` (h1 = nazwa sekcji, ADR-0011).
- [ ] Landmarki: `ModuleAside` daje `<nav>`; spis treści to własny `<nav aria-label>`
      z **inną** nazwą (dwa landmarki nie mogą dzielić nazwy).
- [ ] Panele relacji: `Accordion` daje `role="region"` + `aria-labelledby`.

**Klawiatura**
- [ ] Lista sąsiadów grafu: pełny model z 8.7 (jeden tab stop, `aria-activedescendant`).
- [ ] Podpowiedzi `[[`: `↑`/`↓`/`Enter`/`Escape` przez `handleKeyDown`; **fokus DOM
      zostaje w ProseMirror**.
- [ ] **Escape zamyka najbliższą warstwę.** Popup `[[` rejestruje się w
      `useOverlayStack` przez `createSuggestionOverlay` — inaczej Escape zamknie
      edytor i skasuje pracę.
- [ ] Wikilink-duch nie ma `href` → musi mieć `role="link" tabindex="0"` i obsługę
      `Enter`.
- [ ] Skróty czytnika: `[` / `]` (poprzedni/następny), `/` (szukaj), `e` (edytuj) —
      **nieaktywne**, gdy fokus jest w polu tekstowym lub edytorze.
- [ ] Wyjścia z edytora strzeżone (`onBeforeRouteLeave`), również przy nawigacji
      klawiaturą.

**Semantyka i ogłaszanie**
- [ ] Regiony ładowania: `role="status"` + `label` na wrapperze skeletonów.
- [ ] `EmptyState variant="error"` → `role="alert"` (wbudowane).
- [ ] Stan indeksu ogłaszany po zmianie (`aria-live="polite"` na kontenerze badge'a
      w nagłówku artykułu).
- [ ] Sortowanie tabeli → `aria-sort` (wbudowane w `Table`).
- [ ] Każdy `Button` ikonowy ma `aria-label` **z nazwą obiektu**
      („Usuń trwale: Polityka marki”), nie samo „Usuń”.
- [ ] `<time datetime>` przy każdej dacie.
- [ ] Popover podglądu: `role="tooltip"` + `aria-describedby` na kotwicy na czas otwarcia;
      **zero interaktywnych elementów** w środku.

**Kolor i ruch**
- [ ] Żaden stan nie jest wyrażony samym kolorem (badge = ikona + tekst; krawędzie
      grafu = wzór linii; „aktualność” = ikona + etykieta).
- [ ] Typy krawędzi opisane w **zawsze widocznej legendzie**.
- [ ] Ten sam komplet informacji dostępny w liście sąsiadów co w grafie.
- [ ] `prefers-reduced-motion`: brak animowanego przewijania do kotwicy, brak
      animacji `viewBox`, brak pulsu na skeletonach (globalna reguła `.next-root`).

**Formularze**
- [ ] Każda kontrolka w `FormField` (etykieta + opis + komunikat).
- [ ] Błąd serwera ma pierwszeństwo nad błędem klienta (wzorzec `ConstantEditorDrawer`).
- [ ] `aria-invalid` przez `:invalid` na `TypedLiteralInput`.
- [ ] Przyciski `aria-disabled` **zawsze** z `Tooltip` podającym powód.

---

## 16. Ikony

**Do dodania w `ui/primitives/icons.ts`** (i pokazania w galerii — checklista akceptacji
z matrycy komponentów):

| Nazwa | Kształt | Użycie |
| --- | --- | --- |
| `book-open` | otwarta książka | ikona modułu, ikona bazy |
| `network` | 3 węzły połączone liniami | sekcja Graf |

**Wykorzystane z istniejącego rejestru** (bez dodawania): `file-text` (wpis),
`search`, `settings`, `table`, `trash`, `rotate-ccw` (przywróć), `clock` (historia,
`pending`), `loader` (`indexing`), `check-circle`, `alert-triangle`, `x-circle`,
`wallet` (`pending_budget`), `archive`, `pencil`, `inbox`, `link` / `link-2`
(backlinki / linki wychodzące), `unlink` (czerwony link), `sparkles` (podobne / AI),
`braces` (metadane), `plus`, `x`, `copy`, `eye`, `more-vertical`, `chevron-left/right`,
`type`, `hash`, `calendar`, `list`, `user`, `menu`.

**Zasada rozróżnialności:** ikony sekcji modułu (`book-open`, `table`, `network`,
`settings`) mają cztery wyraźnie różne sylwetki — to warunek szybkiego skanowania
wzrokiem w `ModuleAside`. Nie podmieniać ich na warianty tej samej rodziny.

---

## 17. Inwentarz komponentów (REUSE / EXTEND / CREATE)

### 17.1 REUSE — bez żadnych zmian

| Ścieżka | Gdzie |
| --- | --- |
| `ui/patterns/PageHeader.vue` | 3, 5, 7, 8, 9, 10 |
| `ui/patterns/FilterBar.vue` + `FilterTabBar.vue` + `SaveViewModal.vue` | 3, 5, 9, (10) |
| `ui/patterns/EntityCard.vue` | 3 (karty baz) |
| `ui/patterns/CreatorBadge.vue` | 3, 4, 5, 10 |
| `ui/patterns/Timeline.vue` + `TimelineItem.vue` | 4.11 |
| `ui/data/Table.vue` | 5 |
| `ui/data/StatusBadge.vue`, `ui/primitives/Badge.vue` | wszędzie |
| `ui/data/DescriptionList.vue` | 4.7, 4.10 |
| `ui/data/EmptyState.vue`, `ui/data/Skeleton.vue` | wszędzie |
| `ui/layout/ModuleAside.vue`, `ModuleTabs.vue`, `Surface.vue` | 2.3 |
| `ui/navigation/Tabs.vue` | 10 (Wpisy/Bazy) |
| `ui/forms/SegmentedControl.vue` | 4.2, 7.3, 8 |
| `ui/variables/TypedLiteralInput.vue` | 4.7, 6.4 |
| `ui/forms/{TextInput,Textarea,Select,Switch,NumberInput,DatePicker,DateRangeFilter,UserSelect,FormField,FieldShell}.vue` | formularze |
| `ui/overlay/{Modal,Drawer,ConfirmDialog,DropdownMenu,Popover,Tooltip}.vue` | nakładki |
| `ui/feedback/{Alert,Banner,Progress}.vue` | komunikaty, score |
| `ui/disclosure/Accordion.vue` + `AccordionItem.vue` | panele < `next-xl` |
| `ui/primitives/{Button,Icon,Text,Heading,Link,Kbd}.vue` | wszędzie |
| `app/composables/{useInfiniteScroll,useConfirm,useToast,useDebounce,useAnchoredPosition,useFilterTabs}.ts` | listy, dialogi |
| `ui/editor/MarkdownEditor.vue` | 6 |

### 17.2 EXTEND — zmiany addytywne w design systemie

| Plik | Zmiana | Warunek |
| --- | --- | --- |
| `ui/editor/MarkdownViewer.vue` | **nowy, opcjonalny prop `wikilinks?: WikilinkOptions`**; transformacja po sanitizacji (D5) | przy braku propa wyjście **identyczne** jak dziś — przypiąć testem |
| `ui/editor/MarkdownEditor.vue` | **nowy, opcjonalny prop `wikilinks?: WikilinkOptions`** przekazywany do `buildPart2Extensions()` | domyślnie OFF, jak `mentions`/`variables` |
| `ui/primitives/icons.ts` | `book-open`, `network` | + wpis w galerii |
| `resources/css/next.css` | style `.next-md-prose a[data-wikilink]` / `[data-ghost]` | w `@layer components`, przy istniejącym bloku prose |
| `ui/forms/EntryListInput.vue` | **przeniesienie** z `pages/bots/` + i18n `entryForm.*` | tylko przy D16; inaczej pominąć |

### 17.3 CREATE — nowe pliki modułu

```
pages/knowledge/
├── KnowledgeModuleLayout.vue          shell + aside + nakładki
├── KnowledgeBasesView.vue             ekran 1
├── KnowledgeBaseView.vue              shell bazy (fetch + slim bar + RouterView)
├── KnowledgeReaderView.vue            ekran 2+4
├── KnowledgeEntriesTableView.vue      ekran 3
├── KnowledgeEntryEditorView.vue       ekran 5
├── KnowledgeBaseSettingsView.vue      ekran 6
├── KnowledgeGraphView.vue             ekran 7
├── KnowledgeSearchView.vue            ekran 8
├── KnowledgeTrashView.vue             ekran 9
├── KnowledgeBaseCard.vue
├── KnowledgeBaseEditorDrawer.vue
├── KnowledgeBaseForm.vue              wspólny formularz (drawer + ustawienia)
├── KnowledgeSchemaBuilder.vue         builder deskryptorów
├── KnowledgeMetadataForm.vue          formularz metadanych (rail + edytor)
├── KnowledgeVersionsDrawer.vue
├── KnowledgeStaleWriteModal.vue       409
├── KnowledgeBotMigrationModal.vue
├── statusMaps.ts                      dwie StatusMap + etykiety
├── types.ts                           typy odpowiedzi (1:1 z backendem, ZERO wymyślania)
├── reader/
│   ├── KnowledgeTocPanel.vue
│   ├── KnowledgeArticleBody.vue       wikilinki + kotwice + popover
│   ├── KnowledgeEntryRail.vue
│   ├── KnowledgeEntryPreview.vue      WSPÓŁDZIELONY: popover hover + panel grafu
│   ├── KnowledgeSimilarRow.vue
│   └── wikilinkAnchors.ts             slugify nagłówków + skok do kotwicy (czysta)
├── graph/
│   ├── KnowledgeGraphCanvas.vue
│   ├── KnowledgeGraphNeighbourList.vue
│   ├── KnowledgeGraphLegend.vue
│   └── knowledgeGraphLayout.ts        CZYSTA funkcja + testy Vitest
└── search/
    ├── KnowledgeSearchResultCard.vue
    └── highlightSegments.ts           offsety → segmenty (czysta, testowana)

ui/editor/extensions/
├── wikilink.ts                        plugin triggera `[[` (BEZ węzła)
└── WikilinkSuggest.vue                popup listboxa

app/stores/
└── knowledge.ts                       wzorzec workflows.ts: items/cursor/hasMore/
                                       loading/loadingMore/errored/loadMoreErrored
                                       + token guard + detail/detailLoading/detailError
                                       + cache podglądów per slug
```

---

## 18. Załącznik: klucze i18n (PL + EN)

Wstawić jako **jeden** blok `knowledge: { … }` do `app/i18n/en.ts` **i** `pl.ts`,
w tej samej pozycji (po `workflows`). `en.ts` definiuje typ `MessageSchema` —
brak lub nadmiar klucza w `pl.ts` **wywali `vue-tsc`** i test parity.
**Brak pluralizacji** — cała copy licznikowa w formie `Etykieta: {count}`.

Dodatkowo, poza blokiem: `nav.knowledge` = `'Wiedza'` / `'Knowledge'`.

### 18.1 Rdzeń modułu

| Klucz (`knowledge.`) | PL | EN |
| --- | --- | --- |
| `title` | Wiedza | Knowledge |
| `subtitle` | Bazy wiedzy Twojego zespołu — hasła, które czytają ludzie i AI. | Your team's knowledge bases — entries read by people and AI. |
| `module.hint` | Uporządkowana wiedza, z której korzystają boty i generator. | Structured knowledge your bots and generator rely on. |
| `module.tabs` | Sekcje Wiedzy | Knowledge sections |
| `module.pickBase` | Wybierz bazę | Pick a base |
| `module.pickBaseHint` | Otwórz bazę, aby zobaczyć jej wpisy, graf i ustawienia. | Open a base to see its entries, graph and settings. |
| `module.backToList` | Wróć do listy baz | Back to the base list |
| `module.nav.bases` | Bazy wiedzy | Knowledge bases |
| `module.nav.search` | Szukaj | Search |
| `module.nav.trash` | Kosz | Trash |
| `module.nav.reader` | Czytnik | Reader |
| `module.nav.table` | Tabela | Table |
| `module.nav.graph` | Graf | Graph |
| `module.nav.settings` | Ustawienia | Settings |
| `filters.clearAll` | Wyczyść filtry | Clear filters |
| `filters.index` | Stan indeksu | Index state |
| `filters.language` | Język | Language |
| `filters.status` | Status | Status |
| `filters.stale` | Tylko nieaktualne | Stale only |
| `filters.incomplete` | Niekompletne metadane | Incomplete metadata |
| `filters.withGhosts` | Z czerwonymi linkami | With red links |
| `filters.updated` | Aktualizacja | Updated |
| `filters.author` | Autor | Author |
| `filters.more` | Więcej filtrów | More filters |

### 18.2 Lista baz

| Klucz (`knowledge.bases.`) | PL | EN |
| --- | --- | --- |
| `new` | Nowa baza | New base |
| `filters.search` | Szukaj baz wiedzy | Search knowledge bases |
| `noCharter` | Brak karty tożsamości | No charter yet |
| `meta.entries` | Wpisy | Entries |
| `meta.language` | Język | Language |
| `meta.ghosts` | Czerwone linki | Red links |
| `meta.updated` | Aktualizacja | Updated |
| `menu.open` | Otwórz | Open |
| `menu.settings` | Ustawienia | Settings |
| `menu.duplicateSchema` | Duplikuj schemat | Duplicate schema |
| `menu.trash` | Przenieś do kosza | Move to trash |
| `empty.title` | Nie masz jeszcze żadnej bazy wiedzy | You have no knowledge base yet |
| `empty.description` | Baza wiedzy to mini-encyklopedia Twojego zespołu. Zacznij od jednej bazy na jeden obszar — na przykład „Marka" albo „Produkt". | A knowledge base is your team's mini-encyclopedia. Start with one base per area — for example "Brand" or "Product". |
| `empty.create` | Utwórz bazę | Create a base |
| `empty.migrate` | Przenieś wiedzę z bota | Move knowledge from a bot |
| `emptySearch.title` | Brak baz spełniających filtry | No bases match your filters |
| `trashConfirm.title` | Przenieść bazę do kosza? | Move base to trash? |
| `trashConfirm.message` | Baza i wpisy trafią do kosza. Wpisy do przeniesienia: {count}. Możesz je przywrócić. | The base and its entries go to the trash. Entries affected: {count}. You can restore them. |

### 18.3 Migracja z bota

| Klucz (`knowledge.migrate.`) | PL | EN |
| --- | --- | --- |
| `title` | Przenieś wiedzę z bota | Move knowledge from a bot |
| `botLabel` | Bot źródłowy | Source bot |
| `botPlaceholder` | Wybierz bota | Pick a bot |
| `botEmpty` | Żaden bot nie ma zapisanych wpisów wiedzy. | No bot has stored knowledge entries. |
| `nameLabel` | Nazwa nowej bazy | New base name |
| `countHint` | Wpisy do przeniesienia: {count} | Entries to move: {count} |
| `notice` | Wpisy zostaną skopiowane do nowej bazy. Konfiguracja bota pozostanie bez zmian. | Entries are copied into the new base. The bot's configuration stays unchanged. |
| `submit` | Przenieś | Move |
| `soon` | Migracja będzie dostępna wkrótce. | Migration is coming soon. |
| `success` | Utworzono bazę „{name}". Przeniesione wpisy: {count}. | Base "{name}" created. Entries moved: {count}. |

### 18.4 Czytnik

| Klucz (`knowledge.reader.`) | PL | EN |
| --- | --- | --- |
| `articleLabel` | Treść wpisu | Entry content |
| `mode.reader` | Czytnik | Reader |
| `mode.table` | Tabela | Table |
| `toc.title` | Spis treści | Contents |
| `toc.count` | Wpisy: {count} | Entries: {count} |
| `toc.search` | Filtruj wpisy | Filter entries |
| `toc.open` | Pokaż spis treści | Show contents |
| `toc.group` | Grupuj według | Group by |
| `toc.groupNone` | Bez grupowania | No grouping |
| `toc.groupEmpty` | Bez wartości | No value |
| `toc.moveUp` | Przenieś wyżej | Move up |
| `toc.moveDown` | Przenieś niżej | Move down |
| `prev` | Poprzedni wpis | Previous entry |
| `next` | Następny wpis | Next entry |
| `prevDisabled` | To pierwszy wpis w tej bazie | This is the first entry in this base |
| `nextDisabled` | To ostatni wpis w tej bazie | This is the last entry in this base |
| `edit` | Edytuj | Edit |
| `menu.history` | Historia wersji | Version history |
| `menu.duplicate` | Duplikuj wpis | Duplicate entry |
| `menu.trash` | Przenieś do kosza | Move to trash |
| `stale` | Nieaktualny | Stale |
| `staleHint` | Ten wpis oznaczono jako wymagający odświeżenia. | This entry is flagged as needing a refresh. |
| `updatedBy` | Zaktualizowano {date} | Updated {date} |
| `empty.title` | Ta baza jest pusta | This base is empty |
| `empty.description` | Zacznij od jednego hasła. Kolejne dopiszesz, a wikilinki `[[…]]` połączą je w całość. | Start with one entry. Add more later — `[[…]]` wikilinks will tie them together. |
| `empty.action` | Napisz pierwszy wpis | Write the first entry |
| `ghost.aria` | {label} — wpis nie istnieje | {label} — entry does not exist |
| `ghost.previewTitle` | Ten wpis jeszcze nie istnieje | This entry does not exist yet |
| `ghost.previewHint` | Kliknij, aby go utworzyć. | Click to create it. |
| `preview.error` | Nie udało się wczytać podglądu | Could not load the preview |
| `jump.label` | Skocz do fragmentu | Jump to passage |

### 18.5 Panele relacji

| Klucz (`knowledge.panels.`) | PL | EN |
| --- | --- | --- |
| `metadata` | Metadane | Metadata |
| `similar` | Podobne | Similar |
| `linksOut` | Linkuje do | Links to |
| `linksIn` | Linkowane z | Linked from |
| `ghosts` | Czerwone linki | Red links |
| `provenance` | Pochodzenie | Provenance |
| `history` | Historia wersji | Version history |
| `similar.score` | Dopasowanie: {percent}% | Match: {percent}% |
| `similar.why` | Dlaczego podobne? | Why similar? |
| `similar.dismiss` | Odrzuć podobieństwo: {title} | Dismiss similarity: {title} |
| `similar.dismissed` | Odrzucono podobieństwo | Similarity dismissed |
| `similar.undo` | Cofnij | Undo |
| `similar.empty` | Nie znaleźliśmy podobnych wpisów | We found no similar entries |
| `similar.more` | Pokaż więcej | Show more |
| `linksIn.empty` | Żaden wpis jeszcze tu nie linkuje | No entry links here yet |
| `linksOut.empty` | Ten wpis nie linkuje jeszcze do niczego | This entry does not link anywhere yet |
| `ghosts.create` | Utwórz wpis | Create entry |
| `ghosts.showAll` | Pokaż wszystkie czerwone linki w bazie | Show every red link in this base |
| `provenance.createdBy` | Utworzył(a) | Created by |
| `provenance.createdAt` | Utworzono | Created |
| `provenance.updatedBy` | Ostatnia zmiana | Last change |
| `provenance.source` | Źródło | Source |
| `provenance.source.manual` | Ręcznie | Manual |
| `provenance.source.bot` | Import z bota | Imported from a bot |
| `provenance.source.ai` | Wygenerowane AI | AI-generated |
| `provenance.slug` | Identyfikator (slug) | Slug |
| `provenance.slugCopied` | Skopiowano identyfikator | Slug copied |

### 18.6 Metadane

| Klucz (`knowledge.metadata.`) | PL | EN |
| --- | --- | --- |
| `edit` | Edytuj metadane | Edit metadata |
| `noValue` | Brak wartości | No value |
| `empty` | Ta baza nie ma jeszcze schematu metadanych | This base has no metadata schema yet |
| `outOfSchema` | Poza schematem | Off-schema |
| `outOfSchemaHint` | To pole zniknęło ze schematu bazy. Wartość zachowana. | This field left the base schema. The value is kept. |
| `unsupported` | Tego typu pola nie da się tu edytować. | This field type cannot be edited here. |
| `yes` | Tak | Yes |
| `no` | Nie | No |
| `addItem` | Dodaj element | Add item |
| `removeItem` | Usuń element | Remove item |
| `saved` | Zapisano metadane | Metadata saved |

### 18.7 Tabela wpisów

| Klucz (`knowledge.entries.`) | PL | EN |
| --- | --- | --- |
| `title` | Wpisy | Entries |
| `new` | Nowy wpis | New entry |
| `filters.search` | Szukaj we wpisach | Search entries |
| `caption` | Lista wpisów tej bazy wiedzy | Entries in this knowledge base |
| `col.title` | Tytuł | Title |
| `col.status` | Status | Status |
| `col.stale` | Aktualność | Freshness |
| `col.index` | Indeks | Index |
| `col.creator` | Autor | Author |
| `col.updated` | Aktualizacja | Updated |
| `col.actions` | Akcje | Actions |
| `fresh` | Aktualny | Fresh |
| `actions.open` | Otwórz | Open |
| `actions.openAria` | Otwórz wpis: {title} | Open entry: {title} |
| `actions.edit` | Edytuj | Edit |
| `actions.editAria` | Edytuj wpis: {title} | Edit entry: {title} |
| `actions.more` | Więcej akcji: {title} | More actions: {title} |
| `actions.history` | Historia wersji | Version history |
| `actions.duplicate` | Duplikuj | Duplicate |
| `actions.changeStatus` | Zmień status | Change status |
| `actions.trash` | Przenieś do kosza | Move to trash |
| `empty.title` | Ta baza nie ma jeszcze wpisów | This base has no entries yet |
| `emptySearch.title` | Brak wpisów spełniających filtry | No entries match your filters |
| `trashConfirm.title` | Przenieść wpis do kosza? | Move entry to trash? |
| `trashConfirm.message` | „{title}" trafi do kosza. Możesz go przywrócić. | "{title}" goes to the trash. You can restore it. |
| `trashed` | Przeniesiono do kosza | Moved to trash |

### 18.8 Statusy i indeks

| Klucz (`knowledge.status.`) | PL | EN |
| --- | --- | --- |
| `draft` | Szkic | Draft |
| `proposed` | Zaproponowany | Proposed |
| `proposedHint` | Zaproponowany przez bota — zatwierdź w edytorze wpisu. | Proposed by a bot — approve it in the entry editor. |
| `approved` | Zatwierdzony | Approved |
| `archived` | Zarchiwizowany | Archived |
| `draftHint` | Widoczny tylko dla zespołu; AI go nie użyje. | Visible to the team only; AI will not use it. |
| `approvedHint` | Dostępny dla AI i wyszukiwania. | Available to AI and search. |
| `archivedHint` | Ukryty w czytniku; zostaje w bazie. | Hidden in the reader; stays in the base. |

| Klucz (`knowledge.index.`) | PL | EN |
| --- | --- | --- |
| `pending` | Oczekuje na indeks | Waiting for indexing |
| `pendingHint` | Ten wpis czeka w kolejce do zindeksowania. Wyszukiwanie semantyczne obejmie go za chwilę. | This entry is queued for indexing. Semantic search will cover it shortly. |
| `indexing` | Indeksowanie | Indexing |
| `indexingHint` | Trwa indeksowanie. Wyniki mogą być niepełne. | Indexing is running. Results may be incomplete. |
| `indexed` | Zaindeksowany | Indexed |
| `partial` | Częściowo: {done}/{total} | Partial: {done}/{total} |
| `partialHint` | Zindeksowano {done} z {total} fragmentów. Reszta jest w kolejce. | Indexed {done} of {total} passages. The rest is queued. |
| `pendingBudget` | Wstrzymane — budżet AI | Paused — AI budget |
| `pendingBudgetHint` | Indeksowanie wstrzymane — budżet AI wyczerpany. Dokończymy automatycznie po odnowieniu limitu. | Indexing is paused — the AI budget is used up. We will finish automatically once the limit renews. |
| `pendingBudgetAction` | Zobacz zużycie AI | See AI usage |
| `failed` | Błąd indeksowania | Indexing failed |
| `failedHint` | Nie udało się zindeksować tego wpisu. | We could not index this entry. |
| `retry` | Ponów indeksowanie | Retry indexing |

### 18.9 Edytor wpisu

| Klucz (`knowledge.editor.`) | PL | EN |
| --- | --- | --- |
| `newTitle` | Nowy wpis | New entry |
| `editTitle` | Edycja: {title} | Editing: {title} |
| `back` | Wróć | Back |
| `titleLabel` | Tytuł | Title |
| `titlePlaceholder` | Nazwij hasło jednym pojęciem | Name the entry with one concept |
| `titleRequired` | Tytuł jest wymagany | A title is required |
| `contentPlaceholder` | Napisz hasło. Wpisz `[[`, aby połączyć je z innym wpisem. | Write the entry. Type `[[` to link to another one. |
| `slugLabel` | Identyfikator (slug) | Slug |
| `slugHint` | Używany w wikilinkach `[[…]]`. Zmiana zrywa istniejące linki. | Used in `[[…]]` wikilinks. Changing it breaks existing links. |
| `slugInvalid` | Dozwolone są małe litery, cyfry i myślniki | Lowercase letters, digits and hyphens only |
| `statusLabel` | Status | Status |
| `settingsSection` | Ustawienia wpisu | Entry settings |
| `staleLabel` | Oznacz jako nieaktualny | Flag as stale |
| `positionLabel` | Pozycja w spisie treści | Position in contents |
| `save` | Zapisz | Save |
| `cancel` | Anuluj | Cancel |
| `saved` | Zapisano wpis | Entry saved |
| `overLimit.title` | Ten wpis przekracza 40 000 znaków | This entry is over 40,000 characters |
| `overLimit.body` | Wpis to hasło encyklopedyczne — jeśli materiał jest dłuższy, podziel go na kilka haseł i połącz je wikilinkami `[[…]]`. | An entry is an encyclopedia entry — if the material is longer, split it into several entries and connect them with `[[…]]` wikilinks. |
| `overLimit.help` | Jak dzielić wpisy? | How to split entries? |
| `overLimit.saveBlocked` | Zmniejsz treść do 40 000 znaków, aby zapisać | Shorten the content to 40,000 characters to save |
| `leave.title` | Porzucić niezapisane zmiany? | Discard unsaved changes? |
| `leave.message` | Masz niezapisane zmiany w tym wpisie. Jeśli wyjdziesz, przepadną. | You have unsaved changes in this entry. Leaving discards them. |
| `leave.confirm` | Porzuć | Discard |
| `link.searching` | Szukam wpisów… | Searching entries… |
| `link.empty` | Brak dopasowań | No matches |
| `link.create` | Utwórz wpis „{query}" | Create entry "{query}" |
| `link.listLabel` | Wpisy do połączenia | Entries to link |

### 18.10 Konflikt zapisu (409)

| Klucz (`knowledge.conflict.`) | PL | EN |
| --- | --- | --- |
| `title` | Ktoś zapisał ten wpis w międzyczasie | Someone saved this entry meanwhile |
| `message` | {author} zapisał(a) nowszą wersję {time}. Twoje zmiany nie zostały jeszcze zapisane. | {author} saved a newer version {time}. Your changes are not saved yet. |
| `safe` | Twoja treść jest bezpieczna — dopóki nie wybierzesz opcji, nic nie znika. | Your content is safe — nothing disappears until you choose an option. |
| `keep` | Zostaw moje zmiany | Keep my changes |
| `openLatest` | Otwórz najnowszą wersję w nowej karcie | Open the latest version in a new tab |
| `overwrite` | Nadpisz moją wersją | Overwrite with mine |
| `overwriteConfirm.title` | Nadpisać cudze zmiany? | Overwrite someone else's changes? |
| `overwriteConfirm.message` | Nadpiszesz zmiany {author}. Ich wersja zostanie w historii. | You will overwrite {author}'s changes. Their version stays in the history. |

### 18.11 Historia wersji

| Klucz (`knowledge.history.`) | PL | EN |
| --- | --- | --- |
| `title` | Historia wersji | Version history |
| `open` | Pokaż historię wersji | Show version history |
| `version` | Wersja {number} | Version {number} |
| `current` | Aktualna | Current |
| `restore` | Przywróć | Restore |
| `restoreConfirm.title` | Przywrócić wersję {number}? | Restore version {number}? |
| `restoreConfirm.message` | Przywrócenie utworzy nową wersję. Nic nie zostanie utracone — obecną treść nadal znajdziesz w historii. | Restoring creates a new version. Nothing is lost — the current content stays in the history. |
| `restored` | Przywrócono wersję {number} | Version {number} restored |
| `empty` | Ta wersja jest pierwsza — nie ma jeszcze historii | This is the first version — no history yet |
| `feedLabel` | Lista wersji wpisu | Entry version list |

### 18.12 Ustawienia bazy

| Klucz (`knowledge.settings.`) | PL | EN |
| --- | --- | --- |
| `title` | Ustawienia bazy | Base settings |
| `identity` | Tożsamość | Identity |
| `nameLabel` | Nazwa bazy | Base name |
| `languageLabel` | Język bazy | Base language |
| `languageHint` | Język bazy steruje indeksowaniem i podpowiedziami AI. | The base language drives indexing and AI hints. |
| `charter` | Karta tożsamości | Charter |
| `charterHint` | Opisz bazę tak, jak wytłumaczył(a)byś ją nowej osobie. | Describe the base the way you would explain it to a newcomer. |
| `charterPlaceholder` | Czym jest ta baza?\nDla kogo jest przeznaczona?\nJakim tonem mówi?\nCo obejmuje?\nCzego świadomie NIE zawiera? | What is this base?\nWho is it for?\nWhat tone does it use?\nWhat does it cover?\nWhat does it deliberately NOT cover? |
| `charterNotice` | Karta tożsamości trafia do modeli AI korzystających z tej bazy. Im konkretniej opiszesz zakres i wykluczenia, tym mniej zmyśleń. | The charter is fed to the AI models using this base. The more precisely you state scope and exclusions, the less it makes things up. |
| `saved` | Zapisano ustawienia bazy | Base settings saved |
| `danger` | Strefa niebezpieczna | Danger zone |
| `dangerTrash` | Przenieś bazę do kosza | Move base to trash |

### 18.13 Builder schematu

| Klucz (`knowledge.schema.`) | PL | EN |
| --- | --- | --- |
| `title` | Schemat metadanych | Metadata schema |
| `hint` | Pola, które wypełnia się przy każdym wpisie i po których można filtrować. | Fields filled in on every entry, and filterable afterwards. |
| `fieldLegend` | Klucz i etykieta | Key and label |
| `keyPlaceholder` | klucz_pola | field_key |
| `labelPlaceholder` | Nazwa widoczna dla ludzi | Human-readable name |
| `keyInvalid` | Klucz może zawierać litery, cyfry i podkreślenia; musi zaczynać się od litery. | A key may contain letters, digits and underscores, and must start with a letter. |
| `keyDuplicate` | Ten klucz już istnieje w schemacie | This key already exists in the schema |
| `labelRequired` | Etykieta jest wymagana | A label is required |
| `typeLabel` | Typ | Type |
| `nullable` | Opcjonalne | Optional |
| `array` | Lista wartości | List of values |
| `options` | Opcje wyboru | Choices |
| `optionKey` | Klucz | Key |
| `optionLabel` | Etykieta | Label |
| `optionAdd` | Dodaj opcję | Add choice |
| `optionKeyRequired` | Każda opcja musi mieć klucz | Every choice needs a key |
| `optionKeyDuplicate` | Klucze opcji muszą być unikalne | Choice keys must be unique |
| `addField` | Dodaj pole | Add field |
| `removeField` | Usuń pole | Remove field |
| `moveUp` | Przenieś wyżej | Move up |
| `moveDown` | Przenieś niżej | Move down |
| `empty` | Ta baza nie ma jeszcze schematu metadanych | This base has no metadata schema yet |
| `changeNotice` | Zmiana schematu nie zmienia istniejących wpisów. Nowe pole będzie puste we wpisach, które już istnieją; usunięte pole przestaje być pokazywane, ale jego wartości zostają. | Changing the schema does not change existing entries. A new field is empty on entries that already exist; a removed field stops being shown but its values are kept. |
| `incompleteBanner` | Wpisy z brakami metadanych: {count} | Entries with missing metadata: {count} |
| `incompleteAction` | Pokaż te wpisy | Show those entries |

### 18.14 Graf

| Klucz (`knowledge.graph.`) | PL | EN |
| --- | --- | --- |
| `title` | Graf | Graph |
| `subtitle` | Jak wpisy tej bazy łączą się ze sobą. | How this base's entries connect. |
| `mode.list` | Lista | List |
| `mode.graph` | Graf | Graph |
| `depth` | Głębokość | Depth |
| `depth1` | 1 krok | 1 step |
| `depth2` | 2 kroki | 2 steps |
| `fit` | Dopasuj | Fit |
| `zoomIn` | Przybliż | Zoom in |
| `zoomOut` | Oddal | Zoom out |
| `capped` | Pokazano {shown} z {total} wpisów o największej liczbie połączeń | Showing {shown} of {total} most-connected entries |
| `showMore` | +{count} dalszych | +{count} more |
| `edge.wikilink` | Wikilinki | Wikilinks |
| `edge.similarity` | Podobne | Similar |
| `edge.manual` | Ręczne | Manual |
| `edge.ghost` | Nieistniejące | Missing |
| `edge.count` | {label} ({count}) | {label} ({count}) |
| `legend` | Legenda połączeń | Connection legend |
| `neighbours` | Sąsiedzi | Neighbours |
| `neighboursLabel` | Lista sąsiadów wybranego wpisu | Neighbours of the selected entry |
| `center` | Wyśrodkuj tutaj | Centre here |
| `openEntry` | Otwórz wpis | Open entry |
| `canvasHidden` | Graf jest ilustracją. Pełna treść jest w liście sąsiadów obok. | The graph is an illustration. The full content is in the neighbour list beside it. |
| `empty.title` | Za mało połączeń, żeby narysować graf | Not enough connections to draw a graph |
| `empty.description` | Dodaj wikilinki `[[…]]` w treści wpisów albo poczekaj na zakończenie indeksowania — podobieństwa pojawią się same. | Add `[[…]]` wikilinks inside your entries, or wait for indexing to finish — similarities will show up on their own. |
| `empty.action` | Otwórz czytnik | Open the reader |
| `emptyFiltered.title` | Wszystkie typy połączeń są wyłączone | Every connection type is turned off |
| `emptyFiltered.action` | Włącz z powrotem | Turn them back on |
| `loadingLabel` | Ładowanie grafu | Loading the graph |

### 18.15 Wyszukiwarka

| Klucz (`knowledge.search.`) | PL | EN |
| --- | --- | --- |
| `title` | Szukaj w wiedzy | Search knowledge |
| `placeholder` | Czego szukasz? | What are you looking for? |
| `results` | Wyniki: {count} | Results: {count} |
| `baseFilter` | Baza | Base |
| `approvedOnly` | Tylko zatwierdzone | Approved only |
| `headingPath` | Ścieżka nagłówków | Heading path |
| `score` | Dopasowanie: {percent}% | Match: {percent}% |
| `jump` | Skocz do fragmentu | Jump to passage |
| `start.title` | Zacznij pisać, żeby przeszukać wiedzę | Start typing to search your knowledge |
| `start.description` | Wyszukiwanie łączy dopasowanie słów i znaczenia — pytaj pełnym zdaniem. | Search combines keyword and meaning matching — ask in a full sentence. |
| `empty.title` | Brak wyników | No results |
| `empty.description` | Spróbuj innych słów albo poszerz filtry. | Try different words or widen the filters. |
| `indexNotice` | Część wpisów nie jest jeszcze zaindeksowana — wyniki mogą być niepełne. | Some entries are not indexed yet — results may be incomplete. |

### 18.16 Kosz

| Klucz (`knowledge.trash.`) | PL | EN |
| --- | --- | --- |
| `title` | Kosz | Trash |
| `tabs.entries` | Wpisy | Entries |
| `tabs.bases` | Bazy | Bases |
| `filters.search` | Szukaj w koszu | Search the trash |
| `deletedAt` | Usunięto | Deleted |
| `deletedBy` | Usunął(a) | Deleted by |
| `restore` | Przywróć | Restore |
| `restoreAria` | Przywróć: {name} | Restore: {name} |
| `restored` | Przywrócono „{name}" | "{name}" restored |
| `restoreBlocked` | Najpierw przywróć bazę „{name}" | Restore the base "{name}" first |
| `deleteForever` | Usuń trwale | Delete forever |
| `deleteForeverAria` | Usuń trwale: {name} | Delete forever: {name} |
| `deleteForeverTitle` | Usunąć trwale? | Delete forever? |
| `deleteForeverEntry` | „{name}" zostanie usunięty bezpowrotnie, razem z historią wersji i indeksem. Tej operacji nie da się cofnąć. | "{name}" will be deleted permanently, together with its version history and index. This cannot be undone. |
| `deleteForeverBase` | „{name}" zostanie usunięta bezpowrotnie. Usuniesz również wpisy tej bazy: {count}. Tej operacji nie da się cofnąć. | "{name}" will be deleted permanently. You will also delete its entries: {count}. This cannot be undone. |
| `emptyTrash` | Opróżnij kosz | Empty the trash |
| `emptyTrashTitle` | Opróżnić kosz? | Empty the trash? |
| `emptyTrashMessage` | Usuniesz bezpowrotnie wszystko, co jest w koszu. Elementy: {count}. Tej operacji nie da się cofnąć. | You will permanently delete everything in the trash. Items: {count}. This cannot be undone. |
| `empty.title` | Kosz jest pusty | The trash is empty |
| `empty.description` | Usunięte wpisy i bazy trafiają tutaj. | Deleted entries and bases land here. |

### 18.17 Wspólne komunikaty

| Klucz (`knowledge.common.`) | PL | EN |
| --- | --- | --- |
| `retry` | Spróbuj ponownie | Try again |
| `loadError` | Nie udało się wczytać danych | We could not load the data |
| `saveError` | Nie udało się zapisać zmian | We could not save your changes |
| `loadingLabel` | Ładowanie | Loading |
| `undo` | Cofnij | Undo |
| `copy` | Kopiuj | Copy |
| `copied` | Skopiowano | Copied |

---

## 19. Pytania do backendu (kontrakt do potwierdzenia)

**Frontend nie wymyśla żadnego z poniższych kształtów — to lista do potwierdzenia
przed B4.**

1. **Wyszukiwanie:** czy odpowiedź niesie `matched_chunk` jako
   `{ snippet: string, highlights: [number,number][], heading_path: string[], anchor: string }`?
   Podświetlenie **musi** przyjść jako offsety, nie jako HTML/markdown (sekcja 9.2).
2. **Kotwica fragmentu:** czy `anchor` jest generowany po stronie serwera, czy FE ma
   slugifikować nagłówek? (spec zakłada, że serwer podaje `anchor`; fallback = slugify
   klienta, ale wtedy oba muszą używać **tego samego** algorytmu).
3. **Stany indeksu:** dokładne nazwy w enumie (`pending`, `indexing`, `indexed`,
   `partial`, `pending_budget`, `failed`)? Czy `partial` niesie `{ done, total }`?
   Czy jednostka na poziomie **bazy** to wpisy, a na poziomie **wpisu** — fragmenty?
4. **Krawędzie grafu:** kształt `evidence` przy `similarity` (żeby „Dlaczego podobne?"
   renderowało realne pola, a nie wymyślone). Czy `manual` i odrzucenia
   (`dismissed`) są osobnymi rekordami?
5. **Cap i sortowanie grafu:** czy serwer potrafi zwrócić „top-N wg stopnia"
   (`GET …/graph?limit=60`) + `total`, czy FE tnie lokalnie?
6. **Podgląd wpisu (hover):** dedykowany, tani endpoint
   `GET /knowledge/bases/{base}/entries/{slug}/preview` → `{ title, status, excerpt, exists }`?
   Bez tego popover ściągałby pełną treść (do 40 kB) przy każdym najechaniu.
7. **Konflikt zapisu:** czy `PUT` wpisu przyjmuje `expected_version`/`updated_at` i
   zwraca **409** z `{ latest: { updated_at, creator, version } }`? UI z 6.5 tego wymaga.
8. **Kosz:** czy wzorzec Dysku (`?trashed=1`, `POST …/restore`, `DELETE …/force`)
   obowiązuje też tu — dla wpisów **i** baz? Czy istnieje „opróżnij kosz"?
9. **Zmiana schematu:** co się dzieje z wartościami usuniętego pola (zachowane/ukryte
   vs skasowane)? Copy w 7.4 obiecuje „wartości zostają" — musi być prawdziwe.
   Czy odpowiedź zapisu schematu zwraca `incomplete_entries_count`?
10. **Języki bazy:** lista dostępnych języków z serwera czy stała `['pl','en']`?
11. **Migracja z bota:** endpoint + kształt odpowiedzi (`{ base, moved_count }`);
    czy `bots.knowledge.entries` zostaje nietknięte (copy w 3.6 to obiecuje)?
12. **Uprawnienia:** czy zasoby niosą flagi `can_edit` / `can_delete` / `can_restore`
    (konwencja `can_be_edited`, `can_run` z workflows)? UI **nie zgaduje** uprawnień.
13. **Kolejność wpisów:** czy `position` jest zapisywalne per wpis, czy trzeba wysyłać
    całą listę? (dotyczy `↑`/`↓` w spisie treści).
14. **Liczniki na karcie bazy:** `entries_count`, `ghost_links_count`, `index_status`,
    `language`, `updated_at`, `creator` — czy wszystkie są w liście baz (inaczej N+1
    zapytań z FE).

---

## 20. Czego NIE ma w MVP

Świadomie poza zakresem. **W UI nie ma po nich śladu** — żadnych wyszarzonych
zakładek, żadnych „wkrótce" (poza jawnym przypadkiem migracji z bota, D15).

| Nie ma | Dlaczego / kiedy |
| --- | --- |
| **Skrzynka propozycji** (kolejka wpisów `proposed`) | Status istnieje w danych i renderuje się jako badge z podpowiedzią (12.1), ale osobny ekran kolejki wymaga modelu decyzji/akceptacji — to jest praca dla integracji z modułem Akceptacji, nie dla B4. |
| **Tryb „odpowiedz" (RAG z cytowaniami)** | Wymaga budżetu AI per zapytanie, cytowań z gwarancją źródła i osobnego kontraktu. Osobna iteracja po ustabilizowaniu indeksu. |
| ~~**Diff wersji**~~ | ~~Historia + przywracanie wystarczą do odwracalności.~~ **B13: WCHODZI.** `ui/data/TextDiffView.vue` istnieje, a treść wpisu nie może zawierać dyrektyw (blokuje strażnik backendu), więc obawa o „diff markdownu z dyrektywami" nie dotyczy tego modułu. Patrz §25.3. |
| **CommandPalette dla wiedzy** (⌘K → skok do wpisu) | Komponent istnieje (`ui/overlay/CommandPalette.vue`), ale globalna paleta to decyzja na poziomie aplikacji, nie modułu. Kandydat na natychmiastowy follow-up. |
| **Sekcje drzewiaste / hierarchia wpisów** | D3. Model danych jest płaski; hierarchia to zmiana backendu. |
| **Drag & drop kolejności** | `↑`/`↓` są dostępne z klawiatury i wystarczające; D&D bez klawiaturowego odpowiednika byłby regresem dostępności. |
| **Edycja masowa** (zmiana statusu wielu wpisów) | `Table` ma `selectable`, więc to tani follow-up — ale wymaga endpointu batch. |
| **Eksport / import** (poza migracją z bota) | Osobna iteracja. |
| **Komentarze do wpisów** | `ui/patterns/CommentsPanel.vue` istnieje; dołożenie później jest addytywne. |
| **Publiczne udostępnianie bazy** | Poza modelem tenancy. |
| **Tłumaczenia wpisów** | Baza ma jeden język (7.1). |
| **Podgląd na hover w tabeli i wynikach szukania** | Tylko w czytniku — inaczej popover walczy z hoverem wiersza. |
| **Dekoracja `[[…]]` w edytorze** | D4; opcja na B5b. |

---

## 21. Ryzyka spójności

| # | Ryzyko | Mitygacja |
| --- | --- | --- |
| R1 | **Escape kasujący pracę.** Popup `[[` bez rejestracji w `useOverlayStack` zamknie edytor/drawer i skasuje niezapisaną treść (dokładnie ta regresja trafiła już `@`-wzmianki i `Select`). | `createSuggestionOverlay(close)` + `acquire()/release()` **obowiązkowo**; test DOM: Escape przy otwartej liście nie emituje zamknięcia nadrzędnej nakładki. |
| R2 | **Rozszczelnienie sanitizacji `MarkdownViewer`.** Prop `wikilinks` dotyka potoku bezpieczeństwa. | Transformacja **wyłącznie po** `DOMPurify.sanitize`, elementy budowane `createElement` + `textContent`. Test: przy braku propa wyjście identyczne; test: `[[<img src=x onerror=…>]]` nie tworzy elementu. |
| R3 | **Dwie powierzchnie czytania.** Osobny „czytnik" i osobna „karta wpisu" rozjadą się w tygodniu. | D10 — jedna trasa, jeden komponent artykułu. |
| R4 | **Dwa podglądy wpisu.** Popover na hover i panel grafu to ta sama treść. | Jeden `KnowledgeEntryPreview.vue`, różnica tylko w propie `actions`. |
| R5 | **Brak pluralizacji w i18n.** „1 wpisów" | Cała copy licznikowa w formie `Etykieta: {count}` (jak istniejące `nav.approvalsBadge`). Zapisane w 18. |
| R6 | **Parity katalogów.** `pl.ts` jest typowany `MessageSchema` z `en.ts`. | Dodawać klucze do **obu** plików w jednym commicie; `vue-tsc` + test parity są bramką. |
| R7 | **`FilterTabBar` w Koszu.** D14 to wnioskowany wyjątek od bezwarunkowej reguły. | Decyzja właściciela **przed** B4; domyślka = reguła wygrywa (dodać `FilterTabBar`). |
| R8 | **Awans `EntryListInput`.** D16 rusza 3 użycia w Botach + blok i18n. | Wariant fallback (wiersze inline jak w `ConstantEditorDrawer`) jest w pełni zgodny z konwencją — wybór należy do właściciela. |
| R9 | **Nowa zdolność: SVG.** W repo nie ma ani jednej wizualizacji grafowej. | Layout wydzielony jako **czysta funkcja** z testami; SVG rysuje tylko to, co dostanie; brak nowych zależności. |
| R10 | **Cap 60 węzłów kłamie o wielkości bazy.** | Zawsze widoczne „Pokazano {shown} z {total}" + „+{n} dalszych". Nigdy cichego ucięcia. |
| R11 | **`proposed` bez skrzynki.** Użytkownik zobaczy status i będzie szukał kolejki. | `Tooltip` przy badge'u kierujący do edytora wpisu (12.1). |
| R12 | **Nowe ikony poza galerią.** | `book-open` i `network` muszą trafić do `docs/pages/IconPage.vue` — checklista akceptacji komponentu tego wymaga. |
| R13 | **Wymyślone klucze payloadu.** Znany błąd projektu (Etap 5). | Sekcja 19 domyka kontrakt **przed** kodem; `pages/knowledge/types.ts` powstaje z odpowiedzi backendu 1:1, z komentarzem „VERIFIED backend contract". |
| R14 | **`max-w-[72ch]` to wartość arbitralna** poza skalą tokenów. | Jedyna taka w module, świadoma (miara wiersza). Jeśli reviewer się nie zgodzi — `--spacing-next-*` nie ma odpowiednika, więc alternatywą jest token typografii; do decyzji w przeglądzie. |

---

## 22. Handoff do frontend-agent

### UX Goal

Dać zespołowi **mini-Wikipedię per workspace**, w której człowiek pisze krótkie hasła,
a AI dostaje z nich uporządkowany, opisany kontekst. Trzy rzeczy muszą być zawsze
oczywiste: **gdzie jestem** (baza → wpis), **czy wiedza jest gotowa dla AI**
(status wpisu + stan indeksu, nigdy ukryte) i **jak to się łączy** (wikilinki, backlinki,
podobieństwa, graf). Interfejs ma zniechęcać do pisania traktatów (cap 40 000 znaków
z copy doktrynalnym) i zachęcać do linkowania (czerwony link = zaproszenie, nie błąd).

### User Flow

1. **Wiedza** → lista baz (pusto → *Utwórz bazę* albo *Przenieś wiedzę z bota*).
2. Tworzenie bazy: nazwa → język → karta tożsamości → schemat metadanych.
3. Otwarcie bazy → **czytnik**: spis treści · artykuł · panele relacji.
4. *Nowy wpis* → edytor: tytuł, markdown, `[[` → podpowiedź wikilinku, metadane, status → **Zapisz**.
5. Klik wikilinku → nawigacja bez przeładowania. Klik czerwonego linku → edytor z prefillem tytułu.
6. Panel **Podobne** (leniwy) → odrzucenie z toastem *Cofnij*.
7. **Tabela** → filtrowanie/masowy przegląd; **Graf** → zależności (lista sąsiadów = klawiatura); **Szukaj** → wynik → *Skocz do fragmentu*.
8. **Ustawienia** → zmiana schematu → banner „Wpisy z brakami metadanych: N" → tabela z filtrem.
9. Usunięcie → **Kosz** → *Przywróć* (1 klik) albo *Usuń trwale* (`ConfirmDialog`, mocne copy).

### Screen Structure

Shell `KnowledgeModuleLayout` (`ModuleAside` + `ModuleTabs` + `RouterView` + nakładki `?history`, `?migrate`)
→ 3 strony modułowe (**Bazy**, **Szukaj**, **Kosz**)
→ shell bazy `KnowledgeBaseView` z 5 sekcjami-dziećmi (**Czytnik**, **Tabela**, **Graf**, **Ustawienia**, **Edytor**).
Trasy: sekcja 2.2. Sekcje = child routes + `sectionRedirect`. Deep-link wpisu **po slugu**.
`<h1>` = nazwa sekcji (`PageHeader`), **poza czytnikiem**, gdzie `<h1>` = tytuł wpisu i zamiast `PageHeader` jest slim bar.

### Components Needed

- **REUSE (bez zmian):** sekcja 17.1 — pełna lista ze ścieżkami.
- **EXTEND (addytywnie):** `ui/editor/MarkdownViewer.vue` (`wikilinks`, D5), `ui/editor/MarkdownEditor.vue` (`wikilinks`), `ui/primitives/icons.ts` (`book-open`, `network`), `resources/css/next.css` (style wikilinków). Opcjonalnie `ui/forms/EntryListInput.vue` (D16).
- **CREATE:** sekcja 17.3 — 30 plików w `pages/knowledge/**`, 2 w `ui/editor/extensions/`, 1 store.
- **Zakaz:** `ui/data/Tree.vue` (D3), `InputRenderer` z Forms (D7), własny sanitizer, biblioteka grafowa (D8), własny przełącznik autora zamiast `CreatorBadge`.

### States

Pełna macierz w sekcji 11. Skrót:
**Ładowanie** = skeletony imitujące realny element, kilka sztuk (artykuł = tytuł + akapity;
graf = kropki-węzły; lista = wiersze/karty), **nigdy** spinner + „Ładowanie".
**Pusto** = 10 wariantów z tabeli 11.2, każdy z akcją wyjścia.
**Błąd** = `EmptyState variant="error"` (strona) / `Alert variant="danger"` (panel) / toast (akcja) — zawsze z ponowieniem.
**Stan indeksu** = 6 jawnych stanów z obowiązkowym copy (11.4); `pending_budget` linkuje do zużycia AI; `partial` pokazuje `N/M`.
**Konflikt 409** = `Modal` z trzema wyjściami (6.5) — Escape zawsze wybiera najbezpieczniejsze.

### Responsive Rules

Tabela w sekcji 13. Kluczowe: poniżej `next-lg` `ModuleAside` znika na rzecz `ModuleTabs`
(za darmo); spis treści → `Drawer`; panele relacji → `Accordion` z **identyczną** treścią;
tabela → `responsive="stack"` (prawdziwa transformacja w karty, akcje zawsze widoczne);
**graf na wąskim ekranie domyślnie jako lista**, płótno na żądanie.

### Accessibility

Checklista w sekcji 15. Trzy rzeczy nie do negocjacji:
1. **Popup `[[` musi rejestrować się w `useOverlayStack`** przez `createSuggestionOverlay` — inaczej Escape kasuje pracę użytkownika (R1).
2. **Graf nigdy nie jest jedynym nośnikiem** — SVG `aria-hidden`, prawdziwa powierzchnia to lista sąsiadów z `aria-activedescendant` wg wzorca `VariableBrowser` (8.7).
3. **Żaden stan nie jest wyrażony samym kolorem** — badge = ikona + tekst, typ krawędzi = wzór linii + legenda, czerwony link = przerywane podkreślenie + ikona + `aria-label`.

### Copy / Microcopy

Pełne katalogi PL + EN w sekcji 18 (17 grup, ~260 kluczy). Zasady:
- **Brak pluralizacji** → forma `Etykieta: {count}`.
- Copy doktrynalne przy limicie 40 000 znaków (18.9 `overLimit.*`) — tłumaczy zasadę, nie tylko blokuje.
- Copy stanów indeksu jest **obowiązkowe**, nie ozdobne (18.8) — `pending_budget` mówi *dlaczego* i *co dalej*.
- Copy destrukcyjne nazywa obiekt i skutek („«{name}» zostanie usunięty bezpowrotnie, razem z historią wersji i indeksem").
- Copy przywracania wersji **uspokaja** („Nic nie zostanie utracone").
- `aria-label` przycisków ikonowych zawsze z nazwą obiektu.

### Tailwind / Design Tokens

- Wyłącznie tokeny `next-*` (`resources/css/next.css`); zero surowych `hex`/`hsl`; zero ręcznych `dark:` kolorów — tryb ciemny to nadpisanie tokenów.
- Siatka kart baz: `grid-cols-1 next-sm:grid-cols-2 next-xl:grid-cols-3` (identyczna z Workflows/Bots).
- Odstępy stron: `flex flex-col gap-next-6`; panele: `Surface bg="card" border elevation="sm" radius="lg"` + `p-next-6`.
- Kontrolki w `FilterBar`: `<div class="min-w-0 flex-1 basis-40">`.
- Graf: `var(--color-next-border)` / `--color-next-primary` / `--color-next-primary-subtle` / `--color-next-danger` / `--color-next-fg`; etykiety z podkładem `--color-next-bg`.
- Podświetlenie snippetu: `bg-next-primary-subtle text-next-primary-subtle-foreground rounded-next-xs px-next-0_5`.
- Warstwy: popover podglądu na `--z-next-tooltip`, popup `[[` na `--z-next-dropdown`/`popover`, slim bar na `--z-next-sticky`.
- Jedyna arbitralna wartość: `max-w-[72ch]` na kolumnie artykułu (R14).

### Frontend Handoff

**Kolejność implementacji (proponowana dla B4/B5/B5b):**
1. **B4a — fundament:** store `knowledge.ts` (wzorzec `workflows.ts`), `types.ts` z **potwierdzonego** kontraktu (sekcja 19), trasy, `KnowledgeModuleLayout`, `statusMaps.ts`, blok i18n w obu katalogach, 2 nowe ikony.
2. **B4b — lista + ustawienia:** `KnowledgeBasesView`, `KnowledgeBaseCard`, `KnowledgeBaseForm`, `KnowledgeSchemaBuilder`, `KnowledgeBaseSettingsView`, modal migracji.
3. **B5a — czytnik:** `MarkdownViewer` `wikilinks` (+ test byte-identyczności), `KnowledgeArticleBody`, `KnowledgeTocPanel`, `KnowledgeEntryPreview`, panele relacji, `KnowledgeVersionsDrawer`.
4. **B5b — edytor:** `wikilink.ts` + `WikilinkSuggest.vue` (**najpierw** overlay stack + jego test), `KnowledgeEntryEditorView`, `KnowledgeMetadataForm`, `KnowledgeStaleWriteModal`.
5. **B5c — tabela + szukaj:** `KnowledgeEntriesTableView`, `KnowledgeSearchView`, `highlightSegments.ts`, `KnowledgeTrashView`.
6. **B5d — graf:** `knowledgeGraphLayout.ts` **z testami przed komponentem**, `KnowledgeGraphNeighbourList` (dostępność **przed** płótnem), `KnowledgeGraphCanvas`, legenda, panel boczny.

**Testy, które muszą powstać razem z kodem:**
`MarkdownViewer` byte-identyczny bez `wikilinks` · sanityzacja wikilinków · Escape w popupie `[[` nie zamyka nadrzędnej nakładki · `knowledgeGraphLayout` deterministyczny (dwa wywołania = identyczne wyjście, permutacja wejścia = identyczne wyjście) · `highlightSegments` na offsetach · klawiatura listy sąsiadów · parity katalogów i18n.

**Walidacja:** `npm run test:unit`, `npm run build`, `npx vue-tsc --noEmit` (parity i18n),
oraz przegląd wizualny w `/next/_styleguide` dla dwóch nowych ikon (light + dark).

### Consistency Risks

Pełna tabela w sekcji 21 (R1–R14). Trzy do decyzji **przed** kodem:
- **R7 — `FilterTabBar` w Koszu** (wnioskowany wyjątek D14): decyzja właściciela; domyślka = reguła wygrywa.
- **R8 — awans `EntryListInput`** do `ui/forms/` (D16) vs wiersze inline: decyzja właściciela; obie ścieżki są zgodne z konwencją.
- **R13 — kontrakt backendu** (sekcja 19): 14 punktów do potwierdzenia. `types.ts` **nie powstaje** z domysłów.

---

## 23. Erraty po implementacji

> Dopisane w B8 (dokumentacja), po weryfikacji zbudowanego kodu. Ten dokument jest specyfikacją
> **przed** implementacją — poniższe punkty to miejsca, w których zbudowany kod (batche B4/B5/B5b/B6c/B7)
> świadomie odszedł od pierwotnego zapisu, oraz drobne uściślenia kontraktu, którego specyfikacja nie
> mogła znać z góry. Reszta dokumentu (sekcje 1–22) **pozostaje aktualnym zapisem decyzji projektowych**
> — nie jest przepisywana wstecznie.

### Rozstrzygnięcie pytań z sekcji 19 (kontrakt potwierdzony w kodzie)

Sekcja 19 była listą otwartych pytań do backendu **przed** kodem. Poniżej — zweryfikowane w
zbudowanym kodzie odpowiedzi na te pytania, gdzie założenie specyfikacji różniło się od tego, co
faktycznie zaszyto w kontrakcie. Pełny, aktualny kształt każdego z tych pól:
`docs/backend/knowledge-api.md`.

1. **Pytanie 1 (kształt `matched_chunk`) — `heading_path` to STRING, nie `string[]`, i nie ma pola
   `anchor`.** Założenie sekcji 19: `{ snippet, highlights, heading_path: string[], anchor: string }`.
   Rzeczywisty kontrakt (`KnowledgeSearchResultResource`/`ChunkMatch`): `heading_path` to POJEDYNCZY
   string, ścieżka nagłówków już sklejona separatorem `" > "` przez `KnowledgeChunker`
   (np. `"Cennik > Rabaty"`) — nie tablica segmentów do renderowania osobno. Pola `anchor` **nie ma
   w ogóle** w `matched_chunk` — wynik szukania niesie tylko `ordinal`/`heading_path`/`score` jako
   metadane cytatu, offsety (`char_start`/`char_length`/`highlights`) do podświetlenia i tyle.
2. **Pytanie 2 (kotwica fragmentu) — serwer NIE generuje żadnej kotwicy; jest wyłącznie kliencka i
   dotyczy INNEGO miejsca w UI niż wyniki szukania.** `#h-<anchor>` z sekcji 4.4 to deep-link do
   nagłówka **w czytniku** — budowany w 100% po stronie klienta (`assignHeadingAnchors` w
   `reader/wikilinkAnchors.ts`, wywoływane po każdym renderze `MarkdownViewer`), nie ma odpowiednika
   ani wejścia z backendu. Wynik szukania (pytanie 1) nie ma żadnej kotwicy w ogóle — cytuje się
   offsetami, nie linkiem do miejsca w dokumencie.
3. **Pytanie 5 (cap i sortowanie grafu) — NIE MA parametru `?limit` na grafie.** Cap jest **wyłącznie
   serwerowy** i niekonfigurowalny z zapytania: `knowledge.graph.max_nodes` (domyślnie 60,
   `KnowledgeGraphService`). Klient nie może go podnieść ani obniżyć per-request. Rachunek „co odcięło
   ograniczenie" wraca w `truncated: { hidden_nodes, hidden_edges }` w samej odpowiedzi — dokładnie to,
   co sekcja 21/R10 obiecywała jako „Pokazano {shown} z {total}", tylko że źródłem liczby jest pole
   odpowiedzi, nie osobny parametr `total` żądany osobnym `?limit=`.
4. **Pytanie 6 (podgląd wpisu na hover) — endpoint `GET /knowledge/bases/{base}/entries/{slug}/preview`
   NIE ISTNIEJE i nie powstał.** Zbudowany hover-popover (`KnowledgeArticleBody.vue`) **nie odpytuje
   backendu wcale** — czyta z `entriesBySlug`, mapy zbudowanej z PEŁNEJ listy wpisów bazy, którą czytnik
   i tak już ma załadowaną (żeby poprawnie rozstrzygać wikilinki na duchy/realne cele — patrz sekcja
   4.4). Obawa specyfikacji („bez tego popover ściągałby pełną treść przy każdym najechaniu") okazała
   się nieaktualna z innego powodu niż zakładano: rozwiązaniem nie jest osobny, tani endpoint, tylko
   fakt, że dane (z listy, bez `content`, czyli już bez 40 kB treści) są w pamięci klienta zanim
   użytkownik cokolwiek najedzie.
5. **Pytanie 7 (konflikt zapisu) — pole nazywa się `expected_revision_id`, nie `expected_version`, i nie
   niesie `updated_at`.** Token blokady optymistycznej to `current_revision_id` odczytany z zasobu wpisu,
   odsyłany jako `expected_revision_id` przy zapisie; 409 (`knowledge_stale_write`) niesie
   `{ code, message, current_revision_id }` — bez zagnieżdżonego obiektu `latest`, jakiego spec się
   spodziewała, i bez `updated_at`/`creator` w payloadzie błędu (klient, chcąc pokazać kto/kiedy nadpisał,
   musi dociągnąć wpis osobno).
6. **Pytanie 11 (migracja z bota) — kształt odpowiedzi to `{ knowledge_base_id, name, entries_count, mode }`
   w PŁASKIM body 201, nie `{ base, moved_count }`.** Zob. już udokumentowany punkt B5b.6 poniżej —
   powtórzone tu wprost jako odpowiedź na pytanie 11, bo to jest dokładnie ta niewiadoma. Część drugiej
   połowy pytania 11 (czy `bots.knowledge.entries` zostaje nietknięte) potwierdzona **tak**: migracja jest
   addytywna, kolumna `knowledge` na bocie nie jest czyszczona ani modyfikowana.
7. **Pytanie 12 (uprawnienia) — flagi to `can_be_edited`/`can_be_deleted` (konwencja `can_be_*` z reszty
   aplikacji), NIE `can_edit`/`can_delete`, i na bazie jest DODATKOWA, osobna flaga `can_be_managed`
   (governance: karta tożsamości + schemat metadanych), której pytanie 12 nie przewidywało wprost.**
   Na wpisie: `can_be_edited`, `can_be_deleted`, `can_be_purged` (permanentne usunięcie — inna flaga niż
   zwykłe `can_be_deleted`, bo to inna zdolność: `forceDelete` vs `delete`). `can_restore` nie istnieje
   jako osobna flaga na żywym zasobie (nie ma znaczenia dla wpisu, który nie jest w koszu) — kosz ma
   własny, per-wiersz kontekst przywracania obsługiwany przez UI, nie przez flagę na drucie.

### B4 (fundament + lista + ustawienia)

1. **Realne capy pól bazy: karta tożsamości ≤ 10 000 znaków, opis ≤ 2 000 znaków** (backendowe
   `StoreKnowledgeBaseRequest`) — sekcja 19 pytała o te limity jako otwarty punkt kontraktu; są to
   wartości ostateczne, nie robocze.
2. **`DescriptorSchemaBuilder.vue` (realizacja `KnowledgeSchemaBuilder` z sekcji 7.3) nie oferuje typu
   `object`**, mimo że backend (`KnowledgeMetadataValidator`) go dopuszcza. Decyzja świadoma: metadane
   wpisu mają zostać PŁASKIE, żeby dały się filtrować i zmieścić w kolumnie tabeli. Oferowane bazy:
   `text | number | boolean | date | enum`. Pole typu `object` przyszłe z API (np. zapisane inną drogą)
   renderuje się jako wiersz **read-only** i jest re-emitowane bez zmian — otwarcie edytora nigdy nie
   niszczy fragmentu schematu, którego nie rozumie.
3. **Karta bazy (`KnowledgeBaseCard.vue`) w B4 nie miała badge'a stanu indeksu ani chipa czerwonych
   linków** — `index_summary`/`ghost_links_count` jeszcze nie istniały na `KnowledgeBaseResource`.
   B4 tymczasowo kierowała kliknięcie karty do Ustawień (bo Czytnik jeszcze nie istniał). **Oba stopgapy
   cofnięte w B5**: `index_summary` + `ghost_links_count` doszły do zasobu w B2b (backend), więc karta
   renderuje je bez dodatkowego zapytania (sekcja 3.2 jest już zgodna z kodem); klik karty otwiera Czytnik,
   Ustawienia zostają w kebabie.

### B5 (czytnik + edytor + tabela + szukaj + kosz)

1. **Kosz wpisów jest per-BAZA, nie ogólnoworkspace'owy.** `GET /knowledge/bases/{base}/entries?trashed=1`
   nie ma odpowiednika bez bazy w ścieżce — nie istnieje zapytanie „wszystkie usunięte wpisy w
   workspace". `KnowledgeTrashView.vue` implementuje to uczciwie: karta wyboru bazy, dopiero potem lista;
   pusty wybór bazy to osobny, jawny stan „wybierz bazę", nie pusta lista.
2. **Ta funkcja (kosz wpisów) w ogóle nie istniała przed B6c** — dopiero B6c dodało gałąź `trashed=1` do
   indeksu wpisów; przed tym usunięty wpis był nieosiągalny żadnym zapytaniem. `partial N/M`
   (`indexed_chunks_count` w badge'u indeksu, sekcja 12.2/5.3) jest tym samym zależne od B6c — dopiero
   wtedy `indexed_chunks_count` doszło do `KnowledgeEntryResource`/`KnowledgeEntryListResource`.
3. **Panele relacji (`KnowledgeEntryRail.vue`) renderują się jako `Accordion` w OBU układach**, nie tylko
   poniżej `next-lg`. Sekcja 4.6 zakładała `Surface`-panele w szerokim railu i `Accordion` jako fallback
   pod artykułem; zbudowana wersja ma jedną ścieżkę znaczników dla obu (zmienia się tylko kolumna wokół
   `Accordion`), bo to jedyny sposób, żeby dwa układy **gwarantowanie** niosły identyczną treść — i buduje
   `role="region"` + `aria-labelledby` per panel za darmo.
4. **Panel „Podobne" NIE jest leniwy** — sekcja 4.6 zakładała `@expand`-owe pobranie. Kontrakt backendu
   mówi inaczej: krawędzie podobieństwa przyjeżdżają w `links`/`backlinks` pełnego zasobu wpisu, już
   załadowane. Osobne zapytanie przy rozwinięciu byłoby drugim wywołaniem po dane, które już się ma.
5. **Brak banera „niekompletne metadane"** (sekcja 5.2 wspominała filtr „Niekompletne metadane" karmiony
   też bannerem z Ustawień) — nie został zbudowany w B5/B5b; filtr metadanych po polach `enum` istnieje,
   ale dedykowany wskaźnik "ile wpisów ma osierocone/brakujące pole" nie. Otwarty punkt na przyszłość,
   nie regresja.
6. **Kosz (`KnowledgeTrashView.vue`) MA `FilterTabBar`** — decyzja R7 z sekcji 21/22 rozstrzygnięta na
   korzyść reguły (zapisany widok trzyma tylko `search`; baza i zakładka to nawigacja, nie stan
   zapisywalny — inaczej zapisany widok wskazujący na później-wyczyszczoną bazę pokazywałby pusty kosz
   bez wyjaśnienia).

### B5b (graf)

1. **`updatedAt` NIE jedzie po drucie grafu i NIE jest tie-breakiem w layoucie overview.**
   `KnowledgeGraphResource` jest celowo minimalny (sekcja 8/`KnowledgeGraphResource` w
   `docs/backend/knowledge-api.md`) — węzeł nie niesie znacznika czasu. `knowledgeGraphLayout.ts`
   sortuje overview po `degree ⇣ → slug → id` (slug jako tie-break, nie `updated_at`); świeżość decyduje
   już o tym, KTÓRE izolowane wpisy serwer w ogóle dokłada do odpowiedzi (wypełniacz cappingu po stronie
   `KnowledgeGraphService::overviewCandidates`), nie o tym, gdzie lądują na płótnie.
2. **`node.degree` z drutu nie jest tym, co rysuje promień kropki.** Layout liczy WŁASNY, DRAWN degree
   (liczbę krawędzi faktycznie narysowanych po filtrach chipów kraw. + kliencie capie), bo serwerowy
   `degree` liczy krawędzie w całej ODPOWIEDZI, a płótno może pokazywać mniej.
3. **Sygnatura wejścia layoutu:** `layoutKnowledgeGraph(data: KnowledgeGraphData, options?: GraphLayoutOptions): GraphLayout` —
   jedna funkcja wejściowa (branżuje po `data.center`), a nie osobne wywołanie ego/overview po stronie
   hosta; `layoutEgoGraph`/`layoutOverviewGraph` zostają eksportowane osobno dla testów.
4. **Etykiety cappingu w UI to `knowledge.graph.capped` i `knowledge.graph.cappedEgo`** — dwa różne
   klucze i18n (nie jeden uniwersalny), bo „N z M najbardziej połączonych" jest fałszywym zdaniem dla
   grafu ego, którego `hidden_nodes` liczy SĄSIEDZTWO, jakie znalazł spacer, a nie „najbardziej połączone
   wpisy bazy".
5. **422 migracji bota (sekcja 3.6/„Modal migracji") wraca jako POJEDYNCZY STRING w `errors.knowledge[0]`,
   nie jako ustrukturyzowana lista ofensorów.** Tytuły wpisów, które zablokowały migrację, są WEWNĄTRZ tej
   jednej wiadomości (złożonej po stronie backendu, `bot.knowledge.migration_blocked`); frontend pokazuje
   ją verbatim w `Alert variant="danger"` wewnątrz modala, nie renderuje własnej listy.
6. **`POST /bots/{bot}/knowledge/migrate` zwraca PŁASKIE body, bez opakowania `data`** —
   `{ knowledge_base_id, name, entries_count, mode }` wprost w korzeniu odpowiedzi (201). Odstępstwo od
   domyślnej konwencji Laravel Resource (`{"data": {...}}`), którą stosuje reszta modułu (`PUT`/`DELETE
   .../knowledge-binding` zwracają zwykły, opakowany `BotResource`).
7. **`indexed_chunks_count` bywa `null`, i to NIE znaczy zero.** `null` = „to połączenie nie potrafi tego
   policzyć" (środowisko bez pgvector); `0` na wpisie `indexed` z `chunks_count: 0` = pusta treść, co jest
   `indexed` z definicji. UI musi rozróżniać te dwa przypadki, nie traktować `null` jako `0`.
8. **Retry (`can_retry`) obejmuje TRZY stany, nie jeden: `failed`, `partial`, `pending_budget`.** Flaga na
   drucie (`index.can_retry`) czyta się wprost z `KnowledgeIndexStatus::isRetryable()` po stronie
   backendu — te same trzy stany specyfikacja opisywała pojedynczo w różnych miejscach (sekcje 4.4/12.2);
   w zbudowanym kodzie to jeden, spójny predykat po obu stronach drutu.

### B7 (wykrywanie i render treści)

1. **Linki markdown do WEWNĘTRZNYCH ścieżek renderują się jako zwykły tekst — to jest ZAMIERZONE, nie
   błąd.** `MarkdownViewer`'s `SAFE_LINK = /^(https?:\/\/|mailto:)/i` przepuszcza **wyłącznie**
   `http(s)://` i `mailto:`; każdy inny `href` (w tym względna ścieżka do wpisu, np.
   `[Zobacz też](/next/knowledge/…)` napisana ręcznie w markdownie zamiast jako wikilink) traci swój
   atrybut `href` w sanitizacji i renderuje się jako zwykły, nieklikalny tekst wewnątrz `<a>` bez celu.
   **Jedyną obsługiwaną składnią linku wewnętrznego w treści wpisu jest wikilink `[[slug]]` /
   `[[slug|etykieta]]`** (sekcja 4.4/D5) — te kotwice budowane są programowo, PO sanitizacji, z osobnej,
   jawnie zaufanej ścieżki (`safeWikilinkHref`, która dodatkowo akceptuje względne ścieżki zaczynające
   się od pojedynczego `/`), nigdy przez wstrzyknięcie markupu do surowego markdownu. Autorzy wpisów
   muszą to wiedzieć: pisanie zwykłego linku markdown do innego wpisu **nie zadziała**.

### B10 (krawędzie `mention`) — ZALEGŁOŚĆ, do domknięcia razem z B13

Backend ma **cztery** rodzaje krawędzi, frontend zna trzy. `docs/backend/knowledge-api.md` opisuje
`mention` jako pełnoprawny, **domyślnie włączony** rodzaj (`sources[]` domyślnie =
`wikilink` + `similarity` + `mention`), a mimo to:

1. **Legenda i chipy filtrów nie mają `mention`.** `knowledge.graph.edge.*` w obu katalogach i18n ma
   tylko `wikilink` / `similarity` / `manual` / `ghost` (`pl.ts` ~3646, `en.ts` odpowiednio).
   Skutek: krawędzie, które backend rysuje **domyślnie**, nie mają ani nazwy, ani pozycji w legendzie,
   ani chipa do wyłączenia — użytkownik widzi linie, których nie da się zdekodować. To jest naruszenie
   reguły „wzór linii wymaga legendy" z sekcji 8.8.
2. **`can_be_dismissed` nie jest czytane nigdzie w `resources/js/next`.** Kontrakt mówi wprost
   (knowledge-api.md): *„Only the machine-proposed kinds (`similarity`, `mention`) may be DISMISSED —
   the resource says so per row with `can_be_dismissed`, so a client never has to re-derive the rule
   from `source`."* Frontend nadal wnioskuje odrzucalność z rodzaju krawędzi zamiast czytać flagę.
3. **Sprzeczność w samym `knowledge-api.md` — do rozstrzygnięcia z backendem.** Tabela rodzajów mówi,
   że `mention` jest odrzucalny, ale wiersz endpointu `POST /api/knowledge/links/{link}/dismiss` nadal
   pisze: *„**422** (`knowledge.links.not_dismissable`) for anything but a `similarity` edge"*.
   Jedno z tych zdań jest nieaktualne. **Frontend ma czytać `can_be_dismissed` i nie zgadywać** —
   ale kontrakt trzeba domknąć (pytanie K5 w sekcji 25.6).

**Do zrobienia (B13 lub osobny mikro-batch):**

| Element | Zmiana |
| --- | --- |
| i18n (oba katalogi) | `knowledge.graph.edge.mention` = „Wzmianki" / „Mentions" |
| `graph/KnowledgeGraphLegend.vue` | czwarty wiersz legendy |
| chipy filtrów (`KnowledgeGraphView.vue`) | czwarty chip, domyślnie **włączony** (zgodnie z domyślką serwera) |
| `graph/KnowledgeGraphCanvas.vue` | styl krawędzi `mention` — patrz niżej |
| `reader/KnowledgeSimilarRow.vue` + panele | odrzucalność z `can_be_dismissed`, nie z `source === 'similarity'` |
| „Dlaczego podobne?" | `mention` ma INNY kształt dowodu: `{ char_start, char_length }` (offsety znakowe w treści **wpisu źródłowego**), `score: null` — nie renderować pustego procentu |

**Styl krawędzi `mention` (kolor nigdy jedynym sygnałem).** Cztery rodzaje muszą być rozróżnialne
wzorem linii, a trzy wzory są już zajęte (ciągła = `wikilink`, kropkowana = `similarity`,
przerywana = `ghost`, ciągła grubsza + kwadrat = `manual`). Dla `mention`:
**kreska-kropka** (`stroke-dasharray="6 3 1 3"`), `stroke-width: 1.25`, bez grotu.
Wzór jest jednoznacznie różny od pozostałych czterech także w druku czarno-białym.
W liście sąsiadów (`KnowledgeGraphNeighbourList.vue`) `mention` dostaje własną grupę
`role="group"` z `aria-label` „Wzmianki" — tam rodzaj jest **słowem**, więc dostępność nie zależy
od wzoru linii w ogóle.

---

## 24. Ekran 10 — Kreator AI (`compose`)

> **Pivot zaakceptowany przez właściciela (B13).** Wpisy **nie powstają już ręcznie**.
> „Nowy wpis" = jedno pole tekstowe → agent → 1..N szkiców do przeglądu → akceptacja.
> Edytor (sekcja 6) zostaje, ale **wyłącznie do edycji istniejących** wpisów.
>
> **Cały kontrakt drutu tego ekranu jest DO WERYFIKACJI** — B11a (backend) powstaje równolegle.
> Każdy klucz payloadu poniżej jest oznaczony jako założenie. Frontend **nie koduje żadnego z nich**
> zanim B11a nie potwierdzi (sekcja 25.6). To jest ta sama pułapka, która kosztowała Etap 5.

**Pliki (nowe):**

```
pages/knowledge/
├── KnowledgeComposeView.vue              orkiestracja: źródło → generacja → tablica
├── compose/
│   ├── KnowledgeComposeSourceForm.vue    pole źródłowe + chip kosztu + start
│   ├── KnowledgeDraftBoard.vue           tablica szkiców + akceptacja zbiorcza
│   ├── KnowledgeDraftCard.vue            jedna karta szkica
│   ├── KnowledgeDraftDiffPanel.vue       przełącznik baseline'ów + TextDiffView
│   ├── KnowledgeDraftRelationsPanel.vue  reuse grafu (kanwa + lista)
│   ├── KnowledgeRefineBar.vue            pole poprawki + historia promptów
│   ├── KnowledgeComposeUnavailable.vue   stan „budżet wyczerpany" / kill-switch
│   └── useComposeSettle.ts               realtime settle (klon useSessionSettle)
```

**Trasa** (dokładka do bloku z sekcji 2.2, jako dziecko `':baseId'`):

```
├─ 'compose/:session?'   name: next.knowledge.base.compose   → KnowledgeComposeView.vue
```

`:session` jest opcjonalne: bez niego kreator startuje pusty; z nim wraca do istniejącej sesji
(deep-link, odświeżenie strony, powrót z czytnika). Sekcja dołącza do `resourceItems`
w `KnowledgeModuleLayout.vue` jako **pierwsza** pozycja, ikona `sparkles`, etykieta
`knowledge.module.nav.compose`.

**Query:**

| Klucz | Znaczenie |
| --- | --- |
| `?seed=<slug>` | ziarno z czerwonego linku / „wpis nie istnieje" — prefill pola źródłowego |
| `?amend=<entryId>` | ziarno „popraw ten wpis" — sesja startuje jako nowelizacja konkretnego wpisu |

### 24.1 Decyzje projektowe kreatora

**DC1 — Kreator settluje na **zdarzeniu realtime**, nie na pollingu.**
To nie jest wybór stylu — w projekcie obowiązuje **jawne wymaganie właściciela** zapisane w kodzie
(`pages/generator/session/useSessionSettle.ts`, komentarz modułu): *„with NO polling fallback
anywhere — the user requirement is that generation and refine/regenerate must settle on the
websocket event, not a poll loop"*. Brief B13 mówił „polling"; **stosujemy konwencję projektu**,
bo jest silniejsza niż domyślne brzmienie zlecenia.
`useComposeSettle.ts` jest klonem `useSessionSettle.ts` 1:1:
- kanał prywatny `knowledge.workspace.{workspaceId}` (autoryzacja członkostwem),
- zdarzenie `.knowledge-compose.updated`, payload wyłącznie statusem
  `{ id, status: 'ready'|'failed' }` — **bez treści**,
- na dopasowanie `payload.id` → **jedno** `fetchSession(id)`,
- okno bezpieczeństwa `DEFAULT_TIMEOUT_MS` (ustawić powyżej timeoutu joba generacji) → jedno
  ponowne pobranie i rezygnacja, **nigdy pętla**,
- brak Reverba → **jedno** opóźnione pobranie (~4 s), potem podpowiedź „odśwież".
**Wymóg do backendu (K1):** bez broadcastu ten ekran nie ma jak działać zgodnie z konwencją.

**DC2 — Kreator jest SEKCJĄ bazy, nie osobnym modułem.**
Szkice zawsze należą do jednej bazy (schemat metadanych i karta tożsamości są wejściem agenta).
Sekcja w `ModuleAside` daje nawigację, breadcrumb i powrót za darmo.

**DC3 — Tablica szkiców to lista KART, nie tabela.**
Szkic jest dokumentem do przeczytania (tytuł + treść + metadane + relacje + diff), a nie wierszem
danych. `Table` z rozwijanymi wierszami byłaby tabelą, w której każdy wiersz i tak się rozwija —
czyli listą kart w przebraniu.

**DC4 — Nowelizacja (shadow) NIE jest osobnym typem karty.**
Ta sama `KnowledgeDraftCard`, dodatkowo: `Badge` „Nowelizacja → {tytuł targetu}", **tytuł i slug
TARGETU w nagłówku karty** (nie wymyślony nowy) i trzeci baseline w diffie (domyślny).
Powód: użytkownik ocenia *„co będzie istniało"*, a nie *„jaka to operacja CRUD"*. Dwa układy kart
zmusiłyby go do przełączania modelu mentalnego w środku jednej listy.

**DC5 — Panel relacji to REUSE grafu, nie druga wizualizacja.**
Ten sam `graph/knowledgeGraphLayout.ts` (`layoutKnowledgeGraph`), ta sama
`graph/KnowledgeGraphCanvas.vue`, ta sama `graph/KnowledgeGraphNeighbourList.vue`.
Warunek: endpoint relacji kreatora zwraca **dokładnie kształt `KnowledgeGraphResource`**
(`{ center, nodes[], edges[], ghosts[], truncated }`, `edges[].source` jako rodzaj) — jeśli
backend zwróci cokolwiek innego, powstanie druga wizualizacja i natychmiastowy dryf (K2).
**Szkic jest węzłem** z flagą `is_draft`; **nowelizacja NIE dodaje węzła** — to znacznik
`amends: true` na węźle **targetu** (zgodnie z DC4: nowelizacja to przyszły stan istniejącego
wpisu, nie nowy byt).

**DC6 — Progi podobieństwa muszą być świadome długości.**
Domyślny `min_score` grafu to `knowledge.similarity.threshold` = **0.86**. Krótki szkic
(kilkaset znaków) prawie nigdy nie przebije tego progu wobec pełnych wpisów — a to właśnie krótki
szkic **najbardziej** potrzebuje pokazania sąsiadów (bo najłatwiej duplikuje istniejące hasło).
UI **nie majstruje przy progu sam** (to byłoby wymyślanie semantyki): backend ma zwrócić próg
dopasowany do długości źródła, a odpowiedź ma nieść użyty `min_score`, żeby UI mógł uczciwie
napisać „Pokazujemy powiązania powyżej {score}" (K3).

**DC7 — Strażnik duplikatów to BADGE na karcie, nie blokada.**
Podobieństwo jest heurystyką; modal blokujący akceptację na podstawie heurystyki jest nadużyciem
(anatomia modala: modal = nieodwracalna decyzja). Karta dostaje
`Badge variant="warning" icon="alert-triangle"` „Pokrywa się z «{tytuł}» w {percent}%" + akcję
**„Otwórz istniejący"** (nowa karta). Akceptacja pozostaje możliwa; powyżej progu wysokiego
(≥ 90 %) akceptacja przechodzi przez `useConfirm` z nazwaniem obu tytułów.

**DC8 — Akceptacja jest per-szkic; zbiorcza jest jawnie zbiorcza.**
Brak auto-akceptacji, brak „zaakceptuj i zamknij". Akcja zbiorcza działa **tylko na szkicach
zaznaczonych checkboxem**, pokazuje licznik w etykiecie i przechodzi przez `useConfirm`
wymieniający liczbę i status docelowy.

**DC9 — Dostępność budżetu sprawdzana PRZED renderem formularza** (rozwinięcie D15).
Nie po kliknięciu. Kreator pyta o dostępność przy wejściu i — gdy nie ma budżetu — renderuje
`KnowledgeComposeUnavailable.vue` **zamiast** pola źródłowego. Przycisk „Nowy wpis" w czytniku
i tabeli **pozostaje aktywny** (D15: wyjaśnienie zamiast wyszarzenia); wyjaśnienie należy do celu.

**DC10 — `TextDiffView` dostaje poprawkę dostępności (addytywną).**
`ui/data/TextDiffView.vue` sygnalizuje `+`/`−` w rynience oznaczonej `aria-hidden="true"`
(linia 94). Dla użytkownika widzącego sygnał jest bezkolorowy i poprawny; dla czytnika ekranu
**diff brzmi jak zwykły tekst** — wszystkie wiersze identycznie, bez informacji co dodano, a co
usunięto. To jest realna luka, nie kosmetyka, i kreator jest miejscem, gdzie zaczyna boleć
(użytkownik akceptuje treść na podstawie diffa).
**Poprawka:** każdy wiersz `add`/`del` dostaje wizualnie ukryty prefiks tekstowy
(`<span class="sr-only">`) z `textDiff.rowAdded` / `textDiff.rowRemoved`; rynienka zostaje
`aria-hidden`. Zero zmian wizualnych, zero zmian w API komponentu, dwa nowe klucze i18n.

### 24.2 Faza 1 — pole źródłowe (`KnowledgeComposeSourceForm.vue`)

`PageHeader :title="t('knowledge.compose.title')" icon="sparkles"`,
`description` = `knowledge.compose.subtitle`.

Ciało — jeden `Surface bg="card" border elevation="sm" radius="lg" p-next-6`:

```vue
<FormField :label="t('knowledge.compose.sourceLabel')"
           :description="t('knowledge.compose.sourceHint')" required>
  <Textarea v-model="source" auto-grow :rows="12" :counter="true" :maxlength="20000"
            :placeholder="t('knowledge.compose.sourcePlaceholder')" />
</FormField>
```

- **Cap 20 000 znaków**, licznik widoczny od startu. Przekroczenie: licznik czerwienieje,
  `Zacznij` dostaje `aria-disabled` + `Tooltip` z powodem (**nigdy** wyszarzenie bez wyjaśnienia).
- `placeholder` uczy, czego agent potrzebuje:
  *„Napisz wszystko, co wiesz i co chcesz, żeby wiedziały boty. Nie musisz tego porządkować —
  od tego jest kreator."*
- **Ziarno z czerwonego linku** (`?seed=<slug>`): pole prefillowane
  `knowledge.compose.seedPrefill` = „Napisz, czym jest «{title}»." + kursor na końcu,
  `Textarea` autofocus. Nad polem `Alert variant="info" size="sm"` z
  `knowledge.compose.seedNotice` („Ten wpis jest linkowany z innych haseł, ale jeszcze nie
  istnieje.") — użytkownik ma wiedzieć, skąd się tu wziął.
- **Ziarno nowelizacji** (`?amend=<entryId>`): `Alert variant="info"` „Poprawiasz istniejący wpis:
  «{title}»", pole źródłowe puste z placeholderem `knowledge.compose.amendPlaceholder`
  („Co ma się zmienić w tym wpisie?").

**Pasek startu** (stopka karty):

```
[Chip szacunku kosztu]            [Button ghost Anuluj]  [Button leading-icon="sparkles" Zacznij]
```

- **Chip kosztu** — `Badge variant="neutral" tone="subtle" icon="wallet"` z tekstem
  `knowledge.compose.estimate` = „Szacowany koszt: {amount}".
  > **Uwaga — to NIE jest `SessionBudgetChip`.** Zweryfikowane w kodzie:
  > `pages/generator/session/SessionBudgetChip.vue` przyjmuje `{ summary: AiUsageSummary | null }`
  > i renderuje **wyłącznie ostrzeżenie o stanie budżetu** (`warn` / `blocked`), a w stanie `ok`
  > i przy braku capa **nie renderuje się wcale**. Szacunek kosztu POJEDYNCZEJ operacji to inna
  > rzecz i potrzebuje własnego, nowego chipa.
  > **Oba są potrzebne obok siebie:** `[chip szacunku]` + `[SessionBudgetChip stanu budżetu]`.
  Kwota formatowana **istniejącym** `formatMoney` z `pages/workspaces/aiUsageMeta.ts` (2 miejsca
  po przecinku, do 4 poniżej 1 jednostki — żeby realny mikro-koszt nie zwinął się do „0,00").
  Szacunek pochodzi z serwera (K4); gdy serwer go nie zwraca — chip **nie renderuje się wcale**
  (lepiej brak liczby niż liczba zmyślona).
- `Zacznij` z `:loading` (spinner podmienia ikonę wiodącą, etykieta zostaje, szerokość stała,
  `aria-busy`).

### 24.3 Faza 2 — generowanie

Po `POST` sesja jest `generating`. Formularz źródłowy **zwija się** do jednowierszowego
podsumowania (`Text variant="caption"` + `Button variant="link"` „Pokaż tekst źródłowy"), a niżej:

- `Alert variant="info"` z `knowledge.compose.generating` („Kreator czyta Twój tekst i układa
  hasła. To zwykle kilkadziesiąt sekund.").
- **Skeletony kart szkiców** — 3 × kształt `KnowledgeDraftCard`:
  linia tytułu 45 %, dwa chipy (`rect` 5rem × 1.25rem), 4 linie treści (95 / 100 / 88 / 60 %),
  pasek metadanych 40 %. Wrapper z `label` → jeden region `role="status"`.
  **Nigdy** spinner + „Ładowanie".
- Wejście na trasę z sesją już `generating` (odświeżenie / powrót) subskrybuje kanał od razu
  i pokazuje ten sam stan — bez „zaczynania od nowa".

**Timeout bezpieczeństwa / brak Reverba** → `Alert variant="warning"` z
`knowledge.compose.stalled` („Nie dostaliśmy potwierdzenia zakończenia.") + `Button variant="outline"`
**„Odśwież"** (jedno pobranie). Nigdy pętla.

### 24.4 Faza 3 — tablica szkiców (`KnowledgeDraftBoard.vue`)

```
[ nagłówek tablicy: „Szkice: {count}"   [Checkbox zaznacz wszystkie]  [Button Akceptuj zaznaczone (N)] ]
[ KnowledgeDraftCard ] × N
[ KnowledgeRefineBar — pole poprawki do CAŁEJ tablicy + historia promptów ]
```

#### Karta szkica — `KnowledgeDraftCard.vue`

`Surface bg="card" border elevation="sm" radius="lg"`, sekcje:

| Region | Zawartość |
| --- | --- |
| Zaznaczenie | `Checkbox` (dla akcji zbiorczej), `aria-label` = „Zaznacz szkic: {tytuł}" |
| Nagłówek | `<h3>` tytuł szkica. **Dla nowelizacji: tytuł + slug TARGETU** (`font-next-mono text-next-2xs`), nie wymyślony nowy |
| Znaczniki | `Badge` „Nowy wpis" / `Badge variant="modified" icon="pencil"` „Nowelizacja → {tytuł targetu}" · `Badge` duplikatu (DC7) · `Badge variant="warning"` „Baseline nieaktualny" (24.6) · `Badge` statusu docelowego |
| Treść | `MarkdownViewer` z `:wikilinks` (linki między szkicami działają!), domyślnie **zwinięta** do ~12 wierszy z gradientem i `Button variant="link"` „Rozwiń" / „Zwiń" (`aria-expanded`) |
| Metadane | `DescriptionList layout="grid" :columns="2" size="sm"` — te same reguły renderowania wartości co sekcja 4.7 (enum → `Badge`, boolean → ikona+tekst, data → `<time>`) |
| Błędy walidacji | 24.7 |
| Stopka akcji | `[Akceptuj]` `[Zapisz jako roboczy]` `[Diff]` `[Popraw ten wpis]` `[Odrzuć]` |

**Wikilinki między szkicami.** Agent generuje `[[slug]]` wskazujące na inne szkice tej samej sesji.
`resolve` przekazany do `MarkdownViewer` musi rozstrzygać **najpierw po slugach szkiców w sesji**,
potem po istniejących wpisach bazy, a dopiero na końcu zwracać `null` (duch). Klik linku do innego
szkica **przewija do jego karty** i podświetla ją (`is-jump-target`, 2 s) — nie nawiguje nigdzie.
To jest jedyny sensowny cel: wpis jeszcze nie istnieje, więc nie ma dokąd pójść.

**Akcje (kolejność i waga):**

| Akcja | Komponent | Skutek |
| --- | --- | --- |
| **Akceptuj** | `Button` primary, `:loading` | tworzy wpis ze statusem `approved` (nowelizacja: aktualizuje target) |
| **Zapisz jako roboczy** | `Button variant="outline"` | to samo, status `draft` — **drugorzędne wg wizji właściciela** |
| **Diff** | `Button variant="ghost" size="icon-sm" icon="file-text"` | rozwija `KnowledgeDraftDiffPanel` (24.5) |
| **Popraw ten wpis** | `Button variant="ghost" size="icon-sm" icon="sparkles"` | prefilluje `KnowledgeRefineBar` instrukcją zawężającą: `knowledge.compose.refineScoped` = „Popraw tylko wpis «{tytuł}»: " i ustawia fokus na końcu pola |
| **Odrzuć** | `Button variant="ghost" size="icon-xs" icon="x"` | usuwa szkic z tablicy; **toast z „Cofnij"**, nie `ConfirmDialog` (odwracalne, niska stawka — zgodnie z D13) |

Karta zaakceptowana **zostaje na tablicy** w stanie „zaakceptowany": wyciszona
(`opacity-60`), akcje zastąpione `Badge variant="success" icon="check-circle"` „Zaakceptowany"
+ `Button variant="link"` „Otwórz wpis". Znikanie karty po akceptacji zabrałoby użytkownikowi
poczucie postępu i uniemożliwiło sprawdzenie, co już przeszło.

#### Akceptacja zbiorcza

`Button` w nagłówku, etykieta `knowledge.compose.acceptSelected` = „Akceptuj zaznaczone: {count}"
(count-neutral — brak pluralizacji w i18n). `disabled` gdy `count === 0` **z `Tooltip`** powodem.
Przed wykonaniem `useConfirm`: „Zaakceptujesz szkice: {count}. Powstaną wpisy o statusie
Zatwierdzony." Wykonanie sekwencyjne z paskiem postępu (`ui/feedback/Progress.vue`, determinate);
błąd na którymkolwiek szkicu **zatrzymuje resztę** i zostawia szczegóły na karcie (24.7).

#### Pasek poprawki — `KnowledgeRefineBar.vue`

Pod tablicą, `sticky bottom-0`:

```vue
<FormField :label="t('knowledge.compose.refineLabel')">
  <Textarea v-model="prompt" auto-grow :rows="3" :maxlength="2000" :counter="true"
            :placeholder="t('knowledge.compose.refinePlaceholder')" />
</FormField>
<!-- stopka: chip kosztu + Button „Popraw" -->
```

- **Historia promptów** nad polem: `ui/patterns/Timeline.vue` `variant="compact"`, jedna pozycja na
  iterację (`title` = „Poprawka {n}", `time`, treść promptu z `clampLines=2`), domyślnie zwinięta
  w `AccordionItem` `knowledge.compose.historyTitle` = „Historia poprawek: {count}".
- Każda poprawka to **kolejna iteracja całej tablicy** — szkice są zastępowane, a poprzednia wersja
  staje się dostępna w diffie jako „Poprzedni szkic".
- Chip kosztu jak w 24.2. `Popraw` z `:loading`; podczas iteracji tablica pokazuje skeletony
  (24.3), a pole poprawki jest `readonly` (nie `disabled` — tekst zostaje czytelny).

### 24.5 Widok diffa — `KnowledgeDraftDiffPanel.vue`

Rozwijany w karcie (nie modal — diff to kontekst do czytania obok treści, nie przerwanie).

**Przełącznik baseline'ów** — `ui/forms/SegmentedControl.vue`:

| Szkic | Opcje | Domyślna |
| --- | --- | --- |
| **Nowy wpis** (create) | `Oryginał` · `Poprzedni szkic` | **Oryginał** |
| **Nowelizacja** (shadow) | `Wersja w bazie` · `Oryginał` · `Poprzedni szkic` | **Wersja w bazie** |

- **„Oryginał"** = pierwsza wygenerowana wersja tego szkica w tej sesji **albo** — jeśli szkic już
  raz zaakceptowano i wrócił do poprawki — ostatnia zatwierdzona treść.
- **„Poprzedni szkic"** = iteracja N−1. Niedostępna przy pierwszej iteracji → opcja
  `disabled` + `Tooltip` „Jeszcze nie było poprawek".
- **„Wersja w bazie"** = aktualna treść wpisu-targetu.

Render:

```vue
<TextDiffView :old="baselineText" :current="draft.content"
              max-height="24rem"
              :empty-label="t('knowledge.compose.diffUnchanged')" />
```

`ui/data/TextDiffView.vue` jest **już** komponentem design systemu (przeniesiony w B14) i jego
nagłówek modułu wprost wymienia ten przypadek użycia. Nie kopiować, nie forkować.
Sygnał `+`/`−` jest bezkolorowy z definicji (rynienka), a a11y domyka DC10.

**Stan „bez zmian"** obsługuje sam komponent (`old === current` → `emptyLabel`).
Copy per baseline: `knowledge.compose.diffUnchanged` = „Ta wersja niczym się nie różni od wybranej
podstawy." — konkretniej niż neutralne `textDiff.noChanges`.

**Stan „porównanie zgrubne" — obsłużony przez komponent, ale trzeba o nim wiedzieć.**
`ui/data/textDiff.ts` ma twarde granice `MAX_LINES = 3000` i `MAX_TOKENS = 2000`; powyżej nich
`diffText` degraduje do jednego bloku „usunięto wszystko / dodano wszystko", a komponent wykrywa to
z kształtu wyniku i pokazuje **przyklejony baner** `textDiff.coarse` („Dokument jest bardzo długi —
porównanie pokazujemy w całych blokach, nie linia po linii"). Przy capie wpisu **40 000 znaków**
ten próg jest realnie osiągalny, więc kreator go zobaczy. **Nie obchodzić go i nie podnosić
stałych** — to jest uczciwy komunikat, a nie usterka.

**Uwaga o `maxHeight`:** minimalna wysokość pustego stanu jest w komponencie warunkowana
**porównaniem stringów** (`maxHeight === '60vh'`). Podanie `24rem` (jak wyżej) świadomie oddaje
niższy pusty stan — to jest pożądane w karcie, ale trzeba wiedzieć, że wysokość nie jest liczona,
tylko porównywana dosłownie.

**Diff tytułu i metadanych** renderujemy **osobno, nad** diffem treści (jednowierszowe
`TextDiffView` dla tytułu; dla metadanych lista zmienionych pól `stare → nowe` z `Badge`ami).
Wrzucenie tytułu do tej samej kolumny co treść zrobiłoby z pierwszej linii artykułu część diffa
treści, czym nie jest.

### 24.6 Panel relacji — `KnowledgeDraftRelationsPanel.vue`

Reuse grafu 1:1 (DC5). Układ identyczny z sekcją 8: na `next-lg` płótno + lista sąsiadów;
poniżej — `SegmentedControl` „Lista | Graf" z **listą domyślnie** (D9).

Różnice wobec sekcji 8:

1. **Węzły szkiców** — `is_draft: true` → obrys `--color-next-modified`, wypełnienie
   `--color-next-modified-subtle` **oraz** wzór: obrys **podwójny** (drugi okrąg o r+3).
   Sam kolor nie wystarczy; w liście sąsiadów szkic ma `Badge` „Szkic" — słowem.
2. **Nowelizacja** — bez osobnego węzła. Węzeł targetu dostaje znacznik `amends`:
   mały glif `pencil` w prawym górnym rogu kropki **oraz** `Badge variant="modified"`
   „Nowelizowany" w wierszu listy.
3. **Legenda** — te same cztery rodzaje krawędzi co sekcja 8.8 **plus `mention`** (§23/B10)
   i dwa dodatkowe wiersze kształtów węzła: „Szkic" (podwójny obrys) i „Nowelizowany" (glif).
4. **Próg** — nad płótnem `Text variant="caption"`: „Pokazujemy powiązania powyżej {score}"
   z faktycznie użytym progiem z odpowiedzi (DC6). Gdy backend go nie zwraca — **nie pisać nic**
   (zdanie z wymyśloną liczbą jest gorsze niż jego brak).
5. **Pusto** — inny komunikat niż w sekcji 8:
   `knowledge.compose.relationsEmpty` = „Ten szkic nie łączy się jeszcze z niczym w bazie."
   + `Text variant="caption"` „To normalne dla pierwszego wpisu." — **nie** „za mało połączeń,
   żeby narysować graf", bo w kreatorze to nie jest problem do naprawienia.

### 24.7 Błędy akceptacji — walidacja backendu na karcie

Akceptacja to `POST` wpisu, więc szkic przechodzi przez **pełną walidację zapisu wpisu**
(`docs/backend/knowledge-api.md`, „Entry write fields"). Treść **wygenerowana przez AI** jest
podatna na dwa odrzucenia, których człowiek piszący ręcznie prawie nigdy nie trafiał:

| 422 | Kiedy | Copy na karcie |
| --- | --- | --- |
| `knowledge.validation.directive_<kind>` (`directive` `@[…]` · `reference` `{{…}}` · `if_block` · `branch` `[[IF…]]` · `nul`) | agent opisujący szablony/workflow cytuje składnię dyrektyw w treści | `knowledge.compose.error.directive` = „Treść zawiera składnię szablonów ({kind}), której wpis nie może przechowywać." |
| `too_many_chunks` | > 50 fragmentów mimo < 40 000 znaków (dużo krótkich nagłówków) | `knowledge.compose.error.tooManyChunks` = „Ten szkic ma zbyt wiele sekcji. Podziel go na osobne hasła." |
| `slug_taken` | slug wyprowadzony z tytułu zajęty przez żywy wpis | `knowledge.compose.error.slugTaken` = „Wpis o takim tytule już istnieje." + akcja „Otwórz istniejący" |
| `metadata.<key>` | agent wypełnił pole niezgodnie ze schematem / użył nieznanego klucza | komunikat serwera **verbatim** pod nazwą pola |

Render: `Alert variant="danger" size="sm"` **wewnątrz karty**, pod treścią, z nazwą pola
i `#actions` = `Button variant="outline" size="sm"` **„Popraw ten wpis"** (prefill instrukcji
naprawczej — patrz 24.4). Karta **nie znika** i nie traci treści.
Serwerowy komunikat ma pierwszeństwo nad klienckim (konwencja `ConstantEditorDrawer`).

**409 na nowelizacji** (ktoś edytował target równolegle) — **nie** `KnowledgeStaleWriteModal`
(tamten dotyczy pracy człowieka w edytorze; tu praca jest maszynowa i tania do powtórzenia).
`Alert variant="warning"` w karcie, dwie drogi:

| Droga | Przycisk | Koszt AI |
| --- | --- | --- |
| **Pokaż diff wobec nowej wersji** | `Button` primary | **brak** — przełącza baseline „Wersja w bazie" na świeżo pobraną treść i pokazuje diff |
| **Popraw ponownie** | `Button variant="outline"` z **chipem kosztu** | jedna iteracja agenta z nową treścią targetu w kontekście |

**Badge „Baseline nieaktualny" pojawia się ZANIM użytkownik kliknie akceptuj** — kreator porównuje
`current_revision_id` targetu (znany z chwili generacji) z aktualnym przy każdym settle/refetch.
Wykrycie konfliktu po kliknięciu „Akceptuj" jest o jedno rozczarowanie za późno.

### 24.8 Stan „kreator niedostępny" — `KnowledgeComposeUnavailable.vue` (DC9)

Sprawdzane **przed** renderem formularza. Zamiast pola źródłowego:

`Alert variant="warning"` (`role="alert"`), tytuł `knowledge.compose.unavailable.title`
= „Kreator jest chwilowo niedostępny", treść zależna od przyczyny:

| Przyczyna | Copy |
| --- | --- |
| `budget_exceeded` | „Miesięczny budżet AI tego obszaru roboczego został wyczerpany. Odnowi się {date}." |
| `disabled` (kill-switch) | „Kreator AI jest wyłączony w konfiguracji." |
| `no_vector` | „To środowisko nie obsługuje indeksu semantycznego, więc kreator nie potrafi sprawdzić powiązań." |

`#actions`:
- `Button variant="outline" size="sm"` **„Zobacz zużycie AI"** → `next.settings.aiUsage`
  (tylko dla `budget_exceeded`),
- zawsze: `Text variant="caption"` **„Edycja istniejących wpisów działa normalnie."**
  + `Button variant="link"` „Wróć do czytnika".

**Reuse, nie kopia — i mały awans do design systemu.**
`pages/generator/session/SessionBudgetBanner.vue` robi **dokładnie** to, czego tu trzeba:
props `{ summary: AiUsageSummary | null, canManage: boolean, dismissible?: boolean }`,
emity `dismiss` / `manage` (jest **route-agnostyczny** — sam nie nawiguje), renderuje
`Alert variant="danger"` (czyli `role="alert"` + `aria-live="assertive"`), formatuje datę odnowienia
z `summary.period.resets_at` z zabezpieczeniem `Number.isNaN`, i **rozdziela CTA po roli**:
właściciel dostaje „Podnieś limit", członek — samo zdanie „Poproś właściciela", **bez kontrolki,
która i tak zwróciłaby 403**.

Wiedza jest **trzecim** modułem potrzebującym tego banera (Generator, Boty, Wiedza), więc:
**zalecane — przenieść go do `ui/patterns/AiBudgetBanner.vue`** i przepiąć użycie w Generatorze.
Import z `pages/generator/**` do `pages/knowledge/**` jest zakazany (granica stron), a kopia
oznaczałaby dwa języki dla jednego zdarzenia. Bloku i18n **nie ruszać** przy przenosinach —
`generator.sessions.budget.*` może zostać na miejscu i być podany propem, albo przenieść się do
`aiBudget.*`; decyzja należy do batcha, który wykona awans.

**Wykrywanie 429 też jest już napisane — nie pisać drugi raz.**
`app/stores/sessions.ts` eksportuje `AI_BUDGET_ERROR_CODE = 'ai_budget_exceeded'` oraz
`isBudgetError(err)`, które uznaje **albo** `status === 429`, **albo** `data.code`/`data.error`
równe temu kodowi. Kreator ma używać tej funkcji. Ponieważ import z magazynu Generatora do Wiedzy
też przekracza granicę modułu, **przenieść `isBudgetError` + stałą do współdzielonego miejsca**
(`app/lib/aiBudget.ts`) razem z awansem banera — jedna zmiana, dwa długi spłacone.

**Rozróżnienie stanów, które Generator ma, a które łatwo zgubić:**
`runBudgetBlocked` (przejściowa odmowa TEGO uruchomienia — **da się odrzucić**) to co innego niż
`summary.blocked` (workspace faktycznie ponad capem — **nie da się odrzucić**).
Kreator dziedziczy tę zasadę: `:dismissible="runBudgetBlocked && !summary?.blocked"`.

Meter odświeżamy po **każdej** operacji, która wydała budżet (generacja i każda poprawka),
nie tylko po błędzie — inaczej chip pokazuje nieaktualny stan aż do przeładowania strony.

### 24.9 Pozostałe stany

| Stan | Realizacja |
| --- | --- |
| **Sesja `failed`** | `EmptyState variant="error"` z **powodem z serwera** (verbatim, nie „coś poszło nie tak") + `#action` `Button leading-icon="rotate-ccw"` „Spróbuj ponownie" (ponawia z tym samym źródłem — pole zostaje wypełnione) + `#secondary` „Zmień tekst źródłowy" |
| **Sesja porzucona** (wejście na stary `:session`, którego backend już nie trzyma) | `EmptyState icon="sparkles"`, „Ta sesja kreatora wygasła", opis „Tekst źródłowy nie został zapisany.", akcja „Zacznij od nowa" → `compose` bez `:session` |
| **Zero szkiców z niepustego źródła** | `EmptyState variant="search"`, „Kreator nie znalazł tu materiału na hasła", opis „Spróbuj napisać więcej konkretów — nazwy, definicje, zasady." + „Popraw tekst źródłowy" |
| **Pusta baza / pierwsza sesja** | nad formularzem `Alert variant="info"` z `knowledge.compose.firstSession` = „To pierwsze hasła w tej bazie — kreator nie ma jeszcze z czym ich porównać. Powiązania pojawią się przy kolejnych." |
| **Wyjście z nieobejrzanymi szkicami** | `onBeforeRouteLeave` + `useConfirm({ variant:'danger' })`: „Masz szkice, których jeszcze nie zaakceptowano ani nie odrzucono: {count}. Jeśli wyjdziesz, przepadną." `confirmLabel` „Wyjdź". **Nie** blokować wyjścia gdy wszystkie szkice są rozstrzygnięte. |
| **Błąd pobrania relacji** | `Alert variant="danger" size="sm"` w panelu + „Spróbuj ponownie"; **reszta karty działa** (relacje są dodatkiem, nie warunkiem akceptacji) |

### 24.10 Responsywność i dostępność kreatora

| Breakpoint | Układ |
| --- | --- |
| base | jedna kolumna; diff i relacje jako `AccordionItem` w karcie; pasek poprawki `sticky bottom-0` |
| `next-md` | karta szkica dwukolumnowa w części metadanych |
| `next-lg` | panel relacji obok treści karty (`w-80`); lista sąsiadów widoczna |

**Dostępność:**
- Tablica szkiców to `<ol>`; każda karta `<li>` z `<h3>` tytułem — struktura nagłówków `h1` (kreator)
  → `h3` (szkice) **bez przeskoku** wymaga `<h2>` na nagłówku tablicy („Szkice: {count}").
- Zakończenie generacji ogłaszane w `aria-live="polite"`:
  „Gotowe. Szkice: {count}." — użytkownik niewidomy nie ma jak zobaczyć, że skeletony zniknęły.
- Każdy `Button` ikonowy z `aria-label` zawierającym tytuł szkica.
- Rozwijanie treści karty: `aria-expanded` + `aria-controls`.
- Przełącznik baseline'ów: `SegmentedControl` = `role="radiogroup"` (wybór, nie panele).
- Diff: patrz DC10 — bez tego czytnik ekranu dostaje diff bez informacji o zmianach.
- Skróty: brak własnych (kreator jest polem tekstowym — przechwytywanie liter byłoby wrogie).

---

## 25. Errata B13 — konwersja modułu na AI-only

> Sekcje 1–22 **nie są przepisywane wstecznie** (konwencja tego dokumentu, patrz nagłówek §23).
> Poniżej — dokładny wykaz, co pivot unieważnia i czym zastępuje. Tam, gdzie zapis w §1–22
> aktywnie wprowadzałby w błąd, sekcja ma dopisany jednowierszowy odsyłacz do tej errata.

### 25.1 Wykaz zmian per sekcja

| Sekcja | Było | Jest po B13 |
| --- | --- | --- |
| **§2.2 Trasy** | 5 sekcji bazy | + `compose/:session?` (`next.knowledge.base.compose`); `edit/:slug?` → **`edit/:slug`** (slug obowiązkowy); `sectionRedirect` bez zmian (`compose` nie jest celem starego `?section=`) |
| **§2.3 Shell** | `resourceItems`: reader/table/graph/settings | **Kreator** jako PIERWSZA pozycja (`sparkles`), przed Czytnikiem |
| **§3.4 Pusty stan listy baz** | „Utwórz bazę" + „Przenieś wiedzę z bota" | bez zmian w samych ścieżkach; **zmiana dosypana:** po utworzeniu bazy `KnowledgeBaseEditorDrawer` przekierowuje na **`compose`**, nie na czytnik — pusta baza bez wpisów nie ma czego pokazać w czytniku, a pierwszy krok użytkownika to i tak napisanie źródła |
| **§4.4 Artykuł — klik ducha** | `router.push(edit, query:{title:slug})` | `router.push(compose, query:{seed:slug})` |
| **§4.9 Panel „Czerwone linki"** | CTA „Utwórz wpis" → edytor z prefillem | CTA „Utwórz wpis" → **kreator z ziarnem** (`?seed=<slug>`); etykieta `knowledge.ghosts.create` **zostaje** (nadal prawdziwa) |
| **§4.11 Historia wersji** | „**Diffu nie ma w MVP**" | **NIEAKTUALNE.** `ui/data/TextDiffView.vue` istnieje (awans w B14). Drawer dostaje diff rewizji wobec **bieżącej treści wpisu** — patrz 25.3 |
| **§5.1 Tabela — układ** | `#actions`: „Nowy wpis" → edytor | „Nowy wpis" → **kreator** (etykieta `knowledge.entries.new` bez zmian) |
| **§5.4 Akcje w wierszu** | Otwórz · Edytuj · kebab: Historia / **Duplikuj** / Zmień status / Kosz | **„Duplikuj" WYPADA** — było jedyną ścieżką tworzenia wpisu omijającą kreator. W jego miejsce **„Zaproponuj zmianę przez AI"** → `compose?amend=<entryId>` (nowelizacja). Reszta bez zmian |
| **§6 Edytor wpisu** | tryb create + edit | **wyłącznie edycja istniejących** — pełna lista usunięć w 25.2 |
| **§8.3 Kontrakt layoutu** | `GraphEdgeIn { source, target, kind }` | **NIEAKTUALNE — i była to pułapka nazewnicza.** Drut nazywa rodzaj krawędzi `source` (`edges[].source`), a końce to `from`/`to`. Zbudowany kod ma `layoutKnowledgeGraph(data, options)`, `GraphEdgePlacement { id, from, to, kind, … }` i `GRAPH_EDGE_KINDS`. Dla kreatora: dwa **opcjonalne** pola na `GraphNodePlacement` — `isDraft?: boolean`, `amends?: boolean` (dodatek, nie nowy `kind`, żeby nie rozbić istniejących wyczerpujących `switch`) |
| **§8.8 Legenda** | 4 rodzaje | **5** — dochodzi `mention` (§23/B10) + w kreatorze 2 wiersze kształtów węzła |
| **§11.2 Puste stany** | „Ta baza jest pusta" → „Napisz pierwszy wpis" → edytor | ten sam tekst, **cel = kreator** |
| **§12.1 Status `proposed`** | „zatwierdź w edytorze wpisu" | **nadal aktualne** — szkice kreatora to nie to samo co `proposed` (te przychodzą z bota). Skrzynka propozycji dalej poza MVP |
| **§17.2 EXTEND** | — | + `ui/data/TextDiffView.vue` (a11y, DC10) · + `ui/patterns/AiBudgetBanner.vue` (awans z Generatora) · + `app/lib/aiBudget.ts` (awans `isBudgetError`) |
| **§20 Czego NIE ma w MVP** | wiersz „**Diff wersji**" | **skreślony** — diff wchodzi (25.3). Dochodzą nowe wykluczenia: 25.7 |
| **D15** | dotyczyło przycisku „Przenieś wiedzę z bota" | **rozszerzone na kreator**: przycisk „Nowy wpis" zostaje aktywny **zawsze**; niedostępność wyjaśnia ekran docelowy (§24.8), sprawdzana **przed** renderem |

### 25.2 Edytor traci tryb tworzenia — dokładna lista usunięć

Plik: `resources/js/next/pages/knowledge/KnowledgeEntryEditorView.vue` (480 linii).
Zweryfikowane w kodzie; to jest lista do wykonania, nie sugestia.

| # | Co | Gdzie |
| --- | --- | --- |
| 1 | `const isNew = computed(() => routeSlug.value === null)` | ~L59 |
| 2 | Gałąź `if (!routeSlug.value) { resetForNew(); return; }` | ~L85 |
| 3 | **Gałąź „nieznany slug = twórz"** — `if (!row) { resetForNew(routeSlug.value); return; }` → zastąpić **stanem „nie ma takiego wpisu"** (`EmptyState variant="search"` + akcja „Opisz go w kreatorze" → `compose?seed=<slug>`) | ~L91 |
| 4 | Cała funkcja `resetForNew()` + odczyt `route.query.title` | L111–125 |
| 5 | Gałąź „brud" `if (!base) return title/content !== ''` | L184–188 |
| 6 | Gałąź payloadu `if (!previous) { … pełne body … }` | L216–225 |
| 7 | Ramię `store.createEntry(...)` w `save()` | L255–257 |
| 8 | Ternary nagłówka `isNew ? newTitle : editTitle` → sam `editTitle` | L379–381 |
| 9 | `:disabled="isNew"` na polu slug **oraz** `<Text v-if="isNew">{{ slugOnCreate }}</Text>` | L452–459 |
| 10 | Ternary w `goBack()` | L337–343 |
| 11 | `allowCreate: true` w opcjach autouzupełniania `[[` → **zostaje**, ale etykieta się zmienia (25.5) | ~L316 |
| 12 | Router: `path: 'edit/:slug?'` → `'edit/:slug'` | `app/router/index.ts` |
| 13 | `openEditor()` w `KnowledgeReaderView.vue`: `slug: slug ?? undefined` — **druga cicha furtka do trybu create**; ma nie dać się wywołać bez sluga | `KnowledgeReaderView.vue` L390–395 |

**Strażnik trasy** (obowiązkowy — chroni stare zakładki i linki):

```ts
// w rekordzie `edit/:slug`
beforeEnter: (to) =>
  to.params.slug
    ? true
    : { name: 'next.knowledge.base.compose', params: { baseId: to.params.baseId },
        query: to.query.title ? { seed: String(to.query.title) } : {} },
```

Stary deep-link `…/edit?title=polityka-zwrotow` ląduje więc w kreatorze **z ziarnem**, a nie na
błędzie 404 ani w pustym formularzu.

`store.createEntry` (`POST /knowledge/bases/{baseId}/entries`) **zostaje w magazynie** — po pivocie
jego jedynym wywołującym jest akceptacja szkica (§24.4), a nie edytor.

### 25.3 Historia wersji dostaje diff (unieważnia §4.11 i wiersz w §20)

Nagłówek modułu `KnowledgeVersionsDrawer.vue` mówi dziś: *„NO DIFF (deliberately out of MVP):
diffing markdown that also carries inline directives is its own problem"*. Powód odpadł z dwóch
stron: (a) `ui/data/TextDiffView.vue` istnieje i jest komponentem design systemu, (b) treść wpisu
**nie może** zawierać dyrektyw — zabrania tego strażnik dyrektyw po stronie backendu (422), więc
problem „markdown z dyrektywami" w tym module nie występuje.

Zmiana w drawerze (minimalna): pozycja `TimelineItem` dostaje `#actions`
`Button variant="ghost" size="xs" leading-icon="file-text"` **„Porównaj z bieżącą"**, która
rozwija pod pozycją `TextDiffView` z `:old="revision.content"` `:current="entry.content"`,
`max-height="20rem"`, `:empty-label="t('knowledge.history.diffUnchanged')`.
Rozwinięta jest **jedna** pozycja naraz (`aria-expanded` + `aria-controls`).
Komentarz modułu w pliku trzeba zaktualizować — inaczej zostaje kłamstwem w kodzie.

**Warunek:** `GET /knowledge/entries/{entry}/revisions` musi nieść `content` rewizji.
Jeśli nie niesie — diff wymaga dociągnięcia treści per rewizja (K6).

### 25.4 Wszystkie wejścia „nowy wpis" → kreator

Zweryfikowane w kodzie; **10 miejsc**, wszystkie dziś celują w `next.knowledge.base.edit`:

| # | Plik | Wyzwalacz | Nowy cel |
| --- | --- | --- | --- |
| 1 | `KnowledgeReaderView.vue` (~L531 / L397) | pusty stan bazy, „Napisz pierwszy wpis" | `compose` (bez ziarna) |
| 2 | `KnowledgeReaderView.vue` (L562) | „nie ma wpisu o tej nazwie" | `compose?seed=<slug>` |
| 3–5 | `KnowledgeReaderView.vue` (L576/591/614 → `createGhost` L115–122) | `@create-ghost` z artykułu i z obu instancji railu | `compose?seed=<slug>` |
| 6 | `KnowledgeEntriesTableView.vue` (L399 / L340) | `PageHeader` „Nowy wpis" | `compose` |
| 7 | `KnowledgeEntriesTableView.vue` (L572) | `#empty` tabeli (bez filtrów) | `compose` |
| 8 | `KnowledgeGraphView.vue` (L465 / L266–272) | pusty stan nieznanego sluga | `compose?seed=<slug>` |
| 9 | `KnowledgeGraphView.vue` (L636) | panel boczny węzła-ducha | `compose?seed=<slug>` |
| 10 | `KnowledgeGraphView.vue` (L229–236) | dwuklik / Enter na węźle-duchu | `compose?seed=<slug>` |

> **`createGhost` jest zduplikowany** — identyczna funkcja żyje w `KnowledgeReaderView.vue`
> **i** `KnowledgeGraphView.vue`. Przy tej zmianie wyciągnąć ją do jednego miejsca
> (`pages/knowledge/composeSeed.ts`), zamiast poprawiać dwa razy to samo.

Wejścia **niezmienione**: `KnowledgeBasesView.vue` (tworzy BAZY, nie wpisy), wiersz „Edytuj"
w tabeli (prawdziwa edycja, `slug` obecny), „Edytuj" w slim barze czytnika.

### 25.5 i18n

#### Do USUNIĘCIA z obu katalogów (parytet pinowany przez `vue-tsc` + test)

| Klucz | PL (obecne) | Powód |
| --- | --- | --- |
| `knowledge.editor.newTitle` | „Nowy wpis" | edytor nigdy nie jest w trybie tworzenia |
| `knowledge.editor.slugOnCreate` | „Identyfikator powstanie z tytułu przy tworzeniu wpisu." | podpowiedź istniała tylko dla create |

#### Do ZMIANY (klucz zostaje, treść/cel się zmienia)

| Klucz | Było | Ma być |
| --- | --- | --- |
| `knowledge.editor.link.create` → **rename na `knowledge.editor.link.ghost`** | „Utwórz wpis „{query}"" | **„Wstaw link do nieistniejącego wpisu „{query}""** / „Insert a link to a missing entry "{query}"" — bo ta akcja **nigdy** nie tworzyła wpisu, tylko wstawiała `[[slug]]`; po pivocie ta nazwa byłaby jawnym kłamstwem |
| `knowledge.reader.ghost.previewHint` | „Kliknij, aby go utworzyć." | **„Kliknij, aby opisać go w kreatorze."** / „Click to describe it in the composer." |
| `knowledge.reader.notFound.description` | „Mógł zostać przemianowany albo usunięty. Możesz go teraz utworzyć." | **„Mógł zostać przemianowany albo usunięty. Możesz go teraz opisać w kreatorze."** / „…You can describe it in the composer now." |

Bez zmian (nadal prawdziwe): `knowledge.ghosts.create` („Utwórz wpis"),
`knowledge.entries.new` („Nowy wpis"), `knowledge.reader.empty.action` („Napisz pierwszy wpis").

#### Do DODANIA — `knowledge.module.nav.compose`

| Klucz | PL | EN |
| --- | --- | --- |
| `knowledge.module.nav.compose` | Kreator | Composer |

#### Do DODANIA — `knowledge.graph.edge.mention` (zaległość B10)

| Klucz | PL | EN |
| --- | --- | --- |
| `knowledge.graph.edge.mention` | Wzmianki | Mentions |

#### Do DODANIA — `textDiff.*` (DC10)

| Klucz | PL | EN |
| --- | --- | --- |
| `textDiff.rowAdded` | Dodano | Added |
| `textDiff.rowRemoved` | Usunięto | Removed |

#### Do DODANIA — `knowledge.history.diffUnchanged` (25.3)

| Klucz | PL | EN |
| --- | --- | --- |
| `knowledge.history.compare` | Porównaj z bieżącą | Compare with current |
| `knowledge.history.diffUnchanged` | Ta wersja niczym się nie różni od bieżącej treści wpisu. | This version is identical to the entry's current content. |

#### Do DODANIA — pełny blok `knowledge.compose.*`

Wstawić w obu katalogach w tym samym miejscu (po `knowledge.entries`, przed `knowledge.editor`).
**Brak pluralizacji** → cała copy licznikowa w formie `Etykieta: {count}`.

| Klucz (`knowledge.compose.`) | PL | EN |
| --- | --- | --- |
| `title` | Kreator wpisów | Entry composer |
| `subtitle` | Napisz, co wiesz — kreator ułoży z tego hasła. | Write what you know — the composer turns it into entries. |
| `nav` | Kreator | Composer |
| `sourceLabel` | Co chcesz przekazać botom? | What do you want the bots to know? |
| `sourceHint` | Jeden tekst wystarczy. Kreator sam podzieli go na hasła i połączy je ze sobą. | One block of text is enough. The composer splits it into entries and links them together. |
| `sourcePlaceholder` | Napisz wszystko, co wiesz i co chcesz, żeby wiedziały boty. Nie musisz tego porządkować — od tego jest kreator. | Write everything you know and want the bots to know. You do not have to organise it — that is what the composer is for. |
| `seedPrefill` | Napisz, czym jest „{title}". | Describe what "{title}" is. |
| `seedNotice` | Ten wpis jest linkowany z innych haseł, ale jeszcze nie istnieje. | Other entries link to this one, but it does not exist yet. |
| `amendNotice` | Poprawiasz istniejący wpis: „{title}". | You are amending an existing entry: "{title}". |
| `amendPlaceholder` | Co ma się zmienić w tym wpisie? | What should change in this entry? |
| `estimate` | Szacowany koszt: {amount} | Estimated cost: {amount} |
| `start` | Zacznij | Start |
| `startBlocked` | Zmniejsz tekst do 20 000 znaków, aby zacząć | Shorten the text to 20,000 characters to start |
| `cancel` | Anuluj | Cancel |
| `generating` | Kreator czyta Twój tekst i układa hasła. To zwykle kilkadziesiąt sekund. | The composer is reading your text and drafting entries. This usually takes under a minute. |
| `generatingLabel` | Przygotowywanie szkiców | Preparing drafts |
| `stalled` | Nie dostaliśmy potwierdzenia zakończenia. | We did not get a completion signal. |
| `refresh` | Odśwież | Refresh |
| `sourceCollapsed` | Pokaż tekst źródłowy | Show the source text |
| `sourceHide` | Ukryj tekst źródłowy | Hide the source text |
| `ready` | Gotowe. Szkice: {count}. | Done. Drafts: {count}. |
| `boardTitle` | Szkice: {count} | Drafts: {count} |
| `selectAll` | Zaznacz wszystkie szkice | Select every draft |
| `select` | Zaznacz szkic: {title} | Select draft: {title} |
| `acceptSelected` | Akceptuj zaznaczone: {count} | Accept selected: {count} |
| `acceptSelectedNone` | Zaznacz szkice, żeby zaakceptować je razem | Select drafts to accept them together |
| `acceptSelectedConfirm.title` | Zaakceptować zaznaczone szkice? | Accept the selected drafts? |
| `acceptSelectedConfirm.message` | Zaakceptujesz szkice: {count}. Powstaną wpisy o statusie Zatwierdzony. | You will accept drafts: {count}. They become entries with the Approved status. |
| `accept` | Akceptuj | Accept |
| `acceptDraft` | Zapisz jako roboczy | Save as draft |
| `accepted` | Zaakceptowany | Accepted |
| `acceptedOpen` | Otwórz wpis | Open entry |
| `reject` | Odrzuć szkic: {title} | Discard draft: {title} |
| `rejected` | Odrzucono szkic | Draft discarded |
| `undo` | Cofnij | Undo |
| `expand` | Rozwiń | Expand |
| `collapse` | Zwiń | Collapse |
| `badgeNew` | Nowy wpis | New entry |
| `badgeAmend` | Nowelizacja → {title} | Amends → {title} |
| `badgeStaleBaseline` | Baseline nieaktualny | Baseline out of date |
| `badgeStaleBaselineHint` | Wpis, który ten szkic poprawia, zmienił się po wygenerowaniu szkicu. | The entry this draft amends changed after the draft was generated. |
| `duplicate` | Pokrywa się z „{title}" w {percent}% | {percent}% overlap with "{title}" |
| `duplicateOpen` | Otwórz istniejący | Open the existing one |
| `duplicateConfirm.title` | Zaakceptować mimo pokrycia? | Accept despite the overlap? |
| `duplicateConfirm.message` | „{draft}" pokrywa się z istniejącym wpisem „{existing}" w {percent}%. Powstaną dwa osobne hasła. | "{draft}" overlaps the existing entry "{existing}" by {percent}%. You will end up with two separate entries. |
| `diff` | Porównaj | Compare |
| `diffBaseline` | Porównaj z | Compare against |
| `diffOriginal` | Oryginał | Original |
| `diffPrevious` | Poprzedni szkic | Previous draft |
| `diffPreviousNone` | Jeszcze nie było poprawek | There have been no revisions yet |
| `diffStored` | Wersja w bazie | Version in the base |
| `diffUnchanged` | Ta wersja niczym się nie różni od wybranej podstawy. | This version is identical to the selected baseline. |
| `diffTitle` | Tytuł | Title |
| `diffMetadata` | Metadane | Metadata |
| `refineLabel` | Co poprawić? | What should change? |
| `refinePlaceholder` | Np. „Skróć wszystkie hasła" albo „Dodaj sekcję o cenach". | E.g. "Make every entry shorter" or "Add a pricing section". |
| `refine` | Popraw | Revise |
| `refineScoped` | Popraw tylko wpis „{title}": | Revise only the entry "{title}": |
| `refineThis` | Popraw ten wpis | Revise this entry |
| `historyTitle` | Historia poprawek: {count} | Revision history: {count} |
| `historyItem` | Poprawka {number} | Revision {number} |
| `relations` | Powiązania | Relations |
| `relationsEmpty` | Ten szkic nie łączy się jeszcze z niczym w bazie. | This draft does not connect to anything in the base yet. |
| `relationsEmptyHint` | To normalne dla pierwszego wpisu. | That is normal for a first entry. |
| `relationsThreshold` | Pokazujemy powiązania powyżej {score} | Showing connections above {score} |
| `relationsError` | Nie udało się wczytać powiązań | We could not load the connections |
| `nodeDraft` | Szkic | Draft |
| `nodeAmended` | Nowelizowany | Amended |
| `firstSession` | To pierwsze hasła w tej bazie — kreator nie ma jeszcze z czym ich porównać. Powiązania pojawią się przy kolejnych. | These are the first entries in this base — the composer has nothing to compare them with yet. Connections show up from the next session on. |
| `conflict.title` | Ktoś zmienił ten wpis w międzyczasie | Someone changed this entry meanwhile |
| `conflict.message` | Wpis „{title}" został zmieniony po wygenerowaniu tego szkica. | The entry "{title}" changed after this draft was generated. |
| `conflict.showDiff` | Pokaż diff wobec nowej wersji | Show a diff against the new version |
| `conflict.refine` | Popraw ponownie | Revise again |
| `error.directive` | Treść zawiera składnię szablonów ({kind}), której wpis nie może przechowywać. | The content contains template syntax ({kind}) that an entry cannot store. |
| `error.tooManyChunks` | Ten szkic ma zbyt wiele sekcji. Podziel go na osobne hasła. | This draft has too many sections. Split it into separate entries. |
| `error.slugTaken` | Wpis o takim tytule już istnieje. | An entry with this title already exists. |
| `error.generic` | Nie udało się zapisać tego szkica. | We could not save this draft. |
| `failed.title` | Kreator nie dokończył pracy | The composer did not finish |
| `failed.retry` | Spróbuj ponownie | Try again |
| `failed.editSource` | Zmień tekst źródłowy | Change the source text |
| `expired.title` | Ta sesja kreatora wygasła | This composer session has expired |
| `expired.description` | Tekst źródłowy nie został zapisany. | The source text was not kept. |
| `expired.action` | Zacznij od nowa | Start over |
| `noDrafts.title` | Kreator nie znalazł tu materiału na hasła | The composer found nothing to turn into entries |
| `noDrafts.description` | Spróbuj napisać więcej konkretów — nazwy, definicje, zasady. | Try adding specifics — names, definitions, rules. |
| `leave.title` | Porzucić nieobejrzane szkice? | Discard the drafts you have not reviewed? |
| `leave.message` | Masz szkice, których jeszcze nie zaakceptowano ani nie odrzucono: {count}. Jeśli wyjdziesz, przepadną. | You have drafts you have not accepted or discarded: {count}. Leaving discards them. |
| `leave.confirm` | Wyjdź | Leave |
| `unavailable.title` | Kreator jest chwilowo niedostępny | The composer is temporarily unavailable |
| `unavailable.budget` | Miesięczny budżet AI tego obszaru roboczego został wyczerpany. Odnowi się {date}. | This workspace's monthly AI budget is used up. It renews on {date}. |
| `unavailable.disabled` | Kreator AI jest wyłączony w konfiguracji. | The AI composer is switched off in the configuration. |
| `unavailable.noVector` | To środowisko nie obsługuje indeksu semantycznego, więc kreator nie potrafi sprawdzić powiązań. | This environment has no semantic index, so the composer cannot check connections. |
| `unavailable.editingWorks` | Edycja istniejących wpisów działa normalnie. | Editing existing entries works as usual. |
| `unavailable.backToReader` | Wróć do czytnika | Back to the reader |

### 25.6 Pytania kontraktowe do B11a — **żadnego z tych kluczy nie kodować przed potwierdzeniem**

Backend kreatora (B11a) powstaje równolegle z tym dokumentem. Poniższe są **założeniami UI**,
nie kontraktem. `pages/knowledge/types.ts` dla kreatora powstaje dopiero z odpowiedzi B11a.

| # | Pytanie | Dlaczego blokujące |
| --- | --- | --- |
| **K1** | Czy backend **broadcastuje** zakończenie sesji kreatora? Zakładany kanał `knowledge.workspace.{workspaceId}`, zdarzenie `.knowledge-compose.updated`, payload **wyłącznie statusem** `{ id, status }`. | Bez broadcastu nie da się zbudować ekranu zgodnie z konwencją projektu (DC1) — polling jest wykluczony jawnym wymaganiem właściciela zapisanym w `useSessionSettle.ts`. To jest **decyzja architektoniczna, nie preferencja**. |
| **K2** | Czy endpoint powiązań szkicu zwraca **dokładnie `KnowledgeGraphResource`** (`{ center, nodes[], edges[], ghosts[], truncated }`, `edges[].source` jako rodzaj)? Czy węzeł szkica niesie `is_draft`, a węzeł nowelizowany `amends`? | Warunek reuse'u grafu (DC5). Inny kształt = druga wizualizacja i natychmiastowy dryf. |
| **K3** | Czy próg podobieństwa jest **świadomy długości** źródła i czy odpowiedź niesie **użyty** `min_score`? | Domyślne 0.86 uciszy powiązania dla krótkich szkiców — czyli dokładnie tam, gdzie strażnik duplikatów jest najbardziej potrzebny (DC6). UI nie będzie majstrować przy progu sam. |
| **K4** | Czy istnieje **szacunek kosztu przed uruchomieniem** (generacja i każda poprawka osobno) i w jakiej walucie/kształcie? | Chip szacunku renderuje się **tylko** gdy serwer poda liczbę. Zmyślona liczba jest gorsza niż jej brak. |
| **K5** | Czy `POST /knowledge/links/{link}/dismiss` przyjmuje krawędzie **`mention`**? `docs/backend/knowledge-api.md` **sam sobie przeczy**: tabela rodzajów mówi „`similarity` i `mention` są odrzucalne", a wiersz endpointu — „422 dla czegokolwiek poza `similarity`". | Frontend ma czytać `can_be_dismissed` z wiersza (tak każe kontrakt), ale jedno z tych zdań w dokumencie jest nieaktualne i trzeba wiedzieć które. |
| **K6** | Czy `GET /knowledge/entries/{entry}/revisions` niesie **`content`** rewizji? | Bez tego diff historii (25.3) wymaga N dociągnięć. |
| **K7** | Kształt odpowiedzi **dostępności kreatora** — zakładane `{ available: bool, reason: 'budget_exceeded'\|'disabled'\|'no_vector', resets_at?, summary? }`. Czy da się to odczytać **jednym** zapytaniem przy wejściu na trasę? | DC9: sprawdzenie musi być **przed** renderem formularza, nie po kliknięciu. |
| **K8** | Cykl życia sesji: nazwy statusów, czy źródło jest przechowywane, po jakim czasie sesja wygasa, czy poprawka tworzy nową sesję czy iterację w tej samej. | Steruje stanami 24.9 („sesja porzucona", „spróbuj ponownie z tym samym źródłem"). |
| **K9** | Czy akceptacja szkica to zwykły `POST /knowledge/bases/{base}/entries` (a nowelizacja `PATCH /knowledge/entries/{id}`), czy osobny endpoint akceptacji? Czy przy nowelizacji wysyłamy `expected_revision_id`? | Decyduje, czy błędy z 24.7 to zwykła walidacja zapisu wpisu (którą już znamy), czy nowy zestaw. |

### 25.7 Rozszerzenie §20 — czego NIE ma w kreatorze (MVP)

| Nie ma | Dlaczego |
| --- | --- |
| **Ręczne tworzenie wpisu** | To jest sens pivotu. Edytor obsługuje wyłącznie istniejące wpisy. |
| **Edycja treści szkica wprost na tablicy** | Szkic poprawia się **promptem**, nie ręcznie — inaczej kreator staje się edytorem z dodatkowym krokiem. Po akceptacji wpis jest normalnie edytowalny. |
| **Wybór/parametryzacja agenta w UI** | Agent jest dedykowany i skonfigurowany po stronie serwera. |
| **Kolejka wielu sesji naraz** | Jedna sesja na bazę na raz. |
| **Podgląd „co poszło do agenta"** (prompt systemowy, karta tożsamości, schemat) | Debug, nie produkt. |
| **Ręczne rysowanie krawędzi między szkicami** | Krawędzie `manual` dotyczą istniejących wpisów. |
| **Cofnięcie akceptacji jednym kliknięciem** | Zaakceptowany szkic jest zwykłym wpisem — usuwa się go przez kosz. Osobne „cofnij akceptację" byłoby drugą ścieżką usuwania. |
| **Skrzynka propozycji** | Nadal poza MVP (§20). Szkice kreatora ≠ status `proposed` (ten pochodzi od bota). |

### 25.8 Nowe ryzyka spójności (dopisek do §21)

| # | Ryzyko | Mitygacja |
| --- | --- | --- |
| **R15** | **Polling wbrew konwencji.** Brief B13 mówił „polling"; w projekcie obowiązuje jawne wymaganie właściciela zapisane w `useSessionSettle.ts`, że generacja settluje na zdarzeniu, **nigdy** w pętli. | DC1. Jeśli K1 wypadnie negatywnie (brak broadcastu), **wrócić do właściciela z decyzją**, a nie po cichu wprowadzić pętlę. |
| **R16** | **Dwie ścieżki tworzenia wpisu.** Zostawienie choćby jednej furtki do edytora-w-trybie-create (zwłaszcza `edit/<nieznany-slug>` i `slug ?? undefined`) zrobi z pivotu połowiczny stan. | 25.2 + strażnik trasy. Test: `edit` bez sluga i z nieznanym slugiem **nie renderuje formularza**. |
| **R17** | **Duplikacja `createGhost`** w czytniku i grafie — przy tej zmianie łatwo poprawić jedną, a drugą przeoczyć. | Wyciągnąć do `pages/knowledge/composeSeed.ts` (25.4). |
| **R18** | **Druga wizualizacja grafu.** Jeśli endpoint powiązań kreatora zwróci inny kształt niż `KnowledgeGraphResource`, powstanie drugi layout i drugi kanwas. | K2 jako warunek wstępny; przy negatywnej odpowiedzi **mapować w magazynie do kanonicznego kształtu**, nigdy forkować kanwę. |
| **R19** | **Diff bez sygnału dla czytnika ekranu.** `TextDiffView` oznacza rynienkę `+`/`−` jako `aria-hidden`; kreator opiera akceptację treści na diffie. | DC10 — wizualnie ukryty prefiks per wiersz. Zmiana addytywna, bez wpływu na wygląd i API. |
| **R20** | **`TextDiffView` nie ma testu komponentowego** (jest tylko `textDiff.spec.ts` dla czystego modułu) — `coarse`, `emptyLabel` i `maxHeight` są nieprzetestowane, a kreator opiera się na wszystkich trzech. | Dołożyć `ui/data/__tests__/TextDiffView.spec.ts` razem z poprawką DC10. |
| **R21** | **Odrzucenia walidacji na treści AI.** Strażnik dyrektyw (`@[…]`, `{{…}}`, if-block, `[[IF…]]`, NUL) i `too_many_chunks` (> 50 fragmentów mimo < 40 000 znaków) trafią kreator znacznie częściej niż człowieka piszącego ręcznie. | 24.7 — błąd renderowany **na karcie**, z nazwą pola i akcją „Popraw ten wpis"; karta nie znika. |
| **R22** | **Zaległość `mention` z B10** — backend rysuje ten rodzaj **domyślnie**, frontend nie ma dla niego ani nazwy, ani legendy, ani chipa. Kreator dziedziczy tę lukę. | §23/B10 — domknąć razem z B13, bo panel powiązań kreatora używa tej samej legendy. |
| **R23** | **Przekroczenie granicy stron.** Kreator potrzebuje `SessionBudgetBanner` i `isBudgetError`, które żyją w `pages/generator/**` i `app/stores/sessions.ts`. | Awans do `ui/patterns/AiBudgetBanner.vue` + `app/lib/aiBudget.ts` (§24.8). Kopiowanie = dwa języki dla jednego zdarzenia. |
| **R24** | **Orfan i18n.** Usunięcie kluczy create z jednego katalogu bez drugiego wywala `vue-tsc` (typ `MessageSchema` bierze się z `en.ts`). | 25.5 — usuwać/zmieniać w obu plikach w jednym commicie. |

### 25.9 Delta handoffu (dopisek do §22)

**Kolejność implementacji B13:**

1. **B13a — długi wspólne (przed kreatorem):** awans `AiBudgetBanner` + `aiBudget.ts`;
   `mention` w typach, legendzie, chipach i i18n (§23/B10); `can_be_dismissed` czytane z drutu;
   a11y `TextDiffView` (DC10) + jego test komponentowy.
2. **B13b — konwersja AI-only:** trasa `compose` + strażnik `edit`; usunięcia z 25.2;
   `composeSeed.ts` i przepięcie 10 wejść (25.4); usunięcia/zmiany i18n (25.5).
   **Ten batch da się wykonać i przetestować bez backendu kreatora** — wejścia prowadzą do
   ekranu, który w najgorszym razie pokazuje stan „niedostępny".
3. **B13c — kreator (dopiero po potwierdzeniu K1–K9):** źródło → generacja → tablica → diff →
   powiązania.
4. **B13d — diff historii wersji** (25.3, zależny od K6).

**Testy, które muszą powstać razem z kodem:**
`edit` bez sluga i z nieznanym slugiem nie renderuje formularza (R16) ·
każde z 10 wejść celuje w `compose` z właściwym ziarnem (R16/R17) ·
parytet katalogów i18n po usunięciach (R24) ·
`TextDiffView`: `coarse`, `emptyLabel`, `maxHeight`, prefiksy a11y (R19/R20) ·
legenda i chipy mają 5 rodzajów krawędzi, a odrzucalność czyta `can_be_dismissed` (R22) ·
settle kreatora rozwiązuje się na zdarzeniu i **nie odpytuje w pętli** (R15).

---

## 26. Errata B15a/B15b — kontrakt kreatora zweryfikowany w zbudowanym kodzie

> Dopisane po weryfikacji zbudowanego kodu (`app/modules/Knowledge/**` +
> `resources/js/next/pages/knowledge/**`, batche B15a „sesje + create-only" i B15b
> „nowelizacje/shadow + relacje + diff + RODO"). §24 była spisana **przed** B11a/B15a/B15b i
> oznaczała cały kontrakt drutu jako „DO WERYFIKACJI" (§25.6, pytania K1–K9) — poniżej odpowiedzi na
> te pytania w miejscach, gdzie zbudowany kod różni się od założenia, oraz uściślenia, których żaden
> dokument sprzed kodu nie mógł znać. §24/§25 **nie są przepisywane wstecznie** (konwencja tego
> dokumentu, patrz nagłówek §23) — to jest jedyne aktualne źródło rozstrzygnięć poniżej. Pełny
> kontrakt: `docs/backend/knowledge-api.md` → „The AI composer"; zapis decyzji:
> `docs/decisions/ADR-0046-knowledge-ai-composer.md`.

1. **K1 rozstrzygnięte, ale nazwa zdarzenia jest INNA niż założona w DC1/§24.1 i w tabeli K1.**
   Zweryfikowane w kodzie (`Events/KnowledgeDraftSessionUpdated.php::broadcastAs()` +
   `useComposeSettle.ts`): zdarzenie nazywa się **`knowledge-draft-session.updated`**
   (nasłuch Echo: `.knowledge-draft-session.updated`), **nie** `.knowledge-compose.updated`, jak
   zapisano w DC1 (§24.1) i w pytaniu K1 (§25.6). Kanał (`knowledge.workspace.{workspaceId}`,
   autoryzacja członkostwem) i kształt payloadu (`{ id, status }`, wyłącznie status, nigdy szkice) są
   **dokładnie takie, jak założono** — myli się wyłącznie nazwa zdarzenia. `useComposeSettle.ts` w
   zbudowanym kodzie jest poprawny (nasłuchuje właściwej nazwy); błąd żyje wyłącznie w tym dokumencie.
2. **K2 rozstrzygnięte, ale węzeł nowelizowany niesie `amended_by: [{draft_id}]` — LISTĘ obiektów, nie
   pojedynczą flagę `amends: true`, jak zapisano w DC5 (§24.6) i w tabeli K2.** Zweryfikowane w
   `KnowledgeDraftRelationService::payload()` i w `docs/backend/knowledge-api.md` → „Draft relations
   response": pole `amended_by` jest **zawsze obecne** na każdym węźle (pusta tablica, gdy nic go nie
   nowelizuje) i niesie listę `{ draft_id }` — celowo listę, nie boolean, bo inwariant grafu („każda
   krawędź wskazuje węzeł z `nodes[]`") jest **totalny**, a kształt musi umieć wyrazić więcej niż jedną
   równoległą propozycję nowelizacji tego samego wpisu (dwie sesje naraz), nawet jeśli dziś frontend
   czyta tylko pierwszy element (`amended_by[0]`) do akcji „otwórz nowelizację"
   (`KnowledgeDraftRelationsPanel.vue`, `amendingDraft`). Poza nazwą pola reszta DC5 jest potwierdzona
   co do joty: kształt odpowiedzi to dokładnie `KnowledgeGraphResource`
   (`{ center, nodes[], edges[], ghosts[], truncated }`) plus `is_draft`/`amended_by` na węźle — jeden
   layout grafu, żadnej drugiej wizualizacji.
3. **Baseline `target` w diffie jest domyślny WYŁĄCZNIE po stronie FRONTENDU (i wyłącznie dla
   szkicu-nowelizacji) — serwer sam z siebie domyślnie liczy `original`, niezależnie od tego, czy wpis
   jest nowelizacją.** Doprecyzowanie względem poprzedniej wersji tego punktu, który nie rozróżniał obu
   „domyślności". `GET .../draft-diff` bez parametru `?baseline=` **zawsze** trafia w gałąź `default`
   kontrolera (`KnowledgeEntryRevisionController::draftDiff()`) i liczy `original` — kontroler nie wie
   ani nie pyta, czy wpis jest szkicem-cieniem, dopóki klient jawnie nie poprosi o `?baseline=target`.
   To `KnowledgeDraftDiffPanel.vue` decyduje na starcie, JAKI parametr wysłać:
   `const baseline = ref(isShadow.value ? 'target' : 'original')` — a więc „nowelizacja domyślnie
   otwiera się na wersji w bazie" jest decyzją UI, wykonaną jednym jawnym zapytaniem, a nie zachowaniem
   API. Pułapka warta zapamiętania, jeśli ktoś kiedyś odpyta ten endpoint spoza tego komponentu:
   `GET .../draft-diff?baseline=target` na szkicu, który NIE jest nowelizacją, **nie zwraca błędu** —
   kontroler cicho liczy porównanie względem `original` (bo gałąź `match` wymaga `target->isShadow()`),
   a mimo to pole `baseline` w odpowiedzi nadal echuje `"target"`. Frontend nigdy na to nie trafia (bo
   poprawnie gate'uje opcję), ale kontrakt trzeba było zapisać dokładnie taki, jaki jest — patrz
   `docs/backend/knowledge-api.md` → „Draft diff response".
4. **Badge „Baseline nieaktualny" (§24.7) NIE jest wynikiem porównania po stronie klienta
   `current_revision_id` targetu — to gotowe, serwerowe pole `target_revision_stale`, czytane przy
   KAŻDYM fetchu/refetchu sesji.** §24.7 zakładała, że „kreator porównuje `current_revision_id` targetu
   (znany z chwili generacji) z aktualnym przy każdym settle/refetch" — czyli logikę PO STRONIE
   KLIENTA. Zbudowany kontrakt jest prostszy i bezpieczniejszy: `KnowledgeEntryResource` niesie
   `target_revision_stale` (bool, obliczone przez `KnowledgeEntry::targetRevisionIsStale()`) na każdym
   szkicu-nowelizacji, a `GET .../draft-diff` niesie to samo pole na każdej odpowiedzi (nie tylko dla
   `baseline=target`). Klient (`KnowledgeDraftDiffPanel.vue`, pole `targetStale`) czyta je wprost z obu
   miejsc — nigdzie w kodzie nie ma porównania rewizji po stronie frontendu. Zweryfikowane w
   `Http/Resources/KnowledgeEntryResource.php` i `KnowledgeEntryRevisionController::draftDiff()`.
5. **K4 rozstrzygnięte na „nie": chip szacunku kosztu z §24.2 NIE został zbudowany, bo serwer nigdy
   nie zwraca liczby.** `compose-availability` i każda odpowiedź sesji niosą wyłącznie STAN budżetu
   (`cost_used`/`cost_cap`/`warn_reached`/`period.resets_at`) — nigdy szacunek pojedynczej operacji.
   `KnowledgeComposeSourceForm.vue` dokumentuje to wprost w komentarzu modułu: „a made-up number is
   worse than no number" — renderuje się wyłącznie chip STANU budżetu (`Badge` ostrzegawczy, tylko gdy
   `warn_reached`), nigdy `knowledge.compose.estimate`. Ten klucz i18n (§25.6, `estimate` w tabeli
   `knowledge.compose.*`) zostaje w katalogach jako nieużywany — nie jest to defekt do naprawienia w
   tym batchu, tylko honest odzwierciedlenie tego, czego backend nie oferuje (patrz ADR-0046, sekcja
   Consequences).
6. **Miernik budżetu (`SessionBudgetChip`/`AiBudgetBanner`, DC9/§24.8) odświeża się PO KAŻDEJ
   operacji, która wydaje budżet — jawnie WŁĄCZAJĄC `expand-context`, nie tylko generację i
   poprawkę.** Zweryfikowane w `KnowledgeComposeView.vue`: `waitFor()` (generacja + refine) i
   `onExpandContext()` obie wołają `aiUsage.fetchAiUsage()` po sukcesie. §24.8 mówiła ogólnie „po
   każdej operacji, która wydała budżet (generacja i każda poprawka)" bez wymieniania `expand-context`
   z nazwy — zbudowany kod jest **szerszy niż litera specyfikacji, zgodnie z jej duchem**
   (expand-context też wydaje jedno metrowane osadzanie), więc to potwierdzenie, nie odstępstwo.
7. **`expand-context` nie ma modala potwierdzenia — potwierdzenie jest INLINE, przycisk się wyłącza do
   następnej poprawki, a powtórka jest zamykana serwerowym 422.** `KnowledgeDraftRelationsPanel.vue`:
   brak `useConfirm()` przed kliknięciem „Poszerz kontekst" — klik od razu woła akcję (koszt jest już
   zakomunikowany `Text variant="caption"` pod przyciskiem, `knowledge.compose.expandContextHint`,
   PRZED kliknięciem, nie w osobnym dialogu). Po sukcesie przycisk zamienia się na `disabled` + ikonę
   `check-circle` + `knowledge.compose.expandContextReady` — stan czytany z
   `session.context_expanded_at` (serwerowe pole, patrz punkt 4/ADR-0046 D7), nie z lokalnego
   flagowania w komponencie, więc drugi reviewer patrzący na tę samą sesję widzi ten sam wyłączony
   przycisk bez odświeżania strony. Powtórne kliknięcie w oknie wyścigu (np. dwie karty tej samej
   sesji) kończy się serwerowym `422 knowledge_context_already_expanded`, obsłużonym jako
   sukces-z-inną-treścią (`alreadyExpanded()` w `KnowledgeComposeView.vue`: refetch sesji + toast
   informacyjny, NIE czerwony toast błędu) — zgodnie z ADR-0046 D7 „to nie jest ślepy zaułek".

**Rozbieżność znaleziona przy weryfikacji, poza listą wytycznych do sprawdzenia, dopisana bo dotyczy
tej samej rodziny plików:** `KnowledgeComposeView.vue`, `KnowledgeDraftRelationsPanel.vue` i
`pages/knowledge/types.ts` niosą komentarz modułu/pola stwierdzający, że nowelizacje (`amended_by`,
trzeci baseline diffa, przepływ 409+rebase) są „jeszcze nie tu, to B15b" — a kod W TYCH SAMYCH
PLIKACH implementuje wszystkie trzy (patrz punkty 2–4 powyżej). Komentarze pochodzą z etapu B15a i nie
zostały odświeżone po dopisaniu B15b. Nie jest to rozbieżność w zachowaniu — wyłącznie w komentarzu
źródłowym, który wprowadza w błąd czytającego kod. Zostawione bez poprawki w tym batchu (dokumentacja
nie modyfikuje kodu); patrz `docs/decisions/ADR-0046-knowledge-ai-composer.md` → Consequences.

---

## 27. Wiki-Graf — typowane relacje semantyczne

> **Etap G6 (specyfikacja UX).** Agent przy kompozycji proponuje nie tylko wpisy, ale też
> **relacje między encjami**: typ ze słownika + opis słowny + właściwości + ważność czasowa.
> To piąta warstwa grafu i **pierwsza, w której krawędź niesie znaczenie zdefiniowane przez człowieka**,
> a nie wyprowadzone z tekstu.
>
> **Backend powstaje równolegle.** Każdy kształt drutu poniżej jest **założeniem UI**, nie kontraktem —
> zebrane w §27.12. `pages/knowledge/types.ts` dla relacji powstaje dopiero z odpowiedzi backendu.
> Ta sama dyscyplina, która w §25.6/§26 opłaciła się przy kreatorze (K1 i K2 rozjechały się z
> założeniem dokładnie w nazwach pól).

### 27.1 Model pojęciowy w UI

| PL (UI) | EN (kod) | Co to jest |
| --- | --- | --- |
| Relacja | relation | Twierdzenie o świecie: **podmiot** → **typ** → **dopełnienie**, z opisem, właściwościami i ważnością czasową. |
| Typ relacji | predicate | Jedna z **15** pozycji zamkniętego słownika (§27.11). |
| Typ encji | entity type | Jedna z **7** kategorii wpisu (+ „nieokreślony"). |
| Ważność | validity | `od` / `do`. Relacja bez `do` jest **aktywna**; z `do` w przeszłości — **historyczna**. |
| Zakończenie | end | Ustawienie daty `do`. **Fakt był prawdziwy i przestał być.** |
| Zastąpienie | supersede | Zakończenie jednej relacji i utworzenie następczyni w jednym kroku. |
| Wycofanie | retract | **Twarde usunięcie** — „to nigdy nie było prawdą". **Wyłącznie człowiek, wyłącznie w UI.** |
| Zmiany w grafie | graph updates | Zestaw operacji na relacjach zaproponowany przez agenta w jednej sesji kreatora. |

**Zasada nadrzędna (decyzja właściciela):** LLM **nigdy nie usuwa**. Może zaproponować `add`,
`end`, `supersede`. `retract` nie istnieje jako operacja agenta — nie ma jej w przeglądzie,
nie ma jej w kontrakcie, nie da się jej wywołać inaczej niż ręcznie z panelu relacji.
To nie jest ograniczenie techniczne, tylko **model odpowiedzialności**: maszyna aktualizuje
stan świata, człowiek orzeka, że zapis był błędem.

### 27.2 Decyzje projektowe

**G1 — Relacja jest ZDANIEM. Krotka nigdy nie jest widoczna.**
Użytkownik nie czyta `(łukasz-barszcz, member_of, acme)`. Kanoniczny render (§27.3) to linia
z pogrubionymi encjami, typem jako `Badge` i strzałką kierunku, a **dostępna nazwa** całej linii
to pełne zdanie. Surowy identyfikator predykatu (`member_of`) **nie pojawia się w UI nigdzie** —
poza ewentualnym `title` do debugowania.

**G2 — Grupowanie przeglądu: per ENCJA, nie per operacja.**
Przegląd relacji jest **sprawdzaniem faktów o kimś/czymś**. „Co będzie prawdą o Łukaszu Barszczu"
to jedno spojrzenie; „wszystkie dodania w sesji" wymusza skakanie między bytami przy każdym wierszu.
Wewnątrz grupy kolejność operacji: **`add` → `supersede` → `end`** (najpierw co przybywa, potem co
się zmienia, na końcu co odchodzi).
*Odrzucone:* grupowanie per operacja (wygodne dla audytu, wrogie dla weryfikacji faktów).
*Odrzucone:* wpięcie relacji do kart szkiców — relacja często łączy **dwie już istniejące** encje
i nie należy do żadnego szkica; rozdzielenie części relacji do kart, a części poza nie, rozbiłoby
spójność akceptacji zbiorczej.

**G3 — `graph_updates` to SEKCJA RÓWNORZĘDNA tablicy szkiców, nie jej część.**
Kolejność na ekranie kreatora: `[tablica szkiców]` → `[Zmiany w grafie]` → `[pasek poprawki]`.
Karta szkica dostaje **cichy chip** „Relacje: {count}" (`Badge neutral·subtle`, `Button variant="link"`),
który przewija do grupy tej encji i ją podświetla (`is-jump-target`, 2 s) — powiązanie bez duplikacji.

**G4 — Ziarnistość akceptacji **musi** odwzorować to, co już działa dla szkiców.**
Dziś: `Checkbox` per szkic + „Akceptuj zaznaczone: {count}" + `useConfirm`. Relacje dostają
**dokładnie ten sam** wzorzec: `Checkbox` per relacja, „zaznacz wszystkie w tej encji" w nagłówku
grupy, jeden przycisk zbiorczy dla całej sekcji. Żadnego „zaakceptuj wszystkie relacje jednym
kliknięciem bez zaznaczania" — bramka przeglądu jest wiążąca dla wszystkiego (decyzja właściciela).

**G5 — Zależność relacji od szkicu jest widoczna PRZED akceptacją, nie odkrywana przy 422.**
Relacja, której końcem jest **jeszcze niezaakceptowany szkic**, nie da się zapisać. Wiersz dostaje
`Badge variant="neutral" tone="subtle" icon="clock"` **„Czeka na wpis «{tytuł}»"**, jego `Checkbox`
jest `aria-disabled` z `Tooltip` podającym powód, a akceptacja tego szkica **odblokowuje wiersz na
żywo**. Akceptacja zbiorcza obejmująca oba porządkuje kolejność sama (najpierw wpisy, potem relacje)
i mówi o tym w treści `useConfirm`.

**G6 — Sekcja „czego agent NIE zapisał" jest częścią przeglądu, nie przypisem.**
Zwinięty `AccordionItem` z licznikiem, **zawsze obecny gdy `skipped.length > 0`**. Każdy wiersz:
wyszarzone zdanie relacji + `Badge` powodu + jedno zdanie wyjaśnienia. Bez tego użytkownik nie ma
jak odróżnić „agent nic tam nie znalazł" od „agent znalazł, ale odrzucił" — a to jest różnica
między zaufaniem a jego brakiem.

**G7 — Niejednoznaczna wzmianka rozstrzygana JEST W WIERSZU, nie w osobnej sekcji.**
Osobna sekcja „do rozstrzygnięcia" znaczy: przeczytaj relację, przejdź gdzie indziej, wybierz
osobę, wróć, przeczytaj jeszcze raz. Zamiast tego niejednoznaczny koniec renderuje się **w miejscu
encji** jako `Select` kandydatów (§27.6). Rozstrzygnięcie **stosuje się do całej sesji** — ten sam
uchwyt („Łukasz") w pięciu relacjach rozwiązuje się raz, z notą „Dotyczy też relacji: {count}".

**G8 — Piąta warstwa grafu jest jedyną z ETYKIETĄ na krawędzi, i to jest uzasadnione.**
Cztery istniejące rodzaje mają po jednym znaczeniu (`wikilink` = „linkuje", `similarity` = „podobne",
`mention` = „wymienia", `manual` = „ktoś połączył") — wzór linii wystarcza. Relacja ma **15 różnych
znaczeń**; bez etykiety krawędź niesie tylko „tu jest jakaś relacja", co jest bezużyteczne.
**Reguła modułu, którą to ustanawia:** *wzór linii niesie RODZAJ warstwy, kolor niesie STAN,
etykieta niesie ZNACZENIE — i tylko warstwa relacji ma znaczenie do przekazania.*

**G9 — Typ encji NIE jest kodowany na węźle grafu.**
Siedmiu kategorii nie da się rzetelnie rozróżnić ani kształtem (kształty są już zajęte przez
`ghost` / `is_draft` / `amended_by`), ani 12-pikselowym glifem w kółku o promieniu 6–18 px.
Kodowanie ich byłoby dekoracją, która **kłamie**, że da się je odczytać. Typ encji żyje jako
**tekstowy `Badge`** w liście sąsiadów, w panelu bocznym, w podglądzie hover i w nagłówku wpisu.
*Odłożone (nie MVP):* opcjonalna nakładka „Koloruj według typu" — **wyłączona domyślnie**,
z własną legendą; opt-in i olegendowana nakładka jest uczciwa, domyślne kolorowanie nie.

**G10 — `entity_type = null` to stan normalny, nie brak do naprawienia.**
**Wszystkie istniejące wpisy** mają `null`. Dlatego: w nagłówku artykułu **nie renderujemy nic**
(brak badge'a to nie błąd), w grafie węzeł wygląda **dokładnie jak dziś** (zero regresji wizualnej
dla istniejących baz), a jedyne miejsce, gdzie `null` jest nazwany, to szyna/ustawienia wpisu —
neutralnym „Nieokreślony" z `Select`em obok. **Nigdy** `Badge variant="warning"`, nigdy „brak typu",
nigdy ikona ostrzeżenia.

**G11 — Ostrzeżenia doradcze są DORADCZE i tak brzmią.**
„Para typów nietypowa dla tej relacji" (`works_on` z dopełnieniem typu `person`) →
`Badge variant="warning" tone="subtle" icon="alert-triangle"` + `Tooltip` z wyjaśnieniem.
**Nigdy nie blokuje akceptacji.** Copy mówi „nietypowe", nie „błędne" — słownik typów jest
heurystyką redakcyjną, a nie ontologią z gwarancją.

**G12 — Druga faza kosztuje, i mówimy to SŁOWEM, nie liczbą.**
Wykrywanie relacji to dodatkowe wywołanie AI. Wzorzec jest już w module i został zweryfikowany
w B15b (§26 pkt 5 i 7): **serwer nie zwraca szacunku kosztu**, więc chipa z kwotą nie ma.
Zamiast tego — dokładnie jak `expand-context`: `Text variant="caption"` pod przyciskiem
wyjaśniający, że to osobne, płatne przejście, przycisk wyłączający się po wykonaniu w stan
`check-circle` + „już wykonane", stan czytany **z pola sesji**, nie z lokalnej flagi.

**G13 — `retract` ma najostrzejszy dialog w całym module i UCZY różnicy.**
`ConfirmDialog variant="danger"`, którego treść nie tylko ostrzega, ale **kieruje do właściwej
operacji**: „Jeśli ten fakt PRZESTAŁ być prawdziwy, użyj «Zakończ» — zachowasz historię."
To jedyne miejsce, w którym użytkownik uczy się rozróżnienia end/retract, więc copy jest tu
funkcją produktu, a nie ozdobą.

### 27.3 Kanoniczny render relacji

Jeden komponent, **`pages/knowledge/relations/KnowledgeRelationSentence.vue`**, używany w KAŻDYM
miejscu (przegląd, szyna, lista sąsiadów, panel boczny grafu, modal potwierdzenia). Jedna prawda
o tym, jak wygląda zdanie relacji.

**Warstwa wizualna** (kompaktowa, kierunek widoczny):

```
**Łukasz Barszcz**  [należy do →]  **Acme**   ·  od 01.2024
└ ikona typu encji   Badge primary·subtle      └ Text variant="caption"
```

**Warstwa dostępna** (`aria-label` całej linii — pełne zdanie, bez strzałek i nawiasów):

> „Łukasz Barszcz należy do Acme, od stycznia 2024."

To rozdzielenie jest istotą G1: wzrok dostaje gęstą, skanowalną linię, czytnik ekranu — zdanie.
Strzałka i `Badge` są `aria-hidden`; nazwa dostępna powstaje z tego samego szablonu i18n,
co tekst widoczny.

**Warianty operacji w przeglądzie** (prefiks + tryb):

| Operacja | Render |
| --- | --- |
| `add` | **Doda:** *Łukasz Barszcz* [należy do →] *Acme* · od 01.2024 |
| `end` | **Zakończy:** *Łukasz Barszcz* [należy do →] *Acme* · **z dniem 01.07.2026** · Powód: {reason} |
| `supersede` | **Zastąpi:** *Łukasz Barszcz* [pracuje nad →] ~~*Projekt A*~~ → *Projekt B* · od 01.07.2026 |

- `end` i `supersede` renderują istniejącą relację **przygaszoną** (`text-next-muted-foreground`)
  z datą końca w pełnym kontraście — oko ma trafić w to, co się zmienia.
- `supersede` pokazuje **obie** strony w jednej linii; skreślenie starej wartości jest
  wzmocnione słowem „Zastąpi", więc nie polega na samym przekreśleniu.
- Opis słowny agenta renderuje się **pod** zdaniem jako `Text variant="ui"` z `clampLines={2}`
  i „więcej"/„mniej". Opis jest uzasadnieniem, nie tytułem — nie może wypychać zdania.
- Właściwości (`properties`) → rząd `Badge neutral·subtle` `klucz: wartość`, maks. 4 widoczne
  + `ChipOverflow` `+N` (wzorzec współdzielony, `ui/forms/ChipOverflow.vue`).

**Kierunek w liście sąsiadów** używa **odwrotności** predykatu, nigdy strzałki wstecz:
patrząc na *Acme*, wiersz brzmi „**Acme** [ma członka →] **Łukasz Barszcz**".
Dlatego każdy z 15 typów ma w i18n **dwie** etykiety (§27.11).

### 27.4 Przegląd `graph_updates` w kreatorze

**Pliki (nowe):**

```
pages/knowledge/
├── compose/
│   └── KnowledgeGraphUpdatesPanel.vue      sekcja „Zmiany w grafie" (peer tablicy szkiców)
└── relations/
    ├── KnowledgeRelationSentence.vue       kanoniczny render (§27.3)
    ├── KnowledgeRelationRow.vue            wiersz przeglądu: zdanie + checkbox + akcje + ostrzeżenia
    ├── KnowledgeEntityChooser.vue          rozstrzyganie niejednoznacznej wzmianki (§27.6)
    ├── KnowledgeRelationEditorModal.vue    ręczne tworzenie / edycja / awans (§27.8)
    └── relationLabels.ts                   predykat + kierunek → etykieta; czysty, testowalny
```

> **Uwaga na kolizję nazw:** `KnowledgeDraftRelationsPanel.vue` **już istnieje** i pokazuje
> *podgląd grafu szkiców* (`GET …/draft-sessions/{session}/relations`, §26 pkt 2). To **co innego**
> niż typowane relacje semantyczne. Nowy komponent nazywa się `KnowledgeGraphUpdatesPanel.vue`
> i nie wolno go wcielać do tamtego.

**Układ sekcji:**

```
── Zmiany w grafie ──────────────────────────────────────────────────────────
[Badge: Relacje: 12]  [Checkbox zaznacz wszystkie]   [Button Akceptuj zaznaczone: N]

▸ Łukasz Barszcz        [Badge: Osoba]        [Checkbox grupy]     Relacje: 3
    ☐ Doda: **Łukasz Barszcz** [należy do →] **Acme** · od 01.2024
        „Źródło mówi, że dołączył w styczniu 2024."            [Edytuj] [Odrzuć]
    ☐ Doda: **Łukasz Barszcz** [pracuje nad →] **Projekt Orion**   ⚠ nietypowa para typów
    ☐ Zakończy: **Łukasz Barszcz** [należy do →] **Poprzednia sp. z o.o.** · z dniem 12.2023

▸ Acme                  [Badge: Organizacja]  [Checkbox grupy]     Relacje: 2
    …

▾ Nie zapisano: 4                                              ← zwinięte, zawsze gdy > 0
── pasek poprawki (istniejący KnowledgeRefineBar) ───────────────────────────
```

- Nagłówek grupy = **encja**: nazwa + `Badge` typu encji + licznik + `Checkbox` grupy
  (trójstan: `indeterminate` gdy część zaznaczona). Encja będąca **szkicem** dostaje dodatkowo
  `Badge variant="modified"` „Szkic" — od razu widać, że relacja czeka na wpis (G5).
- Grupa jest `AccordionItem`, **domyślnie rozwinięta** (to jest treść do przeczytania, nie archiwum).
- Wiersz relacji: `KnowledgeRelationRow.vue` — `Checkbox` + `KnowledgeRelationSentence` + opis
  + rząd właściwości + ostrzeżenia + akcje.

**Akcje w wierszu** (zwarte, wzorzec z §5.4): `[Edytuj]` (`pencil`, otwiera
`KnowledgeRelationEditorModal` z wypełnionymi polami — poprawka bez kosztu AI) ·
`[Odrzuć]` (`x`, `icon-xs`) → wiersz znika, **toast z „Cofnij"** (odwracalne, niska stawka —
zgodnie z D13). Każdy przycisk ikonowy z `aria-label` zawierającym pełne zdanie relacji.

**Akceptacja zbiorcza:** `knowledge.relations.acceptSelected` = „Akceptuj zaznaczone: {count}";
`useConfirm` z treścią wymieniającą liczbę relacji **oraz** liczbę wpisów, które zostaną
zaakceptowane po drodze, jeśli zaznaczenie obejmuje relacje zależne od szkiców (G5).
Wykonanie sekwencyjne z `ui/feedback/Progress.vue` (determinate); błąd zatrzymuje resztę
i zostawia powód **na wierszu**, nie w toaście, który zniknie.

**Sekcja „Nie zapisano"** — `AccordionItem`, zwinięty, tytuł `knowledge.relations.skipped` =
„Nie zapisano: {count}". Wiersz: zdanie w `text-next-muted-foreground` + `Badge` powodu:

| Powód | `Badge` | Wyjaśnienie (jedno zdanie) |
| --- | --- | --- |
| `unknown_predicate` | neutral · `x-circle` | „Typ relacji spoza słownika — agent zaproponował «{raw}»." |
| `unresolved_handle` | warning · `help-circle` | „Nie udało się ustalić, o kogo chodzi." → **wiersz oferuje wybór** (§27.6) |
| `duplicate` | neutral · `copy` | „Taka relacja już istnieje." + `Button variant="link"` „Pokaż istniejącą" |
| `self_reference` | neutral · `x-circle` | „Relacja wskazywała sam na siebie." |
| `out_of_base` | neutral · `x-circle` | „Jedna ze stron nie należy do tej bazy." |

Pusto (`skipped.length === 0`) → sekcji **nie ma wcale** (nie pusty stan — brak pominięć nie jest
informacją wartą miejsca).

**Sekcja pusta w całości** (agent nie zaproponował żadnych relacji) → `EmptyState size="sm"
icon="network"`, „Agent nie zaproponował żadnych relacji", opis: „To normalne przy tekście bez
wyraźnych powiązań między osobami, organizacjami czy wydarzeniami." **Bez** akcji naprawczej —
to nie jest usterka.

### 27.5 Faza wykrywania relacji — koszt i uruchomienie (G12)

Wykrywanie relacji jest **drugim przejściem** agenta. Dwa scenariusze — do rozstrzygnięcia
kontraktem (§27.12 / GK1):

- **jeśli jest częścią generacji** — nic nie trzeba uruchamiać, a nota o koszcie należy do
  pola źródłowego (§24.2);
- **jeśli jest osobnym wywołaniem** — dokładnie wzorzec `expand-context` (§26 pkt 7):

```
[Button variant="outline" leading-icon="network"]  Wykryj relacje
[Text variant="caption"]  Osobne przejście agenta — zużywa budżet AI. Można je wykonać raz na rundę.
```

Po wykonaniu przycisk zamienia się w `Text` z `check-circle` +
`knowledge.relations.detectDone` = „Relacje wykryte w tej rundzie". Stan czytany
**z pola sesji** (jak `context_expanded_at`), nie z lokalnej flagi — drugi recenzent patrzący
na tę samą sesję widzi to samo bez odświeżania. Powtórne kliknięcie w oknie wyścigu →
serwerowe 422 obsłużone jako **sukces-z-inną-treścią** (refetch + toast informacyjny,
nie czerwony), zgodnie z precedensem ADR-0046 D7.

Meter zużycia (`aiUsage.fetchAiUsage()`) odświeżany po tej operacji — jest metrowana.

### 27.6 Niejednoznaczne wzmianki — `KnowledgeEntityChooser.vue` (G7)

Agent zgłasza: „«Łukasz» — dwie osoby o tym imieniu". Wiersz relacji renderuje wtedy **w miejscu
encji** nie tekst, lecz wybór:

```
Doda: [ ▾ «Łukasz» — wskaż osobę ]  [należy do →]  **Acme** · od 01.2024
      └ Select: Łukasz Barszcz (Osoba) — „…kierownik projektu Orion…"
                Łukasz Nowak  (Osoba) — „…współpracownik zewnętrzny…"
                ─────────────
                Żaden z nich
```

- `ui/forms/Select.vue` ze slotem `#option`: tytuł + `Badge` typu encji + **fragment
  odróżniający** (`Text variant="caption"`, `clampLines=1`) — bez niego dwa identyczne imiona
  są nieodróżnialne, a wybór to zgadywanie.
- **Rozstrzygnięcie stosuje się do całej sesji.** Pod `Select`em `Text variant="caption"`:
  `knowledge.relations.ambiguityScope` = „Dotyczy też relacji: {count}" (gdy > 0).
- **„Żaden z nich"** → wiersz wraca do sekcji „Nie zapisano" z powodem `unresolved_handle`,
  bez tworzenia czegokolwiek. Agent **nie tworzy nowej encji z uchwytu** — to byłoby zapisanie
  bytu, którego nikt nie zatwierdził (łamie bramkę przeglądu).
- Dopóki wybór nie zapadł, `Checkbox` wiersza jest `aria-disabled` z `Tooltip`
  „Najpierw wskaż, o kogo chodzi".
- Wybór jest **odwracalny** do momentu akceptacji (ponowne otwarcie `Select`a).

### 27.7 Warstwa relacji w grafie (G8)

**Rozszerzenie istniejącego kodu, nie nowy graf.** Seam jest czysty i przetestowany — ale
**tylko część jest pilnowana przez TypeScript**, więc poniżej pełna lista, a nie „dodaj rodzaj".

| Plik | Zmiana | Pilnuje TS? |
| --- | --- | --- |
| `pages/knowledge/types.ts` | `KnowledgeLinkSource` + `'relation'`; `KNOWLEDGE_LINK_SOURCES` + wpis | — |
| `graph/knowledgeGraphLayout.ts` | `GRAPH_EDGE_KINDS` + `'relation'` **na początku** (najsilniejsza semantyka czyta się pierwsza) | — |
| ” | `KIND_RANK.relation = 0` + przesunięcie reszty | **tak** (`Record<GraphEdgeKind, number>`) |
| ” | ramię w `edgeWidth()` → `2.5` (**szerokość pochodzi z layoutu, nie z CSS**) | nie |
| ” | `directed` w `assemble()` — dziś `kind === 'wikilink' \|\| kind === 'mention'`; dla relacji **zależy od predykatu** (symetryczne bez grota) | nie |
| `graph/graphNeighbours.ts` | `GROUP_RANK.relation` | **tak** (`Record<GraphNeighbourGroup, number>`) |
| ” | pole predykatu na `GraphNeighbour` + wypełnienie w `rowFor()` | — |
| ” | **zniesienie zwijania „jeden wiersz na sąsiada"** dla relacji — patrz niżej | — |
| `graph/KnowledgeGraphCanvas.vue` | reguła CSS `.next-kg-edge.is-relation` + etykieta na krawędzi | nie |
| `graph/KnowledgeGraphLegend.vue` | reguła CSS **drugi raz** (legenda ma własną kopię stylów) + ramię `lineEnd()` | nie |
| `KnowledgeGraphView.vue` | piąty chip filtra + przełącznik „Pokaż historyczne" | nie |
| i18n (oba katalogi) | `knowledge.graph.edge.relation` | nie |

> **Pułapka:** `GRAPH_NEIGHBOUR_GROUPS = ['entry', ...GRAPH_EDGE_KINDS]` i wiersz legendy powstają
> **automatycznie** z `GRAPH_EDGE_KINDS`, więc rodzaj „pojawi się" w UI natychmiast — ale **bez
> stylu linii i bez etykiety i18n**, bo tych TypeScript nie pilnuje. Efektem połowicznej zmiany jest
> krawędź nieodróżnialna od `wikilink` z napisem `knowledge.graph.edge.relation` zamiast nazwy.
> Lista wyżej jest kompletna; odhaczyć wszystkie wiersze.

**Predykat musi dojechać do listy sąsiadów — to łańcuch czterech plików.**
`GraphNeighbour` **nie ma dziś pola na czasownik**, więc zdanie z §27.3 nie ma z czego powstać:
`types.ts` (`KnowledgeGraphEdge.predicate`) → `knowledgeGraphLayout.ts`
(`GraphEdgePlacement.predicate`, wypełniane w `assemble()`) → `graphNeighbours.ts`
(`GraphNeighbour.predicate`, wypełniane w `rowFor()`) → `KnowledgeGraphNeighbourList.vue`.
Zaplanować jako jedną zmianę, nie cztery osobne.

> **KRYTYCZNE — `neighboursOf()` zwija dziś wiele krawędzi do JEDNEGO wiersza na sąsiada**
> („najsilniejsza relacja wygrywa", `betterRelation`). Dla czterech istniejących warstw to jest
> poprawne: „jest podobny **i** wymieniony" to wciąż jedno sąsiedztwo. **Dla relacji typowanych to
> gubi treść** — *Łukasz* może jednocześnie `works_on` i `created` *Projekt Orion*, a zwinięcie
> pokaże tylko jedną z tych relacji i użytkownik nigdy się nie dowie o drugiej.
> **Decyzja: dla `relation` zwijania NIE MA — jeden wiersz na relację.** Sąsiad z trzema relacjami
> daje trzy wiersze w grupie „Relacje" (i nadal co najwyżej jeden wiersz w grupach miękkich).
> Wiersze tego samego sąsiada sortują się obok siebie: aktywne przed historycznymi, potem po `od`
> malejąco. To jest jedyne odstępstwo od reguły „jeden wiersz na sąsiada" i musi być
> **przypięte testem**, bo inaczej pierwszy refaktor `betterRelation` je cicho cofnie.

**Styl krawędzi** — piąty wzór, jednoznacznie różny od czterech istniejących:

| Rodzaj | Wzór | Etykieta |
| --- | --- | --- |
| `relation` (aktywna) | **gruba ciągła** `stroke-width: 2.5` + **grot strzałki** | **tak** — etykieta typu |
| `relation` (historyczna) | gruba ciągła + grot, `stroke: --color-next-muted-foreground`, `opacity: .5` | tak — **z sufiksem „(do {rok})"** |
| `wikilink` | ciągła 1.5 + grot | nie |
| `manual` | ciągła 2 + kwadrat w połowie | nie |
| `mention` | kreska-kropka `6 3 1 3` | nie |
| `similarity` | kropkowana `2 3` | nie |
| `ghost` | przerywana `5 4` do pustego okręgu | nie |

`relation` i `manual` są oba „ciągłe" — rozróżnia je **grubość + grot + obecność etykiety**,
a w praktyce przede wszystkim **etykieta**, której `manual` nie ma nigdy. Legenda mówi to wprost.

**Kolor niesie STAN, nie rodzaj** (reguła G8): aktywna = `--color-next-fg`;
historyczna = `--color-next-muted-foreground` + `opacity .5`; zaznaczona/najechana =
`--color-next-primary` (jak wszystkie warstwy). Ponieważ historyczność jest też **w etykiecie**
(„(do 2026)"), stan nigdy nie zależy od samego koloru.

**Etykieta na krawędzi:**
- `<text>` w **połowie** krawędzi, **zawsze poziomo** (obrót wzdłuż linii psuje czytelność przy
  stromych kątach), z podkładem `<rect>` w `--color-next-bg` i `rx="2"`.
- `font-size: var(--text-next-2xs)`, `fill: var(--color-next-fg)`, skracana do **16 znaków** + `…`.
- **Reguła zagęszczenia:** etykiety renderują się, gdy narysowanych krawędzi `relation` jest
  **≤ 20**; powyżej — tylko dla krawędzi **najechanej lub zaznaczonej**. Bez tego przy capie
  60 węzłów etykiety zlewają się w szum. Stan „etykiety ukryte" komunikuje `Text variant="caption"`
  pod płótnem: `knowledge.relations.labelsHidden` = „Za dużo relacji, żeby pokazać wszystkie
  podpisy — najedź na krawędź albo skorzystaj z listy."
- Etykieta jest **wewnątrz `aria-hidden` SVG**, więc nie zastępuje listy — patrz §27.10.

**Chip filtra:** `knowledge.graph.edge.relation` = „Relacje", z licznikiem jak pozostałe,
**domyślnie włączony**.

**Przełącznik „Pokaż historyczne":** `ui/forms/Switch.vue` `size="sm"`, **domyślnie wyłączony**,
obok chipów. Etykieta `knowledge.relations.showHistorical` = „Pokaż historyczne".
Gdy wyłączony, a historyczne istnieją → `Text variant="caption"`
„Ukryto relacje zakończone: {count}" (nigdy cichego ucięcia — reguła R10).

### 27.8 Panel „Relacje" w szynie czytnika (G7/owner)

**Pozycja: NAD „Podobne" i „Wzmianki"** — panel per-wpis jest pierwszym kanałem konsumpcji
relacji, graf jest pomocniczy. Kolejność paneli w `reader/KnowledgeEntryRail.vue`:

```
Metadane · ► RELACJE ◄ · Podobne · Linkuje do · Linkowane z · Czerwone linki · Pochodzenie · Historia
```

**Zweryfikowane w kodzie — dwie rzeczy do poprawienia względem pierwszego odruchu:**

1. **`ui/disclosure/AccordionItem.vue` NIE MA propa `count` ani `badge`** (props to dokładnie
   `value`, `title`, `icon`, `disabled`). Licznik w nagłówku wymaga slotu `#header` zamiast propa
   `title` — **żaden panel szyny dziś tego nie robi**. Precedens w pliku jest inny: licznik żyje
   `Badge`iem **w ciele** panelu (panel „Czerwone linki": `Badge danger·subtle icon="unlink"` z
   `knowledge.ghosts.count`). **Idziemy za precedensem** — `Badge` w ciele, nie w nagłówku. Jeden
   wyjątek wprowadzony dla jednego panelu byłby niespójnością widoczną na pierwszy rzut oka.
2. **Kolejność wbrew konwencji pliku — świadomie.** `KnowledgeEntryRail.vue` grupuje dziś panele
   „od wyprowadzonych do dosłownych" (`similar` → `mentions` → `links-out` → `links-in`), więc
   naturalne miejsce nowego panelu byłoby **po** `mentions`. Decyzja właściciela stawia Relacje
   **przed** `similar` i jest uzasadniona merytorycznie: `similar`/`mentions` to **domysły maszyny**,
   a relacja to **fakt zatwierdzony przez człowieka**. Warstwa zweryfikowana ma pierwszeństwo przed
   spekulacyjną — czytelnik ma najpierw zobaczyć, co wiadomo, a dopiero potem, co się podejrzewa.
   Kolejność `metadata → RELACJE → similar → mentions → …` jest wiążąca.

**Rozszerzenie emitów szyny.** Szyna emituje dziś `dismiss-link(linkId, kind)` /
`undo-link(linkId, kind)` z `kind: 'similarity' | 'mention'`. Relacje wnoszą **inne** czasowniki
(`end`, `retract`, `edit`) — **nie wciskać ich w `dismiss-link`**. Nowe, osobne emity:
`end-relation(id)`, `retract-relation(id)`, `edit-relation(id)`, `create-relation()`.
Odrzucenie i wycofanie to różne operacje o różnej odwracalności; wspólny emit zatarłby tę granicę
dokładnie tam, gdzie G13 stara się ją wyostrzyć.

- Tytuł `knowledge.panels.relations` = „Relacje", ikona `network` (tej samej używa
  `KnowledgeDraftBoard.vue` dla swojej sekcji powiązań — spójność między powierzchniami).
- Licznik: `Badge neutral·subtle` **w ciele** panelu, pierwszy element (patrz punkt 1 wyżej).
- **Domyślnie ROZWINIĘTY** (dołącza do `defaultOpen` obok `metadata`; przy istniejących czerwonych
  linkach lista to `['metadata', 'relations', 'ghosts']`) — to podstawowy kanał, nie dodatek.
- Panel renderuje się **zawsze**, także pusty (precedens `similar` / `links-out`): „ten wpis nie ma
  relacji" jest informacją, a znikający panel uczyłby, że relacji w module nie ma.
- Wiersz = `KnowledgeRelationSentence` w formie **odwróconej względem bieżącego wpisu**
  (patrząc na Acme: „ma członka → Łukasz Barszcz"), + daty, + `Badge` stanu:
  `Badge success·subtle` „Aktywna" / `Badge neutral·subtle icon="clock"` „Zakończona {rok}".
- **Grupowanie po typie relacji** gdy relacji > 8 (nagłówki `Text variant="caption"` sticky);
  poniżej — płaska lista posortowana: aktywne przed historycznymi, potem po dacie `od` malejąco.
- **Historyczne domyślnie ukryte**, za `Button variant="link"` „Pokaż zakończone: {count}".
- Pusto → `EmptyState size="sm" icon="network"`, „Ten wpis nie ma jeszcze relacji",
  `#action` `Button variant="outline" size="sm" leading-icon="plus"` **„Połącz z…"**.
  To jest **normalny stan**, nie brak do naprawienia — copy bez cienia ostrzeżenia.

**Akcje wiersza** (`icon-xs`, ujawniane na hover/`focus-within`, **zawsze w DOM**):

| Akcja | Ikona | Zachowanie |
| --- | --- | --- |
| Edytuj | `pencil` | `KnowledgeRelationEditorModal` w trybie edycji (opis, właściwości, daty; **typ i strony niezmienne** — zmiana strony to inna relacja) |
| Zakończ | `clock` | `Modal` z jednym polem `DatePicker` „Data zakończenia" (domyślnie dziś) + opcjonalny `TextInput` „Powód". **Nie** `ConfirmDialog` — to wprowadzenie danych, nie potwierdzenie |
| Usuń | `trash` (danger) | `ConfirmDialog` — patrz niżej. **Jedyna twarda operacja w całym module relacji** |

**Dialog usunięcia (G13)** — `useConfirm({ variant: 'danger' })`:

- tytuł: `knowledge.relations.retract.title` = **„Usunąć relację całkowicie?"**
- treść: **„Usuwasz zapis tak, jakby nigdy nie powstał — zniknie też z historii.
  Jeśli ten fakt PRZESTAŁ być prawdziwy, użyj «Zakończ» zamiast usuwania: relacja zostanie
  z datą końca, a historia pozostanie czytelna."**
- `confirmLabel`: „Usuń całkowicie"
- W dialogu, pod treścią, **skrót do właściwej operacji**: `Button variant="outline"`
  „Zamiast tego zakończ" → zamyka dialog i otwiera modal zakończenia.

### 27.9 Ręczne tworzenie i awans miękkiej krawędzi (G-owner pkt 5)

#### `KnowledgeRelationEditorModal.vue`

`Modal size="md"` (anatomia z §11.5: tytuł mówi co, treść co się stanie, przycisk jest czasownikiem).

| Pole | Kontrolka | Uwagi |
| --- | --- | --- |
| Podmiot | tekst (nieedytowalny) | zawsze bieżący wpis; przy awarii miękkiej krawędzi — źródło sugestii |
| Typ relacji | `ui/forms/Select.vue` | 15 pozycji, **pogrupowane** (`#header` grup): Przynależność · Działanie · Miejsce i czas · Struktura · Zależność. Slot `#option` = etykieta + `Text variant="caption"` z przykładem użycia |
| Dopełnienie | `ui/forms/Select.vue` async | wyszukiwanie po wpisach bazy (`#option`: tytuł + `Badge` typu encji); **tylko istniejące wpisy** — modal nie tworzy encji |
| Opis | `ui/forms/Textarea.vue` `autoGrow` | wymagany, ≤ 500; to jest uzasadnienie dla przyszłego czytelnika |
| Ważność | **pole złączone** `FormField` + `FieldShell :segmented` z dwoma `DatePicker` („od" / „do") | jedna etykieta, jedna linia stanu — bezpośrednie zastosowanie zasady upraszczania dwukolumnowych formularzy |
| Właściwości | repeater `klucz` / `wartość` | wzorzec `EntryListInput` / wierszy enum w `DescriptorSchemaBuilder.vue` |

**Podgląd na żywo** nad przyciskami: `KnowledgeRelationSentence` z aktualnych pól —
użytkownik widzi zdanie, które tworzy, zanim je zatwierdzi. To jest tańsze niż nauczenie
kogokolwiek, co znaczy `depends_on`.

Walidacja klienta (komunikaty jako **klucze i18n**, serwer ma pierwszeństwo):
typ wymagany · dopełnienie wymagane · dopełnienie ≠ podmiot · `do` ≥ `od` ·
ostrzeżenie doradcze o nietypowej parze typów (**nie** blokuje, `Alert variant="warning" size="sm"`).

#### Awans miękkiej krawędzi

Na wierszu `Podobne` (`reader/KnowledgeSimilarRow.vue`) **oraz** `Wzmianki`
(`reader/KnowledgeMentionRow.vue` — to **dwa osobne komponenty**, obie trzeba ruszyć)
dochodzi trzecia akcja:
`Button variant="ghost" size="icon-xs" icon="network"`, `aria-label`
`knowledge.relations.promote` = „Utrwal jako relację: {title}".

Otwiera `KnowledgeRelationEditorModal` z **wypełnionymi** podmiotem i dopełnieniem
(niezmienne — to właśnie ta para), do uzupełnienia zostaje typ + opis.
Po zapisie: **sugestia znika** (miękka krawędź zostaje odrzucona po stronie serwera, żeby
re-indeks jej nie zaproponował ponownie — GK5) i pojawia się relacja w panelu wyżej.
Toast: „Utrwalono jako relację" z akcją **„Pokaż"**.

To realizuje zasadę **„krawędź miękka awansuje do twardej wyłącznie przez człowieka"**:
maszyna sugeruje sąsiedztwo, człowiek nadaje mu znaczenie.

### 27.10 Dostępność

Warstwa relacji jest **najbardziej wrażliwa na regułę „graf nie jest jedynym nośnikiem"**,
bo jako jedyna niesie znaczenie w etykiecie narysowanej wewnątrz `aria-hidden` SVG.

**Obowiązkowe:**

- [ ] **Lista sąsiadów (`KnowledgeGraphNeighbourList.vue`) obejmuje relacje jako pierwszą grupę**
      (`role="group"`, `aria-label` „Relacje"), a każdy wiersz niesie **pełne zdanie** z §27.3 —
      typ **słowem**, kierunek **odwrotnością predykatu**, stan **słowem** („Aktywna" /
      „Zakończona 2026"). Bez tego etykieta krawędzi jest treścią dostępną wyłącznie wzrokowo.
- [ ] Model klawiatury bez zmian (jeden tab stop, `aria-activedescendant`, virtual focus —
      wzorzec `VariableBrowser`); nowa grupa wchodzi w istniejącą kolejność `KIND_RANK`.
- [ ] `KnowledgeRelationSentence` ma `aria-label` będące **zdaniem**; `Badge` typu i strzałka
      są `aria-hidden` (inaczej czytnik przeczyta „należy do strzałka w prawo").
- [ ] Data w zdaniu w `<time datetime>`; „od 01.2024" ma pełną datę w `datetime`.
- [ ] Ostrzeżenie doradcze: `Badge` + `Tooltip`, a **treść ostrzeżenia jest też w `aria-label`
      wiersza** — tooltip sam nie wystarczy.
- [ ] `Checkbox` zablokowany zależnością (G5) używa `aria-disabled` + `aria-describedby`
      wskazującego na tekst powodu, nie samego `Tooltip`.
- [ ] Rozstrzygnięcie niejednoznaczności: `Select` z prawdziwą `<label>`
      („Wskaż, o kogo chodzi w «{handle}»"), nie sam placeholder.
- [ ] Zakończenie generacji relacji ogłaszane `aria-live="polite"`:
      „Gotowe. Proponowane relacje: {count}, pominięte: {count}."
- [ ] `retract` — `ConfirmDialog` z `role="alertdialog"`; fokus startowy na **Anuluj**,
      nie na akcji destrukcyjnej.

### 27.11 i18n

Wstawić do obu katalogów (`en.ts` definiuje typ `MessageSchema`; `pl.ts` musi mieć 1:1 te same
klucze, inaczej `vue-tsc` i test parytetu `app/i18n/__tests__/i18n.spec.ts` padają).
Brak pluralizacji → wszystkie liczniki w formie `Etykieta: {count}`.
Miejsce: nowy pod-blok `knowledge.relations` i `knowledge.entityType` **przed zamknięciem bloku
`knowledge`** (dziś ostatni pod-blok to `knowledge.common`).

> **Uwaga na zajętą nazwę:** `knowledge.compose.relations` **już istnieje** i znaczy
> „**Powiązania**" — to nagłówek sekcji podglądu grafu szkiców w `KnowledgeDraftBoard.vue`
> (§26 pkt 2), czyli **coś innego** niż relacje typowane. Nie dokładać tam kluczy relacji i nie
> zmieniać tamtego tekstu. Klucze przeglądu `graph_updates` idą do `knowledge.relations.*`
> (blok „Przegląd" niżej), a nie do `knowledge.compose.*`.

#### 15 typów relacji — etykieta i ODWROTNOŚĆ

Klucze: `knowledge.relations.predicate.<key>.forward` / `.inverse`.
`forward` = patrząc od podmiotu; `inverse` = patrząc od dopełnienia (lista sąsiadów, panel szyny).

| `key` | PL forward | PL inverse | EN forward | EN inverse |
| --- | --- | --- | --- | --- |
| `member_of` | należy do | ma członka | member of | has member |
| `works_on` | pracuje nad | ma w pracach | works on | worked on by |
| `knows` | zna | zna | knows | known by |
| `created` | stworzył | stworzone przez | created | created by |
| `owns` | jest właścicielem | ma właściciela | owns | owned by |
| `located_in` | znajduje się w | mieści | located in | contains |
| `participated_in` | brał udział w | miał uczestnika | participated in | had participant |
| `occurred_during` | wydarzyło się podczas | obejmuje | occurred during | spans |
| `part_of` | jest częścią | składa się z | part of | has part |
| `is_a` | jest rodzajem | ma podtyp | is a | has subtype |
| `uses` | używa | jest używane przez | uses | used by |
| `depends_on` | zależy od | jest warunkiem dla | depends on | required by |
| `precedes` | poprzedza | następuje po | precedes | follows |
| `caused` | spowodował | spowodowane przez | caused | caused by |
| `opposes` | jest w opozycji do | jest w opozycji do | opposes | opposed by |

> **Predykaty symetryczne** (`knows`, `opposes`) mają **identyczną** etykietę w obu kierunkach —
> to jest zamierzone, nie przeoczenie. Dla nich graf rysuje krawędź **bez grota** (kierunek nie
> niesie informacji), a lista sąsiadów nie sugeruje fałszywej kierunkowości.
> `relationLabels.ts` musi ten fakt trzymać jako **dane** (`symmetric: true`), a nie wyprowadzać
> go z porównania stringów.

Grupy w `Select`ie typu (`knowledge.relations.group.*`):
`affiliation` „Przynależność" (`member_of`, `owns`, `part_of`, `is_a`) ·
`action` „Działanie" (`works_on`, `created`, `uses`, `participated_in`) ·
`spacetime` „Miejsce i czas" (`located_in`, `occurred_during`, `precedes`) ·
`social` „Relacje między ludźmi" (`knows`, `opposes`) ·
`dependency` „Zależność" (`depends_on`, `caused`).

#### 8 typów encji

Klucze `knowledge.entityType.<key>`. Siódemka realna + `unspecified` dla `null` (G10).

| `key` | PL | EN | Ikona (lista/badge) |
| --- | --- | --- | --- |
| `person` | Osoba | Person | `user` |
| `organization` | Organizacja | Organization | `users` |
| `event` | Wydarzenie | Event | `calendar` |
| `place` | Miejsce | Place | **`map-pin`** (nowa) |
| `product` | Produkt | Product | **`package`** (nowa) |
| `work` | Dzieło | Work | `bookmark` |
| `concept` | Pojęcie | Concept | `braces` |
| `unspecified` | Nieokreślony | Unspecified | `circle` |

Dwie nowe ikony do `ui/primitives/icons.ts` + galerii: **`map-pin`**, **`package`**
(sylwetki wyraźnie różne od siedmiu pozostałych — reguła szybkiego skanowania z §16).

#### Blok `knowledge.relations.*`

| Klucz | PL | EN |
| --- | --- | --- |
| `title` | Relacje | Relations |
| `count` | Relacje: {count} | Relations: {count} |
| `panelEmpty` | Ten wpis nie ma jeszcze relacji | This entry has no relations yet |
| `panelEmptyHint` | Relacje opisują, jak ten wpis łączy się z innymi — kto gdzie należy, co z czego wynika. | Relations describe how this entry connects to others — who belongs where, what follows from what. |
| `connect` | Połącz z… | Connect to… |
| `active` | Aktywna | Active |
| `ended` | Zakończona {year} | Ended {year} |
| `showEnded` | Pokaż zakończone: {count} | Show ended: {count} |
| `hideEnded` | Ukryj zakończone | Hide ended |
| `showHistorical` | Pokaż historyczne | Show historical |
| `hiddenHistorical` | Ukryto relacje zakończone: {count} | Hidden ended relations: {count} |
| `labelsHidden` | Za dużo relacji, żeby pokazać wszystkie podpisy — najedź na krawędź albo skorzystaj z listy. | Too many relations to label them all — hover an edge or use the list. |
| `from` | od {date} | from {date} |
| `until` | do {date} | until {date} |
| `sentence` | {subject} {predicate} {object} | {subject} {predicate} {object} |
| `sentenceDated` | {subject} {predicate} {object}, od {from} | {subject} {predicate} {object}, from {from} |
| `properties` | Właściwości | Properties |
| **Przegląd** | | |
| `updatesTitle` | Zmiany w grafie | Graph updates |
| `updatesSubtitle` | Relacje zaproponowane na podstawie Twojego tekstu. Nic nie zapisze się bez Twojej zgody. | Relations proposed from your text. Nothing is saved without your approval. |
| `opAdd` | Doda | Will add |
| `opEnd` | Zakończy | Will end |
| `opSupersede` | Zastąpi | Will replace |
| `endsOn` | z dniem {date} | as of {date} |
| `reason` | Powód: {reason} | Reason: {reason} |
| `groupCount` | Relacje: {count} | Relations: {count} |
| `selectAll` | Zaznacz wszystkie relacje | Select every relation |
| `selectGroup` | Zaznacz relacje encji: {name} | Select relations for: {name} |
| `select` | Zaznacz relację: {sentence} | Select relation: {sentence} |
| `acceptSelected` | Akceptuj zaznaczone: {count} | Accept selected: {count} |
| `acceptConfirm.title` | Zapisać zaznaczone relacje? | Save the selected relations? |
| `acceptConfirm.message` | Zapiszesz relacje: {relations}. Po drodze zaakceptujesz też wpisy: {entries}. | You will save relations: {relations}. Along the way you will also accept entries: {entries}. |
| `acceptConfirm.messagePlain` | Zapiszesz relacje: {relations}. | You will save relations: {relations}. |
| `edit` | Edytuj relację | Edit relation |
| `discard` | Odrzuć relację: {sentence} | Discard relation: {sentence} |
| `discarded` | Odrzucono relację | Relation discarded |
| `blockedByDraft` | Czeka na wpis „{title}" | Waiting for the entry "{title}" |
| `blockedByDraftHint` | Zaakceptuj najpierw ten wpis — bez niego relacja nie ma do czego prowadzić. | Accept that entry first — without it the relation has nowhere to point. |
| `draftBadge` | Szkic | Draft |
| `emptyProposals` | Agent nie zaproponował żadnych relacji | The agent proposed no relations |
| `emptyProposalsHint` | To normalne przy tekście bez wyraźnych powiązań między osobami, organizacjami czy wydarzeniami. | That is normal for text without clear links between people, organisations or events. |
| **Ostrzeżenia doradcze** | | |
| `advisory.typePair` | Nietypowa para typów | Unusual type pairing |
| `advisory.typePairHint` | Relacja „{predicate}" rzadko łączy {subjectType} z {objectType}. Sprawdź, czy o to chodziło — zapisać i tak można. | The relation "{predicate}" rarely links {subjectType} to {objectType}. Check it is what you meant — you can still save it. |
| `advisory.noDates` | Bez dat | No dates |
| `advisory.noDatesHint` | Relacja bez daty rozpoczęcia jest traktowana jako zawsze aktualna. | A relation with no start date is treated as always current. |
| **Pominięte** | | |
| `skipped` | Nie zapisano: {count} | Not saved: {count} |
| `skippedHint` | Agent to zauważył, ale nie zapisał. Poniżej powody. | The agent noticed these but did not save them. Reasons below. |
| `skip.unknownPredicate` | Typ relacji spoza słownika | Relation type outside the vocabulary |
| `skip.unknownPredicateHint` | Agent zaproponował „{raw}", czego nie ma w słowniku typów. | The agent proposed "{raw}", which is not in the type vocabulary. |
| `skip.unresolvedHandle` | Nie wiadomo, o kogo chodzi | Unclear who is meant |
| `skip.duplicate` | Taka relacja już istnieje | This relation already exists |
| `skip.duplicateShow` | Pokaż istniejącą | Show the existing one |
| `skip.selfReference` | Relacja wskazywała sama na siebie | The relation pointed at itself |
| `skip.outOfBase` | Jedna ze stron nie należy do tej bazy | One side is not in this base |
| **Niejednoznaczność** | | |
| `ambiguity.label` | Wskaż, o kogo chodzi w „{handle}" | Choose who "{handle}" refers to |
| `ambiguity.placeholder` | „{handle}" — wskaż encję | "{handle}" — pick an entity |
| `ambiguity.none` | Żaden z nich | None of these |
| `ambiguity.scope` | Dotyczy też relacji: {count} | Also applies to relations: {count} |
| `ambiguity.blocked` | Najpierw wskaż, o kogo chodzi | Choose who is meant first |
| **Wykrywanie** | | |
| `detect` | Wykryj relacje | Detect relations |
| `detectHint` | Osobne przejście agenta — zużywa budżet AI. Można je wykonać raz na rundę. | A separate agent pass — it spends AI budget. Once per round. |
| `detectDone` | Relacje wykryte w tej rundzie | Relations detected this round |
| `detectAlready` | Relacje były już wykryte w tej rundzie. | Relations were already detected this round. |
| **Edytor** | | |
| `editor.createTitle` | Połącz z… | Connect to… |
| `editor.editTitle` | Edytuj relację | Edit relation |
| `editor.promoteTitle` | Utrwal jako relację | Make this a relation |
| `editor.subject` | Ten wpis | This entry |
| `editor.predicate` | Typ relacji | Relation type |
| `editor.predicatePlaceholder` | Wybierz typ | Pick a type |
| `editor.object` | Powiązany wpis | Related entry |
| `editor.objectPlaceholder` | Szukaj wpisu w tej bazie | Search this base |
| `editor.description` | Opis | Description |
| `editor.descriptionHint` | Napisz, na czym polega ta relacja — to przeczyta następna osoba i AI. | Describe what the relation means — the next person and the AI will read it. |
| `editor.validity` | Ważność | Validity |
| `editor.validFrom` | od | from |
| `editor.validUntil` | do | until |
| `editor.preview` | Podgląd | Preview |
| `editor.save` | Zapisz relację | Save relation |
| `editor.saved` | Zapisano relację | Relation saved |
| `editor.errPredicate` | Wybierz typ relacji | Pick a relation type |
| `editor.errObject` | Wskaż powiązany wpis | Pick a related entry |
| `editor.errSelf` | Wpis nie może być powiązany sam ze sobą | An entry cannot relate to itself |
| `editor.errDates` | Data „do" nie może być wcześniejsza niż „od" | The "until" date cannot precede "from" |
| `editor.errDuplicate` | Taka relacja już istnieje między tymi wpisami | This relation already exists between these entries |
| **Awans** | | |
| `promote` | Utrwal jako relację: {title} | Make this a relation: {title} |
| `promoted` | Utrwolono jako relację | Saved as a relation |
| `promotedShow` | Pokaż | Show |
| **Zakończenie** | | |
| `end.title` | Zakończ relację | End the relation |
| `end.hint` | Relacja zostanie w historii z datą zakończenia. | The relation stays in the history with an end date. |
| `end.date` | Data zakończenia | End date |
| `end.reason` | Powód (opcjonalnie) | Reason (optional) |
| `end.submit` | Zakończ | End it |
| `end.done` | Zakończono relację | Relation ended |
| **Wycofanie (twarde usunięcie)** | | |
| `retract.action` | Usuń relację | Delete relation |
| `retract.title` | Usunąć relację całkowicie? | Delete the relation entirely? |
| `retract.message` | Usuwasz zapis tak, jakby nigdy nie powstał — zniknie też z historii. Jeśli ten fakt PRZESTAŁ być prawdziwy, użyj „Zakończ" zamiast usuwania: relacja zostanie z datą końca, a historia pozostanie czytelna. | You are deleting the record as if it had never existed — it disappears from the history too. If the fact merely STOPPED being true, use "End it" instead: the relation stays with an end date and the history remains readable. |
| `retract.confirm` | Usuń całkowicie | Delete entirely |
| `retract.instead` | Zamiast tego zakończ | End it instead |
| `retract.done` | Usunięto relację | Relation deleted |
| **Typ encji** | | |
| `entityType.label` | Typ encji | Entity type |
| `entityType.hint` | Ułatwia agentowi dobieranie relacji. Można zostawić nieokreślony. | It helps the agent pick relations. You can leave it unspecified. |
| `entityType.set` | Ustaw typ encji | Set the entity type |
| `entityType.saved` | Zapisano typ encji | Entity type saved |

Dodatkowo: `knowledge.graph.edge.relation` = „Relacje" / „Relations"
oraz `knowledge.panels.relations` = „Relacje" / „Relations".

### 27.12 Pytania kontraktowe do backendu — **nie kodować przed potwierdzeniem**

Backend powstaje równolegle. Poniżej **założenia UI**, nie kontrakt.
Precedens z §26 jest tu ostrzeżeniem: przy kreatorze rozjechały się dokładnie **nazwy pól**
(`.knowledge-compose.updated` vs `.knowledge-draft-session.updated`, `amends` vs `amended_by`),
mimo że kształt był trafiony.

| # | Pytanie | Dlaczego blokujące |
| --- | --- | --- |
| **GK1** | Czy wykrywanie relacji jest **częścią generacji**, czy **osobnym, metrowanym wywołaniem** (jak `expand-context`)? Jeśli osobnym — czy sesja niesie pole typu `relations_detected_at` (odpowiednik `context_expanded_at`) i czy powtórka daje 422? | Decyduje o istnieniu przycisku „Wykryj relacje" i całej sekcji §27.5. Stan **musi** być serwerowy, nie lokalny (precedens §26 pkt 7). |
| **GK2** | Kształt `graph_updates` w odpowiedzi sesji: zakładane `{ operation: 'add'\|'end'\|'supersede', subject, predicate, object, description, properties, valid_from, valid_until, reason, advisories[], blocked_by_draft_id }` + `skipped[]` z `reason`. Jak identyfikowane są encje — id wpisu, slug, czy uchwyt tekstowy dla nierozstrzygniętych? | Cały render §27.3/§27.4 stoi na tych polach. Zwłaszcza **czym jest koniec relacji, gdy wskazuje szkic** (id szkicu ≠ id wpisu, który dopiero powstanie). |
| **GK3** | Czy relacja jest **osobnym bytem**, czy wierszem `knowledge_links` z `source='relation'`? Czy `GET …/graph` zwraca ją w `edges[]` z tym samym kształtem plus `predicate`/`valid_from`/`valid_until`? | Od tego zależy, czy warstwa grafu to **dodanie jednej wartości do `GRAPH_EDGE_KINDS`** (tanie, §27.7), czy druga ścieżka danych w kanwie (drogie). **Dowód, że `knowledge_links` w obecnym kształcie NIE wystarczy:** klucz unikalny to `['from_entry_id', 'target_slug', 'source']`, więc dwie różne relacje między tą samą parą (`works_on` **i** `created`) nie zmieszczą się bez dołożenia predykatu do klucza; nie ma też ani jednej kolumny czasowej (`valid_from`/`valid_until`), a jedyny mechanizm „człowiek powiedział nie" to `dismissed_at` — miękka flaga z cofnięciem, czyli **nie** `retract`. Silnie preferowane: osobna tabela + `edges[]` wzbogacone o pola relacji. |
| **GK4** | Rozstrzyganie niejednoznaczności: czy jest endpoint „rozwiąż uchwyt {handle} → {entry_id}" **na poziomie sesji** (stosowany do wszystkich relacji z tym uchwytem), czy per relacja? | G7 obiecuje „rozstrzygnij raz, zastosuje się do wszystkich" — jeśli kontrakt jest per relacja, obietnica jest fałszywa i copy trzeba zmienić. |
| **GK5** | Czy awans miękkiej krawędzi (`similarity`/`mention` → relacja) **odrzuca** krawędź źródłową po stronie serwera, żeby re-indeks jej nie zaproponował ponownie? Jednym żądaniem, czy dwoma (utwórz relację + `POST …/links/{link}/dismiss`)? | §27.9 obiecuje „sugestia znika". Dwa żądania bez transakcji mogą zostawić stan pośredni (relacja jest, sugestia wraca po re-indeksie). |
| **GK6** | `retract` — twarde `DELETE` czy soft-delete z możliwością przywrócenia? Jeśli twarde: czy relacja znika też z historii wpisu? | Copy dialogu (§27.8) **twierdzi**, że „zniknie też z historii". Jeśli to nieprawda, copy kłamie w najbardziej wrażliwym miejscu modułu. |
| **GK7** | `entity_type` — kolumna na `knowledge_entries` (`null` dozwolony) czy osobna encja? Czy zmiana typu jest `PATCH` wpisu (czyli tworzy rewizję i unieważnia indeks), czy osobnym, tanim endpointem? | Jeśli tworzy rewizję, ustawienie typu na 200 istniejących wpisach zaśmieci historię i wywoła 200 re-indeksacji. UI musi wtedy ostrzegać. |
| **GK8** | Czy słownik 15 typów + reguły „typowej pary typów" są **wystawione przez API** (żeby UI nie kopiował ontologii), czy zaszyte po obu stronach? | Ostrzeżenia doradcze (G11) wymagają reguł par. Duplikowanie ontologii w `relationLabels.ts` gwarantuje dryf. **Preferowane:** API zwraca słownik + pary, UI trzyma tylko **etykiety**. |
| **GK9** | **RODO / usuwanie podmiotu (ADR-0045):** czy `KnowledgeSubjectPurgeService` obejmuje relacje? Osoba usunięta z bazy nie może zostać w krawędzi „należy do Acme". | To jest zobowiązanie prawne, nie funkcja. Jeśli purge nie widzi relacji, moduł relacji **nie może wejść na produkcję**. |
| **GK10** | Ważność czasowa: `valid_from`/`valid_until` to **daty** czy **datetime**? Czy relacja bez `valid_from` jest „od zawsze"? Czy `end` z datą w przyszłości jest dozwolone (zaplanowane zakończenie)? | Steruje `DatePicker` vs `DateTimePicker` i copy „Aktywna" / „Zakończona {rok}". Kontrakt serializacji dat w module: **ISO `yyyy-mm-dd`, nigdy `Date`** (§Tier 3 macierzy komponentów). |

### 27.13 Ryzyka spójności (dopisek do §21 / §25.8)

| # | Ryzyko | Mitygacja |
| --- | --- | --- |
| **R25** | **Kolizja nazw komponentów.** `KnowledgeDraftRelationsPanel.vue` już istnieje i znaczy co innego (podgląd grafu szkiców). Wcielenie nowej sekcji do niego zlepi dwa niezwiązane byty. | Nowy komponent `KnowledgeGraphUpdatesPanel.vue`; §27.4 mówi to wprost. |
| **R26** | **Etykieta krawędzi jako treść dostępna tylko wzrokowo.** SVG jest `aria-hidden`; relacja jako jedyna warstwa niesie znaczenie w etykiecie. | §27.10 — lista sąsiadów **musi** objąć relacje pełnym zdaniem, zanim kanwa dostanie etykiety. Kolejność implementacji w §27.14 to wymusza. |
| **R27** | **Ontologia zduplikowana po obu stronach.** 15 typów + reguły par w `relationLabels.ts` i w backendzie = pewny dryf. | GK8 — API wystawia słownik i pary; frontend trzyma **wyłącznie etykiety** i18n. |
| **R28** | **Szum etykiet w grafie.** Przy 60 węzłach etykiety zlewają się w plamę. | Reguła zagęszczenia ≤ 20 krawędzi + komunikat „podpisy ukryte" (§27.7). |
| **R29** | **`entity_type = null` wyglądający jak błąd.** Wszystkie istniejące wpisy mają `null`; badge ostrzegawczy zamieniłby całą bazę w listę usterek. | G10 — brak badge'a to brak informacji, nie ostrzeżenie. Nigdy `variant="warning"` dla `null`. |
| **R30** | **Mylenie `end` z `retract`.** Użytkownik usunie relację, żeby „zaktualizować" fakt, i skasuje historię. | G13 — dialog **kieruje** do „Zakończ" i daje do niej skrót; „Zakończ" jest w wierszu **przed** „Usuń". |
| **R31** | **Relacja do nieistniejącej encji.** Akceptacja relacji przed wpisem = 422 albo, gorzej, krawędź w próżnię. | G5 — zależność widoczna **przed** akceptacją, `Checkbox` zablokowany z powodem, zbiorcza akceptacja porządkuje kolejność. |
| **R32** | **Podwójny koszt AI po cichu.** Druga faza wydaje budżet; użytkownik może jej nie zauważyć. | G12 — wzorzec `expand-context`: nota słowna, przycisk jednorazowy, stan serwerowy, meter odświeżany. |
| **R33** | **RODO.** Purge podmiotu, który nie widzi relacji, zostawia dane osobowe w krawędziach. | GK9 — **warunek wejścia na produkcję**, nie punkt do follow-upu. |
| **R34** | **Bramka przeglądu obchodzona przez wygodę.** Pokusa „zaakceptuj wszystkie relacje" bez zaznaczania. | Decyzja właściciela jest wiążąca: zbiorcza akcja działa **tylko na zaznaczonych** (G4), jak przy szkicach. |
| **R35** | **Zwijanie „jeden wiersz na sąsiada" gubi relacje.** `neighboursOf()` wybiera dziś najsilniejszą krawędź na sąsiada; dwie różne relacje między tą samą parą pokażą się jako jedna. | §27.7 — dla `relation` zwijania nie ma, **przypięte testem** (inaczej pierwszy refaktor `betterRelation` cofnie to bez śladu). |
| **R36** | **Połowiczne dodanie rodzaju do grafu.** `GRAPH_EDGE_KINDS` automatycznie tworzy wiersz legendy i grupę listy, ale TypeScript **nie** pilnuje CSS ani i18n — efektem jest krawędź nieodróżnialna od `wikilink` z surowym kluczem zamiast nazwy. | §27.7 — pełna lista siedmiu miejsc z kolumną „pilnuje TS?"; CSS trzeba dodać **dwa razy** (kanwa i legenda mają osobne kopie stylów). |
| **R37** | **Zatarcie granicy „odrzuć" / „wycofaj".** Wciśnięcie `end`/`retract` w istniejący emit `dismiss-link` zrównałoby operację odwracalną z nieodwracalną. | §27.8 — osobne emity `end-relation` / `retract-relation` / `edit-relation`; `dismiss-link` zostaje dla warstw miękkich. |
| **R38** | **Kolizja klucza i18n.** `knowledge.compose.relations` jest już zajęte („Powiązania" — podgląd grafu szkiców) i znaczy co innego. | §27.11 — relacje typowane mieszkają w `knowledge.relations.*`; tamtego klucza nie ruszać. |

### 27.14 Kolejność implementacji i testy

1. **G6a — fundament, bez UI relacji:** `relationLabels.ts` (czysty, testowany: forward/inverse/
   symmetric), `knowledge.relations.*` + `knowledge.entityType.*` w obu katalogach,
   dwie nowe ikony (`map-pin`, `package`), `KnowledgeRelationSentence.vue` + jego testy.
   **Da się zrobić i przetestować bez backendu.**
2. **G6b — konsumpcja (najpierw czytanie, potem pisanie):** panel „Relacje" w szynie
   (`KnowledgeEntryRail.vue`, nad „Podobne"), typ encji w nagłówku/ustawieniach wpisu.
3. **G6c — graf:** `GRAPH_EDGE_KINDS` + `KIND_RANK`, **najpierw lista sąsiadów** (R26),
   dopiero potem kanwa i etykiety, legenda, chip, przełącznik historycznych.
4. **G6d — pisanie ręczne:** `KnowledgeRelationEditorModal`, „Połącz z…", zakończenie, `retract`,
   awans miękkiej krawędzi.
5. **G6e — przegląd w kreatorze** (dopiero po potwierdzeniu GK1–GK4):
   `KnowledgeGraphUpdatesPanel`, `KnowledgeRelationRow`, `KnowledgeEntityChooser`,
   sekcja pominiętych, faza wykrywania.

**Testy powstające razem z kodem:**
`relationLabels` — odwrotność każdego z 15 typów, symetryczne w obie strony identyczne ·
`KnowledgeRelationSentence` — `aria-label` jest zdaniem, `Badge` i strzałka `aria-hidden` ·
lista sąsiadów zawiera grupę relacji z pełnym zdaniem (R26) ·
`Checkbox` relacji zależnej od szkicu jest zablokowany i odblokowuje się po akceptacji wpisu (R31) ·
rozstrzygnięcie uchwytu stosuje się do wszystkich relacji z tym uchwytem (G7/GK4) ·
dialog `retract` ma skrót „Zamiast tego zakończ" i fokus startowy na Anuluj (R30) ·
parytet katalogów i18n po dodaniu bloku (R24) ·
etykiety krawędzi znikają powyżej 20 relacji (R28) ·
**dwie relacje między tą samą parą dają DWA wiersze w liście sąsiadów** (R35) ·
predykat dojeżdża przez cały łańcuch `types → layout → graphNeighbours → lista` (§27.7).

---

## 28. Errata G9 — Wiki-Graf zweryfikowany w zbudowanym kodzie

> Dopisane po weryfikacji zbudowanego kodu (`app/modules/Knowledge/**`, batch G9 — typowane relacje
> semantyczne, silnik + write path + review gate). §27 była spisana **przed** kodem backendu i
> oznaczała cały kontrakt drutu jako „założenie UI", z dziesięcioma blokującymi pytaniami w §27.12
> (GK1–GK10). Poniżej: najpierw odpowiedzi na te pytania, potem rozbieżności nazw/kształtu pól
> zgłoszone przez agentów frontendowych w trakcie budowy (numerowane **8–17**, kontynuacja liczenia
> z tego etapu), a na końcu to, co wynikło dopiero przy tej dokumentacyjnej weryfikacji (**18–20**).
> §27 **nie jest przepisywana wstecznie** (konwencja tego dokumentu, patrz nagłówek §23) — to jest
> jedyne aktualne źródło rozstrzygnięć poniżej. Pełny kontrakt: `docs/backend/knowledge-api.md` →
> „Typed relations" + „Typed relations — endpoints"; zapis decyzji:
> `docs/decisions/ADR-0047-knowledge-typed-relations.md`.

### GK1–GK10 rozstrzygnięte

| # | Pytanie (§27.12) | Rozstrzygnięcie |
| --- | --- | --- |
| **GK1** | Czy wykrywanie relacji jest częścią generacji, czy osobnym, metrowanym wywołaniem? | **Częścią generacji.** Nie ma osobnego przycisku „Wykryj relacje", nie ma pola `relations_detected_at`, nie ma osobnego 422 za powtórkę. Sekcja §27.5 w całości **nie ma zastosowania** — usunąć z planu implementacji, nie budować. `graph_ops` powstaje w TYM SAMYM wywołaniu co szkice, przy każdej generacji i każdej poprawce. |
| **GK2** | Kształt `graph_updates` — nazwy pól, sposób adresowania encji. | Patrz punkt **8** niżej — pełna tabela mapowania nazw. Adresowanie: uchwyty tekstowe `E<n>`/`R<n>`/`N<n>` (istniejące encje / istniejące relacje / nowe encje), nigdy id z bazy. |
| **GK3** | Czy relacja jest osobnym bytem, czy wierszem `knowledge_links` z `source='relation'`? | **Osobny byt** — tabela `knowledge_relations`, dokładnie jak spec sam podejrzewał na podstawie analizy klucza unikalnego `knowledge_links`. Ekspozycja w `GET …/graph`: TAK, w tym samym `edges[]`, z dyskryminatorem `kind: 'link' \| 'relation'` — patrz punkt **11**. |
| **GK4** | Rozstrzyganie niejednoznaczności — endpoint per sesja czy per relacja? | **Ani jedno, ani drugie — nie ma osobnego endpointu.** Rozstrzygnięcie niejednoznacznej wzmianki dzieje się WEWNĄTRZ generacji/poprawki (ten sam model decyduje z kontekstu, albo zostawia w `resolution.ambiguous[]`/`unresolved[]`). Nie ma interaktywnego `Select`a wysyłającego wybór do serwera w trakcie sesji — G7/§27.6 (`KnowledgeEntityChooser.vue` jako serwerowo-rozwiązywany wybór) **nie ma dziś odpowiednika w API** i nie powinien być budowany w tym kształcie; patrz punkt **20** niżej. |
| **GK5** | Czy awans miękkiej krawędzi odrzuca krawędź źródłową po stronie serwera, jednym żądaniem? | **Tak, jednym żądaniem.** `POST …/relations` z `promote_link_id` tworzy relację (`origin: promoted`) I stempluje `dismissed_at` na wskazanym `KnowledgeLink` w TEJ SAMEJ transakcji. Link spoza tej bazy lub niedopuszczalnego `source` (nie `similarity`/`mention`) jest po cichu pomijany — relacja i tak powstaje, bo karanie autora za nieaktualne id w payloadzie byłoby złym kompromisem. |
| **GK6** | `retract` — twarde `DELETE` czy soft-delete z przywróceniem? | **Ani jedno wprost — to DWIE różne operacje, nie jedna.** `retract` jest MIĘKKI: `POST …/relations/{relation}/end` z `{retract: true}`, wiersz PRZETRWA ze `state: retracted`, widoczny pod `include_historical`. Twarde, nieodwracalne usunięcie to OSOBNY endpoint, `DELETE /relations/{relation}`, ograniczony do twórcy/właściciela workspace'u. **Copy dialogu z §27.8 („zniknie też z historii") opisuje `DELETE`, nie `retract`** — patrz punkt **12** niżej, to jest realna rozbieżność do naprawienia w UI, nie tylko w nazewnictwie. |
| **GK7** | `entity_type` — kolumna czy osobna encja? Czy zmiana tworzy rewizję? | **Zwykła, nullable kolumna** na `knowledge_entries`, ustawiana przez ten sam `PATCH /entries/{entry}` co każde inne pole (`entry_type`, absent = bez zmian, `null` = czyści). **Nie tworzy rewizji i nie re-indeksuje** — `entry_type` jest jawnie WYŁĄCZONY z `index_digest` (`KnowledgeEntryService::update()`, komentarz: „an entry's KIND is a fact about the graph, not about the document"). Ustawienie typu na 200 istniejących wpisach nie zaśmieci historii i nie wywoła re-indeksacji — obawa z §27.12 się nie ziściła. |
| **GK8** | Czy słownik 15 typów + reguły par są wystawione przez API? | **Tak, w całości.** `KnowledgeBaseResource.relation_vocabulary` (`KnowledgeRelationType::catalog()`) niesie `{id, label, inverse_label, symmetric, property_keys, from_types, to_types}` dla każdego z 15 typów, sent WITH the base. Frontend nie trzyma własnej kopii ontologii — patrz `resources/js/next/docs/pages/KnowledgePage.vue`. |
| **GK9** | Czy `KnowledgeSubjectPurgeService`/`knowledge:purge-subject` obejmuje relacje? | **Tak — warunek wejścia na produkcję spełniony.** Nowa kategoria `relations[]` w raporcie, dopasowanie po `description`/`properties`, remedium to TWARDE `DELETE` (nigdy `end`/`retract` — te dwa celowo ZATRZYMUJĄ wiersz). Zeskanowane po `active`, `ended` I `retracted` — historyczna relacja niesie to samo zdanie. Zdarzenia audytu (`knowledge_relation_events`) usuwane razem z relacją. Patrz `docs/backend/knowledge-api.md` → „Data erasure". |
| **GK10** | `valid_from`/`valid_until` — daty czy datetime? Relacja bez `valid_from` = „od zawsze"? `end` w przyszłości dozwolony? | **Daty (`yyyy-mm-dd`), nigdy datetime** — `DatePicker`, nie `DateTimePicker`. Relacja bez `valid_from` nie ma specjalnej semantyki „od zawsze" zakodowanej wprost, ale jest tak traktowana FAKTYCZNIE przez regułę duplikatów (dwie relacje bez daty startu tego samego typu/pary liczą się jako TA SAMA relacja). `end` z datą w przyszłości **jest dozwolony** — `EndKnowledgeRelationRequest.valid_to` nie ma górnego ograniczenia (zaplanowane zakończenie jest ważną, wspieraną operacją). |

### Rozbieżności nazw i kształtu pól (zgłoszone w trakcie budowy, 8–17)

8. **`predicate` → `relation_type`, i cała reszta nazewnictwa operacji jest inna niż w GK2.** Spec
   zakładał `{ operation: 'add'|'end'|'supersede', subject, predicate, object, description, properties,
   valid_from, valid_until, reason, advisories[], blocked_by_draft_id }`. Zbudowany kształt (`graph_ops`
   na sesji ORAZ `proposed_relations[]` w podglądzie relacji — dwie lekko różne serializacje tego
   samego, patrz `docs/backend/knowledge-api.md`):
   `{ op: 'create'|'update'|'end', from, to, relation_type (surowe pole 'type' w `graph_ops.graph_updates`,
   'relation_type' w podglądzie `proposed_relations`), description, properties, valid_from, valid_to,
   replaces, relation (uchwyt R<n> dla update/end) }`. Nie ma pola `reason` — jest tylko `description`.
   Nie ma pola `advisories[]` PER OPERACJA — ostrzeżenia żyją w sesyjnym `graph_ops.warnings[]`, luźno
   powiązane z operacją przez `type`/`from`/`to`, nie przez indeks operacji. Nie ma `operation: 'add'`
   — jest `op: 'create'`. Nie ma osobnej wartości `'supersede'` — zastąpienie to `create` z `replaces`
   wskazującym na uchwyt `end`u w tej samej odpowiedzi (patrz punkt 15).
9. **`valid_until` → `valid_to`** na każdej powierzchni (zasób relacji, krawędź grafu, operacja
   `graph_updates`, endpointy zapisu relacji).
10. **`entity_type` → `entry_type`** — nazwa kolumny na `KnowledgeEntry`, pole na węźle grafu, pole w
    macierzy par bazy (`relation_vocabulary[].from_types`/`to_types`).
11. **`edges[].kind` to dyskryminator pierwszej klasy, zaprojektowany od razu — nie druga wizualizacja,
    nie wywnioskowany z `source`.** GK3 dopuszczał, że relacja może wylądować jako wiersz
    `knowledge_links` z `source='relation'`; spec sam wskazał, dlaczego to prawdopodobnie nie wystarczy
    (klucz unikalny, brak kolumn czasowych). Zbudowane: osobna tabela, ten sam `edges[]` co linki, z
    `kind: 'link' | 'relation'` i KAŻDYM polem drugiego rodzaju present-and-null (nigdy nieobecnym) —
    patrz `docs/backend/knowledge-api.md` → `KnowledgeGraphResource`.
12. **`retract` ≠ twarde usunięcie — copy dialogu z §27.8 opisuje NIEWŁAŚCIWĄ operację.** §27.1 sam
    definiuje: „Wycofanie / retract / **Twarde usunięcie** — 'to nigdy nie było prawdą'. Wyłącznie
    człowiek, wyłącznie w UI" — czyli spec od początku utożsamia `retract` z twardym usunięciem. W
    zbudowanym kodzie to DWIE różne operacje (patrz GK6): `retract` jest MIĘKKI stan (`state:
    retracted`, wiersz przetrwa, widoczny pod „Pokaż historyczne"), a twarde `DELETE` jest osobnym,
    bardziej ograniczonym endpointem. **Konsekwencja dla UI:** akcja „Usuń relację" opisana w §27.8
    (`ConfirmDialog variant="danger"`, treść „zniknie też z historii") **musi wołać `DELETE
    /relations/{relation}`, nie `POST …/end` z `{retract: true}`** — jeśli UI wywoła miękki `retract`
    pod tym dialogiem, użytkownik przeczyta „zniknie na zawsze" o operacji, która w rzeczywistości nic
    nie usuwa. Jeśli produkt chce OSOBNO wystawić miękki `retract` ([]„to nigdy nie było prawdą", wiersz
    zostaje) jako trzecią akcję obok „Zakończ"/„Usuń", potrzebuje własnego, PRAWDOMÓWNEGO copy — nie
    copy z §27.8.
13. **`description` ma twardy limit 300 znaków; `properties` — 200 znaków na wartość, i NAJWYŻEJ JEDEN
    klucz na typ relacji (nie 4).** Zweryfikowane w `KnowledgeRelationType::propertyKeys()`: każdy z 15
    typów zwraca dokładnie **zero lub jeden** klucz (`member_of`/`works_on`/`created`/`participated_in` →
    `role`; `knows` → `how`; `owns` → `share`; `uses` → `purpose`; `depends_on` → `kind`; `opposes` →
    `reason`; pozostałe sześć — `located_in`, `part_of`, `is_a`, `occurred_during`, `precedes`, `caused`
    — zero). Stała `MAX_PROPERTY_KEYS = 4` istnieje w `KnowledgeGraphOps.php`, ale to pułap
    LAUNDROWANIA (ile kluczy odpowiedź modelu może w ogóle mieć w bagażu, zanim cała mapa właściwości
    zostanie odrzucona) — **nie** liczba, jaką którykolwiek pojedynczy typ relacji faktycznie
    przyjmuje. **Repeater `klucz`/`wartość` zbudowany wg pierwotnego założenia specu (dowolna liczba
    par) pokazywałby wiersze, które serwer ZAWSZE odrzuci** — dla wybranego typu relacji poprawny jest
    co najwyżej JEDEN wiersz, z kluczem zablokowanym na jedyną wartość, jaką ten typ przyjmuje (np. pole
    „Rola" dla `member_of`, nie generyczny `TextInput` na klucz). Kontrolka właściwości musi więc
    czytać `property_keys` z `relation_vocabulary` wybranego typu (0 lub 1 element) i renderować
    dokładnie tyle pól, ile ten typ deklaruje — nie stały repeater. §27.9 opisuje generyczny „repeater
    `klucz`/`wartość`" bez tego ograniczenia i bez wspomnianego limitu 300/200 widocznego w UI —
    licznik znaków/`maxlength` trzeba dograć.
14. **9 opcji w pickerze typu encji, nie 8: 8 REALNYCH `KnowledgeEntryType` (w tym `other`) +
    „nieokreślony" dla `null`.** §27.1 mówi „7 kategorii wpisu (+ 'nieokreślony')"; tabela w §27.11
    wylicza dokładnie 7 realnych wierszy (person/organization/event/place/product/work/concept) +
    `unspecified` = 8 pozycji razem. Zbudowany enum ma ÓSMY realny przypadek, `other` — świadoma
    odpowiedź „typowany, ale żaden z powyższych", inna niż `null` (nietypowany) — co daje 8 realnych +
    „nieokreślony" = **9 pozycji**. `other` nie uczestniczy w macierzy par (traktowany jak nieznany),
    identycznie jak `null` — brakujący wiersz w tabeli §27.11 potrzebuje etykiety PL/EN. **Zweryfikowane
    w zbudowanym kodzie (`relationLabels.ts::entryTypeIcon()`): `other` dostaje TĘ SAMĄ ikonę co
    `unspecified` — `circle` dla obu** (nie osobną, odróżnialną ikonę, jak można by się spodziewać po
    tym, że to dwie różne odpowiedzi — `other` jest świadomym wyborem, `null`/`unspecified` jest jego
    brakiem). Rozmyślne czy przeoczenie — nierozstrzygnięte tym przejściem dokumentacyjnym (dokumentacja
    nie zmienia kodu); jeśli produkt chce je odróżniać wzrokowo, potrzebna osobna ikona dla `other`.
15. **Akceptacja jest per-OPERACJA z kluczami serwerowymi, nie per-ZMIANA identyfikowaną treścią.** G4
    poprawnie przewidział ziarnistość (checkbox per relacja, nie per encja) — ale spec nie miał żadnego
    kontraktu na to, JAK operacja jest adresowana przy wysyłce `accept`. Zbudowany mechanizm:
    `graph_op_keys` w body `POST …/accept`, klucze `graph:<n>` — POZYCJA operacji we WŁASNEJ, zamrożonej
    kolumnie `graph_ops.graph_updates` sesji, nigdy hash ani treść. Trójwartościowe: pole nieobecne =
    zastosuj wszystkie; `[]` = żadnej; lista = dokładnie te, reszta wraca w `skipped[]` jako
    `not_selected`. **Nowa reguła, której spec w ogóle nie przewidywał:** para `create`+`replaces`/`end`
    związana przez `pair_with` musi być zaznaczona lub odznaczona RAZEM — rozdzielenie zaznaczenia zwraca
    `422 inseparable_ops`. UI musi renderować taką parę jako JEDNĄ kontrolkę (jeden checkbox obejmujący
    oba wiersze albo widoczne sprzężenie), nie dwa niezależne checkboxy, inaczej użytkownik trafi na 422
    bez zrozumienia dlaczego.
16. **Kody odrzuceń/ostrzeżeń są innym, większym i inaczej nazwanym zbiorem niż pięciowierszowa tabela w
    §27.4 — i rozdzielone na CZTERY, nie trzy, kanały.** Spec zakładał pięć powodów pominięcia:
    `unknown_predicate`, `unresolved_handle`, `duplicate`, `self_reference`, `out_of_base`. Zbudowane
    (zweryfikowane w kodzie na dzień przeglądu G10 — poprzednia wersja tego punktu miała TĘ SAMĄ klasę
    błędu, którą tu opisuje: liczyła kanał ostrzeżeń, zanim `wikilinks_lost` przeniósł się do notatek):
    - Laundering, odrzucenia (`graph_ops.rejected[]`, **13** kodów — było 12 do momentu dopisania
      `dates_reversed`, patrz punkt 23): `unknown_handle`, `unknown_relation_type`, `type_not_allowed`,
      `self_loop`, `forbidden_op`, `unknown_op`, `properties_refused`, `duplicate_relation`,
      `pair_refused`, `op_cap_reached`, `template_directive`, `malformed`, `dates_reversed`.
      `op_cap_reached`'s kontekst niesie `max` we WSZYSTKICH trzech `scope`ach (`entities` niósł
      wcześniej `cap`, ujednolicone — patrz punkt 22).
    - Laundering, ostrzeżenia (`graph_ops.warnings[]`, **4** kody, WYŁĄCZNIE o grafie):
      `pair_unchecked`, `ambiguity_unresolved`, `moved_to_review`, `replaces_unbound` — patrz punkt 19.
      **`rewrite_degraded_to_append` nie istnieje nigdzie w kodzie** (wcześniejsza wersja tego dokumentu
      go zakładała/wymyśliła).
    - Notatki przebiegu (`notes[]` na sesji, `DraftRunNotes`, **4** kody, o TEKŚCIE, nie o grafie):
      `amend_append_only`, `amend_too_long`, **`wikilinks_lost`** (jest TU, nie w
      `graph_ops.warnings[]` — reguła modułu „jeden fakt, jeden kanał": to, co serwer zrobił z TEKSTEM
      propozycji, nigdy nie żyje w tym samym miejscu co to, co zrobił z GRAFEM), `resolution_degraded`.
    - Akceptacja (`accept` response `skipped[]`, 5 kodów): `not_selected`, `already_applied`,
      `dependency_not_accepted`, `relation_gone`, `refused`. **`entity_gone` i `unknown_handle` NIE
      istnieją** — nie są nawet zadeklarowanymi-ale-nieosiągalnymi stałymi (tak było we wcześniejszej
      wersji tego dokumentu); kod niesie dziś jawny komentarz, że żaden z tych dwóch skipów nie istnieje
      z rozmysłu. Zniknięty cel podczas przeglądu ujawnia się przez `conflicts[]` ścieżki
      szkicu-cienia, a zależność od niezaakceptowanej encji to zawsze `dependency_not_accepted`.
    Zgrubne odpowiedniki dla trafień odrzuceń: `unknown_predicate`≈`unknown_relation_type`,
    `unresolved_handle`≈`unknown_handle`, `duplicate`≈`duplicate_relation`, `self_reference`≈
    `self_loop`. `out_of_base` nie ma odpowiednika — encja spoza bazy po prostu 404uje przy
    rozwiązywaniu, nigdy nie trafia do listy odrzuceń. UI zbudowany ściśle wg tabeli z §27.4, bez
    rozdzielenia na cztery kanały powyżej, nie umiałby wyrenderować większości tego, co serwer faktycznie
    zwraca — w szczególności wstawiłby `wikilinks_lost` do niewłaściwego panelu (przeglądu relacji
    zamiast przeglądu treści szkicu).
17. **`amend_mode`/`amend_section`/`amended_body` istnieją WYŁĄCZNIE na pełnym `KnowledgeEntryResource`
    — nieobecne (nie `null`) na `KnowledgeEntryListResource`.** Poza zakresem §27 (to pola z partii
    kreatora, ADR-0046), ale zweryfikowane przy okazji tego przejścia dokumentacyjnego: karta/diff
    nowelizacji-dopisania MUSI renderować `amended_body`, nigdy surowego `content` — dla dopisania
    `content` to sam dodatek, nie cały nowy tekst (patrz ADR-0047, defekt (d)).

### Wynikło dopiero przy tej weryfikacji (18–20)

18. **Kontrolka typu encji (`entry_type`) żyje w EDYTORZE wpisu, nie w szynie czytnika.** G10 (§27.2)
    mówi ogólnikowo „jedyne miejsce, gdzie `null` jest nazwany, to szyna/ustawienia wpisu" — sformułowanie
    dopuszcza dwie różne powierzchnie. Rozstrzygnięcie: skoro `entry_type` jest zwykłym polem
    ustawianym przez ten sam `PATCH /entries/{entry}` co `title`/`aliases`/`metadata` (GK7), kontrolka
    (`Select` z 9 opcjami — punkt 14) należy do **edytora wpisu** (`KnowledgeEntryEditorView.vue`, obok
    pól tytułu i metadanych), a nie do panelu bocznego czytnika — panel czytnika WYŁĄCZNIE odczytuje i
    wyświetla `Badge` typu, nie edytuje go.
19. **`moved_to_review` i `replaces_unbound` — dwa kody ostrzeżeń bez ŻADNEGO odpowiednika w tabelach
    copy §27.4/§27.11.** `moved_to_review`: zmiana treści wymierzona w ISTNIEJĄCĄ encję została
    zamieniona w szkic-cień zamiast zapisana wprost (patrz ADR-0047 defekt (c)) — sekcja przeglądu musi
    to zakomunikować jako „ta propozycja przeniosła się do przeglądu wpisów", nie ukrywać po cichu.
    `replaces_unbound`: `create`'owa `replaces` wskazywała `end`, który nie przetrwał laundrowania —
    sama relacja WCIĄŻ jest proponowana, ginie wyłącznie adnotacja „to zastępuje tamto". Obie potrzebują
    własnych wierszy w `knowledge.relations.*` (analogicznie do istniejących `skip.*`/`advisory.*`).
20. **`resolution.unresolved[]`/`.omitted[]` i `graph_ops.unresolved[]` nie mają ŻADNEGO opisu
    renderowania w §27.** §27.6 opisuje szczegółowo `ambiguous[]` (kilku kandydatów, `Select` w wierszu)
    — ale `unresolved[]` (nazwa, której żaden kandydat w ogóle nie pasował) i `omitted[]` (encja, która
    przekroczyła próg trafności, ale nie zmieściła się w budżecie kontekstu) to inne, nieopisane
    przypadki. Konsekwentnie z GK4: **nie ma dziś interaktywnego `KnowledgeEntityChooser.vue`
    wysyłającego rozstrzygnięcie do serwera** — `ambiguous`/`unresolved`/`omitted` są WYŁĄCZNIE
    informacyjne (część `resolution` na sesji, przegląd bez akcji naprawczej), nie sekcją do
    rozstrzygania kliknięciem. §27.6 w obecnym kształcie (interaktywny `Select` per wzmianka,
    stosowany do całej sesji) **opisuje funkcję, której serwer dziś nie udostępnia** i nie powinien być
    budowany, dopóki taki endpoint nie powstanie.

**Dopisane po Request Changes z przeglądu G10 (backend, kontrakt zamrożony do przeglądu):**

21. **`graph_op_keys: []` teraz naprawdę oznacza „nic" — łącznie z deklarowanymi encjami, których
    wcześniej to NIE dotyczyło.** Wcześniejszy kod tworzył każdą encję (`N<n>`) zadeklarowaną w
    `graph_ops.entities`, niezależnie od tego, które operacje relacji reviewer wybrał — więc
    `entry_ids: []` + `graph_op_keys: []` (odrzucenie CAŁEJ propozycji grafu) mimo to zapisywało co
    najmniej „stwórz Boba", tylko bez relacji do niego. Naprawione: encja powstaje **tylko wtedy, gdy
    nazywa ją WYBRANA operacja relacji** — jest konsekwencją wyboru operacji, nie osobną osią wyboru
    (dokładnie ten sam wzorzec co checkbox par `replaces`/`pair_with` z §27.4/punktu 15: mniej
    kontrolek, nie więcej). **Nie ma i nie będzie klucza `entity:<n>` na wire** — panel przeglądu musi
    pokazywać encję jako konsekwencję zaznaczenia relacji, która jej `depends_on`, a nie jako osobny
    wiersz z własnym checkboxem. `null` (brak selekcji) zachowuje się jak dawniej — tworzy wszystko.
22. **Nowy kod odrzucenia w praniu: `op_cap_reached` ze `scope: entities`.** Cap deklarowanych encji na
    jedną odpowiedź spadł z (faktycznie luźnych) 20 do **8** — teraz jawnie w configu
    (`knowledge.drafting.max_new_entities`, env `KNOWLEDGE_DRAFT_MAX_NEW_ENTITIES`), zrównany z capem
    liczby szkiców na sesję (`max_entries_per_session`, też 8), bo oba kanały tworzą wpisy i czytelnik
    strony nie odróżni, którym przyszła. **Wcześniej nadmiar znikał CICHO** — trzydziesta zadeklarowana
    osoba po prostu nie istniała, a relacje, które ją nazywały, kończyły się myloną `dependency_not_accepted`
    zamiast `op_cap_reached`. Sekcja „Nie zapisano" (§27.4) musi teraz umieć wyświetlić TEN powód dla
    encji, nie tylko dla relacji — dziś brak dla niego wiersza w tabeli powodów pominięcia.
23. **`dates_reversed` — nowy kod, żyjący dziś w TRZECH warstwach pod TĄ SAMĄ nazwą: laundering
    (`graph_ops.rejected[]`), serwis (`422 knowledge_relation_dates_reversed` na `POST`/`PATCH
    .../relations`) i klucz i18n (`knowledge.relations.dates_reversed`) — celowo jedna nazwa wszędzie,
    żeby klient kluczował odrzucenie w podglądzie kreatora i odmowę przy ręcznym zapisie tą samą
    wartością, bez tłumaczenia jednego kodu na drugi.** *(Poprawka względem wcześniejszej wersji tego
    punktu: pisała, że laundering NIE łapie tego przypadku i że wychodzi on dopiero z `accept.skipped[]`
    jako `{code:'refused', reason:'dates_reversed'}` — to było prawdą w chwili pisania i jest
    **odwrotne** teraz.)* Odwrócony przedział z `graph_updates` (`create` z `valid_from`/`valid_to`
    wymyślonymi przez model) jest dziś łapany **PRZED ekranem przeglądu**, w laundrowaniu —
    `{code: 'dates_reversed', type, valid_from, valid_to}` w `graph_ops.rejected[]`, widoczny w sekcji
    „Nie zapisano" (§27.4) razem z pozostałymi dwunastoma powodami odrzucenia, zanim reviewer w ogóle
    zobaczy tę operację jako coś do zaznaczenia. **Operacja z odwróconymi datami nigdy nie trafia do
    `graph_updates[]`, więc nigdy nie jest oferowana do akceptacji — a co za tym idzie, ścieżka
    `accept.skipped[]` z `reason: 'dates_reversed'` nie występuje dla relacji proponowanych przez AI**
    (serwis wciąż ma własną barierę — obronę w głębi — na wypadek czegoś, co ominie laundering, ale
    zwykła ścieżka kreatora już tam nie dociera). **Wyjątek: operacja `end` jest z tego zwolniona
    celowo, w OBU warstwach** — data zakończenia wcześniejsza niż `valid_from` relacji oznacza tam, że
    źle był ustawiony początek, a nie że zakończenie jest błędne; odmowa zostawiłaby relację, której
    nikt nie może wycofać. Reguła w laundrowaniu (`KnowledgeGraphOps::createOp()`) i reguła w serwisie
    (`KnowledgeRelationService::assertDates()`) niosą każda komentarz wskazujący na drugą — jedna reguła,
    stwierdzona dwa razy. Poprawka to `PATCH` (edycja `valid_from`), nie ponowna próba `end`. Modal
    edytora renderuje ten sam kod natychmiast (poza kreatorem, na `POST`/`PATCH .../relations`) obok
    istniejącej walidacji klienckiej `do ≥ od` (§27.9) — to ten sam warunek, teraz egzekwowany po
    stronie serwera w dwóch miejscach zamiast jednego.

## 29. Errata — moduł staje się read-only dla ludzi (ADR-0049)

> Sekcje 1–28 **nie są przepisywane wstecznie** (konwencja tego dokumentu, patrz nagłówek §23).
> §25 (B13) wycofała TWORZENIE wpisu ręcznie — edytor zostawał w trybie edycji. Ten batch (owner:
> „tylko AI zmienia treść wiedzy; człowiek zatwierdza, odrzuca i kieruje promptem") wycofuje
> WSZYSTKO, co §25 zostawiła: edycję istniejącego wpisu, kosz/przywracanie/purge wpisu pojedynczo,
> zmianę kolejności bazy i każdy ręczny czasownik relacji. Backend: 621 testów Knowledge (7
> pominiętych), zielone. Pełny zapis decyzji, trzy nowe abilities i lista reguł, które straciły
> pokrycie: `docs/decisions/ADR-0049-knowledge-authorship-withdrawn.md`. Kontrakt drutu:
> `docs/backend/knowledge-api.md` → „This module is read-only for humans".

### 29.1 Usunięte ekrany i komponenty — zweryfikowane w zbudowanym kodzie

| Ekran / komponent | Plik (był) | Trasa (była) | Status |
| --- | --- | --- | --- |
| Edytor wpisu | `KnowledgeEntryEditorView.vue` | `edit/:slug` | **Plik i trasa nie istnieją.** §25.2 zostawiła edytor wyłącznie w trybie edycji (10 usunięć z 13 dotyczyło TYLKO trybu tworzenia); ten batch usuwa resztę — nie ma już żadnego trybu, bo `PATCH /entries/{entry}` nie istnieje. |
| Kosz | `KnowledgeTrashView.vue` | `next.knowledge.trash` | **Plik i trasa nie istnieją — usunięty w całości, WŁĄCZNIE z zakładką „Bazy".** Patrz 29.4 — to jest nadmiarowe usunięcie, nie tylko konsekwencja wycofania autorstwa wpisów. |
| Edytor relacji | `KnowledgeRelationEditorModal.vue` | (modal, bez własnej trasy) | **Plik nie istnieje.** Obsługiwał trzy operacje naraz — ręczne tworzenie, edycję, awans linku (§27.8) — wszystkie trzy wycofane razem z `POST`/`PATCH .../relations`. |
| „Edytuj" w przeglądzie relacji kreatora | Akcja `[Edytuj]` w `KnowledgeRelationRow.vue` → otwierała `KnowledgeRelationEditorModal` z wypełnionymi polami, opisana w §27.8 jako „poprawka bez kosztu AI" | — | **Akcja nie istnieje w zbudowanym `KnowledgeGraphUpdatesPanel.vue`** (następca panelu relacji w kreatorze) — wiersz oferuje dziś wyłącznie zaznaczenie/odrzucenie, żadnej edycji pola przed akceptacją. Poprawka wymaga dziś kolejnego przebiegu kreatora (koszt AI), nie edycji inline. |
| Przywracanie wersji | Przycisk „Przywróć" w `KnowledgeVersionsDrawer.vue`, `POST /revisions/{revision}/restore` | — | **Przycisk nie istnieje.** Zobacz 29.2 — **PODGLĄD historii i DIFF (§25.3) ZOSTAJĄ**, znika wyłącznie akcja przywrócenia. |

**Rozbieżność złapana przy tej weryfikacji, do naprawienia w kodzie (nie w tym dokumencie):**
nagłówek `KnowledgeVersionsDrawer.vue` mówi w jednym akapicie „RESTORING IS GONE, and the history
stayed" i **trzy akapity niżej** wciąż opisuje „Restoring is confirmed, but NOT as a destructive
act: it appends a new version…" — druga część jest martwą prozą sprzed tego batcha, sprzeczną z
pierwszą, w tym samym pliku. Komponent **zachowuje się poprawnie** (nie ma przycisku „Przywróć" w
szablonie, `useConfirm`/`toast` importy są dziś nieużywane) — to czysto kosmetyczny dług w
komentarzu, zgłoszony tu, bo dokumentacja rendera in-app (`resources/js/next/docs/`) go dziedziczy.

### 29.2 Historia wersji — podgląd i diff zostają, przywracanie znika (uzupełnienie §25.3)

§25.3 opisała diff wersji jako nowość B14, w kontekście edytora, który wtedy jeszcze istniał w
trybie edycji. Po tym batchu drawer jest **wyłącznie do czytania**: `GET /entries/{entry}/revisions`
(niepaginowany, cała historia) + porównanie KAŻDEJ wersji z **bieżącą** treścią wpisu
(`TextDiffView`, tryb `diff`/`content` przez `SegmentedControl`). Uzasadnienie w samym komponencie —
werbatim, bo trafnie streszcza powód: *„READING the history is how anybody audits what the AI wrote
and what a human approved, and that need got larger, not smaller, when authorship moved to the
machine."* Konsekwencja dla `docs/backend/knowledge-api.md`: `POST /revisions/{revision}/restore`
jest **WITHDRAWN**, `GET /revisions` bez zmian.

### 29.3 Filtr warstw grafu — z multi-select na jeden-z-N, z podfiltrem duchów i licznikami w legendzie

Zweryfikowane w `KnowledgeGraphView.vue` (komentarz w kodzie tłumaczy zmianę wprost — cytowany
poniżej). **Niezwiązane z wycofaniem autorstwa** — to osobna, równoległa decyzja UX z tej samej
rundy zmian, ale dotyczy tego samego ekranu, więc dokumentowana razem.

- **Był:** rząd `Button`ów z `aria-pressed`, multi-select (4 niezależne przełączniki = 16 możliwych
  stanów), z licznikiem na każdym toggle.
- **Jest:** `SegmentedControl` **jeden-z-pięciu** — `Wszystkie` (domyślny) / `Relacje` / `Wikilinki` /
  `Podobieństwo` / `Wzmianki`. `Wszystkie` jest jedną z pięciu opcji, nie stanem specjalnym — bez
  niej kompletny obraz bazy (dzisiejszy domyślny widok) byłby nieosiągalny inaczej niż przez
  ręczne zaznaczenie wszystkich czterech warstw po kolei. Uzasadnienie z komentarza w kodzie:
  *„A graph is read by comparing shapes, and comparing them means holding one variable at a time."*
- **Podfiltr duchów — `Select`, nie drugi `SegmentedControl`, widoczny WYŁĄCZNIE gdy warstwa =
  Wikilinki.** Trzy opcje: `z duchami` (domyślna) / `bez duchów` / `tylko duchy`. Czerwony link jest
  PODtypem wikilinku (cel nigdy nie napisany), nie osobną warstwą — stąd podrzędna kontrolka, nie
  peer. Świadomie `Select`, nie karty: *„two card rows side by side read as two equal questions,
  and the reader has to work out which governs which."*
- **Warstwa „Ręczne" (`manual`) zniknęła z UI filtra — enum ZOSTAJE w kodzie, bo mention-scanner i
  legenda wciąż go czytają/rysują (linia stylu `.next-kg-legend-line.is-manual` w
  `KnowledgeGraphLegend.vue`).** `GraphLayerFilter` (typ w `KnowledgeGraphView.vue`) ma pięć
  wartości, `manual` nie jest jedną z nich — celowo: *„a filter offering a layer that is always
  empty teaches a user that the base has none of something it cannot have."* (Nic dziś nie zapisuje
  `source: manual` — to fakt niezależny od wycofania autorstwa wpisów/relacji, patrz
  `docs/backend/knowledge-api.md`, tabela źródeł `KnowledgeLink` w Concepts, niezmieniona przez ten
  batch.)
- **Liczniki przeniesione z togglów do legendy.** Jeden-z-N pokazuje tylko wybieralne opcje, nie
  ich liczności — a legenda (`KnowledgeGraphLegend.vue`, prop `counts`) i tak wymienia każdy rodzaj
  krawędzi naraz, więc „Relacje: 7 · Wikilinki: 19" jest czytelne bez przełączania filtra. Legenda
  sama **nie** rysuje wiersza dla `manual` (`LEGEND_EDGE_KINDS` filtruje go z listy `kind !==
  'manual'`), spójnie z jego brakiem w filtrze powyżej.
- **Stan pusty po wyborze warstwy bez treści** (`layerIsEmpty`) zmienił znaczenie, nie zniknął:
  poprzednio opisywał „wszystkie togle wyłączone" (stan nieosiągalny w jeden-z-N — zawsze dokładnie
  jedna warstwa wybrana); dziś opisuje „wybrana warstwa jest pusta, baza ma inną treść" (np. „Relacje"
  na bazie bez relacji). Wyjście z niego identyczne: powrót do „Wszystkie".

### 29.4 Znaleziony fakt: kosz BAZ nie ma dziś ŻADNEGO ekranu — mimo że backend go nie wycofał

**To jest luka warta zgłoszenia, nie dalsza konsekwencja ADR-0049.** Baza-poziom trash/restore/purge
(`DELETE /bases/{base}`, `POST /bases/{id}/restore`, `DELETE /bases/{id}/force`) jest **jawnie
NIETKNIĘTY** przez wycofanie autorstwa — `KnowledgeBasePolicy`, `KnowledgeBaseController` i store'owe
`restoreBase()`/`purgeBase()` (`app/stores/knowledge.ts`) wszystkie żyją i działają. Ale usunięty
`KnowledgeTrashView.vue` obsługiwał OBIE zakładki naraz („Wpisy" i „Bazy" — §10.1), i poszedł w
całości. `KnowledgeBasesView.vue` (lista baz) nie ma dziś żadnego `?trashed=1`/przełącznika
pokazującego usunięte bazy. **Skutek: `restoreBase()` w store'ze nie ma dziś żadnego wywołującego
komponentu — funkcja jest osiągalna z kodu, nieosiągalna z UI.** Właściciel workspace'u, który
skasował bazę przez pomyłkę, nie ma dziś ekranu, z którego mógłby ją przywrócić. Wymaga decyzji:
albo baza-kosz wraca jako mały, samodzielny ekran (analogicznie do Kosza Dysku), albo restore bazy
przenosi się gdzie indziej (np. link w toaście po skasowaniu, ważny przez ograniczony czas) — obie
opcje poza zakresem tej rundy dokumentacyjnej.

### 29.5 i18n — klucze bez odpowiednika w zbudowanym kodzie

Zweryfikowane pobieżnie przy tym przejściu (nie pełny audyt parytetu PL/EN — poza zakresem tej
rundy): `knowledge.relations.editor.*` (nagłówki/etykiety `KnowledgeRelationEditorModal`) i
`knowledge.entries.trash.*`/`knowledge.trash.*` (Kosz) prawdopodobnie zawierają martwe klucze po
usunięciu obu ekranów — do potwierdzenia przez `vue-tsc`/test parytetu przy najbliższej zmianie w
`app/i18n/{pl,en}.ts`, nie ustalane tu z dokumentacyjnego przejścia samego.

**Domknięte (sprzątanie długu FE):** `knowledge.relations.editor.*` POTWIERDZONE jako martwe i
usunięte z obu katalogów. Ręczne autorstwo relacji jest wycofane celowo, nie odłożone — trasy
`store`/`update`/`end`/`destroy` i ich FormRequesty nie istnieją, polityka odmawia wszystkich
czterech uprawnień, a komponentu edytora nie ma (ADR-0049 D2). Razem z blokiem poszły typy żądań
`KnowledgeRelationCreatePayload` / `…UpdatePayload` / `…EndPayload` (zero konsumentów) oraz pole
`promote_link_id` i klucze `promote*` po wycofanym awansie sugestii. Jedyny żywy klucz z tego bloku,
`showExisting` (ostrzeżenie o duplikacie w `KnowledgeGraphUpdatesPanel`), przeniesiony na
`knowledge.relations.showExisting` — blok o nazwie `editor` z jednym kluczem wysyłałby następną
osobę na poszukiwanie komponentu, którego nie ma. **`KnowledgeRelationOrigin` ZOSTAJE w komplecie**
(`human`/`composer`/`promoted`): to rzutowanie na zapisaną kolumnę i publikowana wartość API, więc
wiersz sprzed pivotu wciąż ją niesie.

`knowledge.entries.trash.*`/`knowledge.trash.*` — sprawdzone przy tej samej okazji: **też nie mają
dziś żadnego wywołującego** (`grep` po pełnych nazwach, poza samymi katalogami). NIE usunięte:
przywracanie skasowanej bazy to otwarta decyzja produktowa z §29.4 powyżej, a klucze są jedynym
zapisem tego, jak ten ekran brzmiał. Do domknięcia razem z tamtą decyzją.
