# FilterTabs Stage 2 — Etap 3: Specyfikacja UX + wizualna (Saved Views)

Status: gotowe do implementacji przez `frontend-agent` (Etap 4).
Zakres: TYLKO projekt UX/wizualny + statyczny markup/klasy. Logika (Pinia/composable, snapshot/dirty,
serializacja D1, wywołania API) NIE jest częścią tego dokumentu — to robi Etap 4.

Backend (moduł `FilterTabs`) jest gotowy. Kontrakt zasobu (`FilterTabResource`):

```
{ id, name, icon (IconEnum value | null), filters, sort_order }
```

Operacje API (`auth:sanctum`):

| Akcja   | Metoda + URL                         | Uwagi |
|---------|--------------------------------------|-------|
| list    | `GET /filter-tabs?context=<ctx>`     | per context (np. `tasks`) |
| create  | `POST /filter-tabs`                   | limit 30/context → 422 `context: filter_tabs.errors.limit_reached`; unikalna nazwa → 422 `name: filter_tabs.errors.name_taken`; payload >16KB → 422 `filters: filter_tabs.errors.filters_too_large` |
| update  | `PUT /filter-tabs/{id}`              | `name`/`icon`/`filters` opcjonalne; te same komunikaty 422 |
| delete  | `DELETE /filter-tabs/{id}`           | |
| reorder | `PUT /filter-tabs/reorder`           | `ids[]`; zły zestaw → 422 `ids: filter_tabs.errors.invalid_reorder_set` |

> Backend zwraca KLUCZE i18n jako komunikaty walidacji (np. `filter_tabs.errors.name_taken`).
> Frontend mapuje je przez `t()` (klucze podane niżej). Nigdy nie wyświetlać surowego klucza.

---

## 1. Nazewnictwo i terminologia (D6 — odróżnienie od Tabs)

Komponent `Tabs.vue` to `role="tablist"` przełączający panele Active/Archive/Trash i ZOSTAJE bez zmian.
Pasek zapisanych filtrów to ODRĘBNA rzecz semantycznie i wizualnie.

Reguły nazewnictwa (kod + UI + ARIA):

- W UI i kluczach i18n używamy **"view" / "saved view" / "widok"** — NIGDY słowa "tab"/"zakładka".
- Backend nazywa byt `FilterTab` — to nazwa techniczna; frontend mapuje ją na domenowe "view".
  W komponentach frontu używać nazw `SavedViewsBar`, `SavedViewPill`, `SaveViewModal`, `view`/`views`.
- ARIA paska: `role="toolbar"` (NIE `tablist`), `aria-label="tasks.savedViews.barLabel"`.
- Pojedyncza pigułka widoku to przycisk akcji w toolbarze (`<button>` opakowane w `Button`),
  NIE `role="tab"`. Aktywny widok oznaczamy `aria-current="true"` (nie `aria-selected`).

Dzięki temu czytnik ekranu rozróżnia "toolbar widoków" (filtry zapisane) od "tablisty Active/Archive/Trash".

---

## 2. Rozmieszczenie w `TasksView.vue`

`SavedViewsBar` wstawiamy **nad** `FilterBar`, pod `PageHeader`:

```
PageHeader
SavedViewsBar      ← NOWE (Etap 4)
FilterBar
Tabs (Active/Archive/Trash)  ← bez zmian (D3: bucket NIE jest częścią widoku)
```

Kontekst (`context`) dla Tasks = `"tasks"` (stała przekazywana do composable list/create w Etapie 4).

Co widok zapisuje (D3): wyłącznie zawartość paska filtrów (`filters` = aktualny obiekt `TaskFilters`
budowany w `TasksView`). Bucket Active/Archive/Trash (`tab`) NIE jest zapisywany ani przywracany.

---

## 3. SavedViewsBar — anatomia

Kontener (spójny z `FilterBar`: ta sama karta, ten sam promień/cień, by tworzyły wizualny stos):

```html
<section
  role="toolbar"
  :aria-label="t('tasks.savedViews.barLabel')"
  class="next-saved-views flex flex-wrap items-center gap-next-2
         rounded-next-lg border border-next-border bg-next-card p-next-2 shadow-next-xs"
>
  <!-- 1. Ikona sekcji (label paska) -->
  <span class="flex items-center gap-next-1_5 pr-next-1 text-next-sm font-next-medium text-next-muted-foreground">
    <Icon name="bookmark" class="shrink-0" aria-hidden="true" />
    <span>{{ t('tasks.savedViews.sectionLabel') }}</span>
  </span>

  <!-- 2. Pigułki widoków (overflow: WRAP, jak chipy w FilterBar — bez +N) -->
  <SavedViewPill v-for="view in views" :key="view.id" ... />

  <!-- 3. Trailing: przycisk Zapisz jako (zawsze ostatni) -->
  <Button size="sm" variant="outline" leading-icon="plus" @click="onSaveAs">
    {{ t('tasks.savedViews.saveAs') }}
  </Button>
</section>
```

Zachowanie overflow: pigułki WRAP na nową linię (ta sama zasada co chipy w `FilterBar` — nie ma `+N`).
"Zapisz jako" jest stałą kontrolką i dzięki `gap`/`wrap` zawsze pozostaje za listą.

### 3.1 SavedViewPill — anatomia jednej pigułki

Pigułka = grupa: [ikona] [nazwa] [badge "zmodyfikowano" — tylko aktywny+dirty] [menu ⋯ (kebab)].
Reguła trailing-affordance: kontrolki WARUNKOWE przed STAŁYMI; stała kontrolka (kebab ⋯) nigdy się nie przesuwa.

Kolejność trailingu w pigułce (od lewej): badge "dirty" (warunkowy) → kebab ⋯ (stały, zawsze obecny).

```html
<!-- Pigułka aktywna -->
<div
  class="next-view-pill group inline-flex items-center gap-next-1 rounded-next-full
         h-8 pl-next-2 pr-next-1 text-next-sm transition-colors
         duration-[var(--duration-next-fast)]"
  :class="isActive
    ? 'bg-next-primary-subtle text-next-primary-subtle-foreground ring-1 ring-next-primary/40'
    : 'bg-next-muted text-next-muted-foreground hover:text-next-fg hover:bg-next-accent'"
>
  <!-- Korpus klikalny = aktywacja widoku -->
  <button
    type="button"
    class="inline-flex items-center gap-next-1_5 min-w-0 outline-none
           focus-visible:ring-2 focus-visible:ring-next-ring rounded-next-full"
    :aria-current="isActive ? 'true' : undefined"
    :aria-label="t('tasks.savedViews.activate', { name: view.name })"
    @click="onActivate(view)"
  >
    <Icon v-if="resolvedIcon" :name="resolvedIcon" class="shrink-0" aria-hidden="true" />
    <span class="truncate max-w-[14ch]">{{ view.name }}</span>
  </button>

  <!-- WARUNKOWY badge dirty (tylko aktywny widok, gdy filtry ≠ snapshot) -->
  <Badge
    v-if="isActive && isDirty"
    variant="warning"
    tone="subtle"
    size="sm"
    icon="pencil"
    :aria-label="t('tasks.savedViews.dirtyAria', { name: view.name })"
  >
    {{ t('tasks.savedViews.dirtyBadge') }}
  </Badge>

  <!-- STAŁA kontrolka: menu per widok (kebab) -->
  <Button
    size="icon-xs"
    variant="ghost"
    leading-icon="more-vertical"
    :aria-label="t('tasks.savedViews.menuAria', { name: view.name })"
    @click="onOpenMenu(view)"
  />
</div>
```

Ikona widoku: `view.icon` to wartość `IconEnum` z backendu — mapujemy ją na `IconName` przez
istniejący resolver `resolveLabelIcon()` z `resources/js/next/ui/forms/labelIcon.ts`
(ten sam mechanizm co Labels — NIE tworzyć drugiego mapowania). Gdy `icon === null`,
ikona pigułki to fallback (np. `bookmark`) ALBO brak ikony — zalecane: brak ikony, by odróżnić
"widok bez ikony" od "widok z ikoną zakładki". Decyzja: BRAK ikony gdy `null`.

### 3.2 Menu per widok (action menu)

Otwierane z kebaba. Reużyć istniejący wzorzec menu z `next` (DropdownMenu/menu w `ui/`).
Pozycje (z ikonami — zasada "ikona + tekst", nigdy sam kolor):

| Pozycja            | Ikona           | Klucz i18n                          | Akcja (Etap 4) |
|--------------------|-----------------|-------------------------------------|----------------|
| Edytuj widok       | `pencil`        | `tasks.savedViews.menu.edit`        | otwiera SaveViewModal w trybie edit |
| Przesuń w górę     | `chevron-up`    | `tasks.savedViews.menu.moveUp`      | reorder; disabled gdy pierwszy |
| Przesuń w dół      | `chevron-down`  | `tasks.savedViews.menu.moveDown`    | reorder; disabled gdy ostatni |
| (separator)        |                 |                                     | |
| Usuń widok         | `trash-2`       | `tasks.savedViews.menu.delete`      | otwiera ConfirmDialog (danger) |

Reorder przez menu (move up/down) to GŁÓWNA, dostępna z klawiatury afordancja reorderu
(zgodna z trailing-affordance: nie wprowadzamy drag-uchwytu, który łamałby układ pigułek).
Pozycje disabled (pierwszy/ostatni) zachowują widoczność i mają `aria-disabled` + tooltip
wyjaśniający ("Już na początku/końcu") — zgodnie z regułą "disabled musi być zrozumiałe".
Pozycja "Usuń" stylizowana jako destrukcyjna (`text-next-danger`), ale dodatkowo z ikoną kosza.

---

## 4. Trzy warianty chipów w FilterBar (`tabState` per chip/wartość)

Frontend (Etap 4) dołoży do `ActiveFilter` / `ActiveFilterValue` pole `tabState`:
`'tab-active' | 'extra' | 'tab-disabled'` (gdy brak aktywnego widoku — wszystkie chipy są neutralne
jak dziś, tzn. traktowane jak `extra` wizualnie bez przekreśleń; patrz §4.4).

> Nazwa pola pozostaje `tabState` (zgodnie z planem), mimo że w UI mówimy "view".
> To nazwa wewnętrzna kontraktu danych, niewidoczna dla użytkownika.

`FilterBar` musi:
- renderować każdy chip wg jego `tabState`,
- dla `tab-disabled` renderować chip NIEusuwalny, ale z afordancją "przywróć" (ikona) →
  emitować nowy event `restore-filter` (key) zamiast `remove-filter`,
- nadal emitować `remove-filter` dla `tab-active` i `extra`.

### 4.1 Tabela 3 wariantów (stan → tokeny/klasy → afordancja a11y)

| Stan          | Znaczenie | Badge variant/tone | Klasy/tokeny `next-*` (light+dark via tokeny) | Afordancja NIE-kolorowa (a11y) |
|---------------|-----------|--------------------|-----------------------------------------------|--------------------------------|
| `tab-active`  | Filtr jest i w snapshocie, i w bieżącym stanie | `neutral` / `subtle` | `bg-next-muted text-next-muted-foreground` (dziś domyślny). Subtelny, bez dodatkowego obramowania | ✕ usuwalny (jak dziś). Brak dodatkowego oznaczenia — to stan bazowy. `aria-label`: `…removeFilter` |
| `extra`       | Filtr tylko w bieżącym stanie (dodany ponad widok) | `primary` / `subtle` | `bg-next-primary-subtle text-next-primary-subtle-foreground ring-1 ring-next-primary/30` | Leading-icon `plus` + tekst statusu w `aria-label`: `…chipExtraAria`. Wyraźniejszy (ring + ikona +), nie tylko kolor |
| `tab-disabled`| Filtr był w snapshocie, usunięty z bieżącego stanu | `neutral` / `subtle` (wyszarzony) | `bg-next-muted/60 text-next-muted-foreground/70 line-through opacity-70 border border-dashed border-next-border` | Tekst PRZEKREŚLONY (`line-through`) + przerywane obramowanie (wzór, nie kolor) + ikona `rotate-ccw` (przywróć) zamiast ✕. `aria-label` chipa: `…chipDisabledAria`; przycisk: `…restoreFilter`. Klik = `restore-filter` |

A11y — nie tylko kolor (potrójne kodowanie dla każdego stanu):
- `tab-active`: brak markera (baseline) + ✕ usuwalny.
- `extra`: ikona `plus` + ring + `aria-label` zawiera słowo "dodany / added".
- `tab-disabled`: `line-through` (wzór tekstu) + `border-dashed` (wzór ramki) + ikona `rotate-ccw`
  + `aria-label` zawiera "usunięty z widoku / removed from view".

Dark mode: wszystkie powyższe klasy używają wyłącznie tokenów `next-*`, które mają warianty dark
w `@theme` (`primary-subtle`, `muted`, `border`, `muted-foreground`). Nie używać surowych kolorów.
`opacity`/`line-through`/`border-dashed` działają identycznie w obu trybach.

### 4.2 Markup chipa — rozszerzenie pętli w `FilterBar.vue`

Aktualnie `FilterBar` renderuje jednolite `Badge variant="neutral" tone="subtle" removable`.
Etap 4 zmienia pętlę `flatChips` tak, by `FlatChip` niósł `tabState` (propagowane z `ActiveFilterValue`/
`ActiveFilter`). Szkic:

```html
<!-- tab-disabled: NIEusuwalny, z przyciskiem przywróć -->
<Badge
  v-if="chip.tabState === 'tab-disabled'"
  variant="neutral"
  tone="subtle"
  class="line-through opacity-70 border border-dashed border-next-border"
  :aria-label="t('tasks.savedViews.chipDisabledAria', { label: chip.rawLabel })"
>
  {{ chip.label }}
  <template #trailing>
    <!-- jeśli Badge nie ma slotu trailing, użyć osobnego Button obok; patrz nota niżej -->
  </template>
</Badge>

<!-- extra -->
<Badge
  v-else-if="chip.tabState === 'extra'"
  variant="primary"
  tone="subtle"
  icon="plus"
  class="ring-1 ring-next-primary/30"
  removable
  :remove-label="t('filterBar.removeFilter', { label: chip.label })"
  @remove="removeFilter(chip.key)"
>
  {{ chip.label }}
</Badge>

<!-- tab-active (baseline) -->
<Badge
  v-else
  variant="neutral"
  tone="subtle"
  removable
  :remove-label="t('filterBar.removeFilter', { label: chip.label })"
  @remove="removeFilter(chip.key)"
>
  {{ chip.label }}
</Badge>
```

> Uwaga implementacyjna (Etap 4): prymityw `Badge` ma dziś tylko opcjonalny ✕ (`removable`),
> bez slotu na inną ikonę trailing. Dla `tab-disabled` potrzebny jest przycisk "przywróć"
> (`rotate-ccw`) zamiast ✕. Dwie zgodne z DS opcje (wybór należy do frontend-agenta):
> (a) dodać do `Badge` prop `trailingAction` { icon, label } emitujący `action` — preferowane,
>     bo nie tworzy jednorazowego wzorca i da się reużyć; LUB
> (b) zawinąć Badge + osobny `<Button size="icon-xs" variant="ghost" leadingIcon="rotate-ccw">`
>     w jeden `inline-flex` (bez modyfikacji prymitywu).
> Rekomendacja: (a). To minimalne, reużywalne rozszerzenie prymitywu, nie one-off.

### 4.3 Nowy event `restore-filter`

`FilterBar` dorzuca emit `(e: 'restore-filter', key: string)`. `TasksView` (Etap 4) obsługuje go
przywracając pojedynczy filtr ze snapshotu aktywnego widoku. `remove-filter` i `clear-all` bez zmian.

### 4.4 Brak aktywnego widoku

Gdy żaden widok nie jest aktywny (lub widoków nie ma): `tabState` wszystkich chipów = `undefined`/
brak → renderowane jak baseline (`neutral/subtle`, usuwalne). Żadnych przekreśleń ani ringów.
To zachowuje obecny wygląd `FilterBar` dla użytkowników bez widoków (zero regresji wizualnej).

---

## 5. Modal "Zapisz jako / Edytuj widok" (na bazie Modal + Button)

Jeden komponent `SaveViewModal` w dwóch trybach (`mode: 'create' | 'edit'`), `Modal size="md"`.

Tytuł: create → `tasks.savedViews.modal.createTitle`; edit → `tasks.savedViews.modal.editTitle`.
Opis (#description): `tasks.savedViews.modal.subtitle` (wyjaśnia, że widok zapisuje aktualne filtry).

### 5.1 Pola

1. **Nazwa** (`TextInput`):
   - `label`: `tasks.savedViews.modal.nameLabel`, `placeholder`: `…namePlaceholder`.
   - Walidacja klient: required + max 60 znaków (lustro backendu). Licznik/hint `…nameHint` (np. "Maks. 60 znaków").
   - Walidacja serwer 422 `name` = `filter_tabs.errors.name_taken` → komunikat pod polem
     `tasks.savedViews.errors.nameTaken`. Mapowanie: gdy odpowiedź 422 niesie ten klucz, pokaż go
     przy polu nazwy i NIE zamykaj modalu.

2. **Ikona** (picker ikon — wzorzec z Labels):
   - Reużyć wzorzec pickera z `IconInput` (legacy Labels): trigger (kwadracik z podglądem ikony +
     nazwa + ✕ clear + chevron) → popover z polem wyszukiwania + siatką ikon (grid, tooltip z nazwą,
     aktywna ma ring).
   - W `next` nie ma jeszcze `IconInput`. Etap 4: zbudować `next`-owy odpowiednik (np.
     `ui/forms/IconSelect.vue`) zgodny z konwencjami `next` (FieldShell/FieldPopover/Button/Icon),
     wizualnie i interakcyjnie kalkujący wzorzec z Labels. Zestaw ikon = lokalny rejestr `next`
     (`ui/primitives/icons.ts`) — wartość wybrana musi być serializowalna do `IconEnum` backendu;
     zapis: skoro picker operuje na nazwach `next`, a backend chce `IconEnum`, Etap 4 musi
     ograniczyć wybór do ikon, które mają poprawny odpowiednik `IconEnum`, albo mapować nazwę
     `next` → wartość `IconEnum` przy zapisie. **OTWARTE PYTANIE — patrz §8 (R1).**
   - Ikona jest OPCJONALNA (`nullable`), clear dozwolony. Label: `…iconLabel` + `(common.optional)`.

3. **Format dat terminu (D1)** — sekcja widoczna TYLKO gdy snapshot zawiera konkretne daty
   (`date_from` i/lub `date_to`). Presety (`today`/`this_week`/…) NIE mają tego wyboru.
   Dla KAŻDEJ obecnej daty (osobno `from`, osobno `to`) pokaż `SegmentedControl` (2 opcje):
     - `absolute` → `tasks.savedViews.modal.dateMode.absolute` ("Sztywna data")
     - `relative` → `tasks.savedViews.modal.dateMode.relative` ("Względem dziś")
   Pod kontrolką hint wyjaśniający różnicę: `tasks.savedViews.modal.dateMode.hint`
   ("Sztywna data: zawsze ta sama data. Względem dziś: przesunięcie N dni od dnia wczytania widoku,
   przeliczane przy każdym otwarciu.").
   Dla każdego pola pokaż podgląd: dla `absolute` sformatowaną datę (`dd.mm.yyyy`); dla `relative`
   wyliczone przesunięcie ("za 3 dni" / "3 dni temu" / "dziś") — tekst z `…dateMode.offsetPreview`
   (parametr {days} + wariant znaku). Sama LOGIKA serializacji (zamiana absolutnej daty na offset
   i odwrotnie) należy do Etapu 4 (D1) — tu definiujemy tylko UI wyboru i copy.

   Layout sekcji dat (jointed fields — z referencji UX): `from` i `to` w dwóch kolumnach na ≥`next-sm`,
   sklejone wizualnie wspólnym nagłówkiem sekcji `…dateMode.sectionLabel`; na mobile → jedna kolumna.

### 5.2 Stopka modalu (#footer)

Tryb `create`:
- `Button variant="ghost"` Anuluj (`common.cancel`) → close.
- `Button variant="primary"` `…modal.create` ("Zapisz widok"), `loading` podczas zapisu,
  `disabled` gdy nazwa pusta.

Tryb `edit`:
- `Button variant="ghost"` Anuluj.
- `Button variant="primary"` `…modal.saveChanges` ("Zapisz zmiany"), `loading`/`disabled` j.w.

Stan ładowania przycisku: prymityw `Button` ma wbudowane `loading` (spinner zamiast leading-icon,
zachowana szerokość, `aria-busy`) — używać go, nie własnego spinnera.

Zachowanie po sukcesie: zamknąć modal, `useToast` sukces (`…toast.created` / `…toast.updated`),
aktywować zapisany/zmieniony widok. Po błędzie 422: NIE zamykać, pokazać komunikat przy właściwym polu
(name/filters), reszta błędów → toast `…toast.saveError`.

---

## 6. Flow: dirty / Zapisz / Zapisz jako / usuwanie

### 6.1 Dirty (aktywny widok zmodyfikowany)

- Composable (Etap 4) trzyma snapshot `filters` aktywnego widoku i porównuje z bieżącym `filters`.
- `isDirty === true` → badge "zmodyfikowano" na aktywnej pigułce (§3.1) + w pasku pojawiają się
  chipy `extra`/`tab-disabled` w FilterBar (§4).
- Gdy aktywny i dirty, "Zapisz jako" w pasku zyskuje towarzysza — przycisk **"Zapisz"** (nadpisz aktywny):

```html
<!-- W SavedViewsBar, gdy isActive && isDirty -->
<Button size="sm" variant="primary" leading-icon="check" @click="onSaveActive">
  {{ t('tasks.savedViews.save') }}     <!-- "Zapisz" = update aktywnego -->
</Button>
<Button size="sm" variant="outline" leading-icon="plus" @click="onSaveAs">
  {{ t('tasks.savedViews.saveAs') }}   <!-- "Zapisz jako" = create nowy -->
</Button>
```

- Gdy NIE ma aktywnego widoku, ale są filtry: tylko "Zapisz jako" (create).
- "Zapisz" (update) wykonuje `PUT /filter-tabs/{id}` z bieżącymi `filters` (+ ewentualnie modal
  ikony/nazwy NIE jest potrzebny — "Zapisz" nadpisuje cicho snapshot; toast `…toast.updated`).
- "Zapisz jako" zawsze otwiera `SaveViewModal` w trybie create (z prefillem dat D1).

Opcjonalnie (zalecane): przy próbie aktywacji INNEGO widoku, gdy bieżący jest dirty — ConfirmDialog
ostrzegający o utracie niezapisanych zmian (`…confirm.discardTitle/Message`). **OTWARTE — §8 (R2).**

### 6.2 Usuwanie widoku (ConfirmDialog, danger)

```html
<ConfirmDialog
  v-model:open="confirmDeleteOpen"
  variant="danger"
  :title="t('tasks.savedViews.confirm.deleteTitle')"
  :message="t('tasks.savedViews.confirm.deleteMessage', { name: viewToDelete?.name })"
  :confirm-label="t('common.delete')"
  :cancel-label="t('common.cancel')"
  :loading="deleting"
  @confirm="onConfirmDelete"
/>
```

- Komunikat ZAWSZE zawiera nazwę widoku (i18n z parametrem `{name}`).
- `variant="danger"` → focus na Anuluj (bezpieczny default), przycisk Usuń destrukcyjny.
- `loading` blokuje Esc/scrim/cancel podczas żądania DELETE.

### 6.3 Reorder

- Z menu pigułki: "Przesuń w górę"/"w dół" → `PUT /filter-tabs/reorder` z nową kolejnością `ids`.
- Pozycje disabled na krańcach (pierwszy/ostatni) — widoczne, `aria-disabled`, tooltip wyjaśniający.
- Zgodnie z trailing-affordance NIE dodajemy drag-uchwytu w pigułce (zmieniałby pozycję stałego
  kebaba i powodowałby przeskoki układu). Reorder jest w pełni klawiaturowy przez menu.

---

## 7. Stany: empty / loading / error

### 7.1 Empty (brak zapisanych widoków)

Pasek nadal się renderuje (ikona sekcji + "Zapisz jako"), zamiast pigułek — krótki tekst zachęty:

```html
<span class="text-next-sm text-next-muted-foreground">
  {{ t('tasks.savedViews.empty') }}   <!-- "Brak zapisanych widoków. Ustaw filtry i zapisz je tutaj." -->
</span>
<Button size="sm" variant="outline" leading-icon="plus" @click="onSaveAs"
        :disabled="!hasActiveFilters">
  {{ t('tasks.savedViews.saveAs') }}
</Button>
```

- "Zapisz jako" `disabled` gdy brak aktywnych filtrów (nic do zapisania) — z tooltipem
  `…saveAsDisabledHint` ("Najpierw ustaw filtry"). Disabled musi być zrozumiały.

### 7.2 Loading (skeleton pigułek — KILKA, naśladujące realny element)

Reguła skeleton: skeleton naśladuje realny element, pokazujemy kilka, NIE spinner+"Loading".

```html
<div class="flex items-center gap-next-2" aria-hidden="true">
  <span
    v-for="n in 4"
    :key="n"
    class="h-8 rounded-next-full bg-next-muted animate-pulse"
    :style="{ width: ['7rem','5.5rem','8rem','6rem'][n-1] }"
  />
</div>
<span class="sr-only">{{ t('tasks.savedViews.loading') }}</span>
```

- Kilka (4) placeholderów o ZRÓŻNICOWANej szerokości (jak realne pigułki o różnych nazwach),
  kształt pigułki (`rounded-next-full`, `h-8`), token `bg-next-muted`.
- Ikona sekcji i "Zapisz jako" mogą być widoczne od razu (są statyczne).

### 7.3 Error (nie udało się wczytać widoków)

Pasek pokazuje inline błąd z akcją ponów (nie blokuje reszty strony — FilterBar działa dalej):

```html
<span class="flex items-center gap-next-1_5 text-next-sm text-next-danger">
  <Icon name="alert-triangle" aria-hidden="true" />
  {{ t('tasks.savedViews.loadError') }}
</span>
<Button size="sm" variant="ghost" leading-icon="rotate-ccw" @click="onRetry">
  {{ t('common.retry') }}
</Button>
```

- Błędy MUTACJI (create/update/delete/reorder) raportujemy przez `useToast` (nie inline w pasku),
  poza 422 walidacji nazwy/filters w modalu (inline przy polu).

---

## 8. Ryzyka / otwarte pytania UX (wymagają decyzji)

- **R1 (blokujące dla pickera ikon): mapowanie zestawu ikon `next` ↔ `IconEnum` backendu.**
  Backend waliduje `icon` przez `Rule::enum(IconEnum::class)` — akceptuje TYLKO wartości z `IconEnum`.
  Picker w stylu Labels w `next` operuje na lokalnym rejestrze `ui/primitives/icons.ts`, który jest
  (per `labelIcon.ts`) "częściowo rozłączny" z `IconEnum`. Trzeba zdecydować:
  (a) picker pokazuje TYLKO przecięcie `next.icons ∩ IconEnum` (część glifów `next` zniknie), czy
  (b) picker pokazuje pełny `IconEnum` (trzeba renderować glify z legacy/IconEnum, nie z rejestru `next`), czy
  (c) wprowadzić odwrotną mapę `next → IconEnum` przy zapisie (i `IconEnum → next` przy odczycie,
      już istnieje jako `resolveLabelIcon`).
  Rekomendacja UX: (c) — spójność z Labels, jeden kierunek odczytu już mamy. Ale potrzebny jest
  reverse-map i lista dozwolonych ikon. To decyzja produktowo-techniczna — **proszę o potwierdzenie
  przed Etapem 4**, bo determinuje, które ikony użytkownik w ogóle zobaczy.

- **R2 (UX, nie blokujące): ochrona niezapisanych zmian.** Czy przy aktywacji innego widoku /
  "Wyczyść wszystko" / opuszczeniu strony, gdy aktywny widok jest dirty, pokazywać ConfirmDialog
  "Odrzucić niezapisane zmiany?" Domyślnie proponuję: TAK przy przełączaniu na inny widok, NIE przy
  "Wyczyść wszystko" (to jawna intencja). Proszę o akceptację domyślnego zachowania.

- **R3 (UX): zachowanie przy aktywacji widoku a bucket (D3).** Widok przywraca tylko `filters`,
  nie zmienia Active/Archive/Trash. Potwierdzenie, że to pożądane (np. zapisany widok "Pilne"
  działa w obrębie aktualnie wybranego bucketa) — zgodne z D3, zgłaszam dla świadomości produktu.

- **R4 (UX, drobne): czy "Zapisz" (nadpisz aktywny) ma być cichy czy z potwierdzeniem.**
  Proponuję cichy + toast (mniej tarcia; nadpisanie jest odwracalne przez ponowną edycję).
  Jeśli widoki bywają współdzielone/krytyczne — rozważyć ConfirmDialog. Domyślnie: cichy.

- **R5 (techniczne, dla `Badge`): rozszerzenie prymitywu o `trailingAction`** (§4.2 opcja a).
  To jedyna ingerencja w prymityw. Jeśli nieakceptowana, fallback = opakowanie (opcja b),
  ale to mniej spójne. Rekomendacja: zaakceptować małe, reużywalne rozszerzenie `Badge`.

---

## 9. Responsive

- `SavedViewsBar`: `flex-wrap` — pigułki zawijają się na wąskich ekranach; ikona sekcji + "Zapisz/
  Zapisz jako" zostają (wrap razem z resztą). Na bardzo wąskich ekranach nazwa pigułki truncuje
  (`max-w-[14ch]`), kebab i badge zawsze widoczne.
- `SaveViewModal`: sekcja dat `from/to` — 2 kolumny od `next-sm`, 1 kolumna poniżej (jointed fields).
- Picker ikon: popover dopasowuje szerokość do triggera; siatka ikon `grid-cols-6`/`grid-cols-8`
  responsywnie (mniej kolumn na mobile).

## 10. Accessibility (zbiorczo)

- Pasek: `role="toolbar"`, `aria-label`. Pigułka aktywna: `aria-current="true"`. Kebab: `aria-label`
  z nazwą widoku. Menu: zarządzanie focusem z istniejącego DropdownMenu (Esc zamyka, strzałki nawigują).
- Chipy filtrów: każdy stan ma potrójne kodowanie (kolor + ikona/wzór + `aria-label` z tekstem stanu).
  `tab-disabled` nieusuwalny ✕; zamiast tego klawiaturowo dostępny przycisk "przywróć".
- Modal/ConfirmDialog: dziedziczą focus-trap, `role="dialog"`, `aria-modal`, return-focus z `Modal.vue`.
  ConfirmDialog danger → focus na Anuluj.
- Wszystkie akcje/close to prymityw `Button` (w tym kebab, retry, close, restore) — nigdy surowy
  `<button>` poza wewnętrznym klikalnym korpusem pigułki (który i tak ma focus-visible ring + aria).
- Skeleton `aria-hidden`, z towarzyszącym `sr-only` tekstem ładowania.
- Reorder w pełni z klawiatury (menu), bez drag-only.

---

## 11. Klucze i18n (PL + EN) — do dodania do BOTH `en.ts` i `pl.ts`

Dodać pod istniejący namespace `tasks` (nowy pod-namespace `savedViews`) oraz dwa klucze błędów
mapujące komunikaty backendu. Zachować 1:1 parytet kluczy (wymóg testu i18n).

### 11.1 `tasks.savedViews.*`

| Klucz | EN | PL |
|-------|----|----|
| `savedViews.barLabel` | `Saved views` | `Zapisane widoki` |
| `savedViews.sectionLabel` | `Views` | `Widoki` |
| `savedViews.activate` | `Apply view: {name}` | `Zastosuj widok: {name}` |
| `savedViews.menuAria` | `Actions for view: {name}` | `Akcje widoku: {name}` |
| `savedViews.dirtyBadge` | `Modified` | `Zmodyfikowano` |
| `savedViews.dirtyAria` | `View {name} has unsaved changes` | `Widok {name} ma niezapisane zmiany` |
| `savedViews.save` | `Save` | `Zapisz` |
| `savedViews.saveAs` | `Save as…` | `Zapisz jako…` |
| `savedViews.saveAsDisabledHint` | `Set some filters first` | `Najpierw ustaw filtry` |
| `savedViews.empty` | `No saved views yet. Set filters and save them here.` | `Brak zapisanych widoków. Ustaw filtry i zapisz je tutaj.` |
| `savedViews.loading` | `Loading saved views…` | `Wczytywanie zapisanych widoków…` |
| `savedViews.loadError` | `Couldn't load saved views.` | `Nie udało się wczytać zapisanych widoków.` |

### 11.2 `tasks.savedViews.menu.*`

| Klucz | EN | PL |
|-------|----|----|
| `savedViews.menu.edit` | `Edit view` | `Edytuj widok` |
| `savedViews.menu.moveUp` | `Move up` | `Przesuń w górę` |
| `savedViews.menu.moveDown` | `Move down` | `Przesuń w dół` |
| `savedViews.menu.moveUpDisabled` | `Already first` | `Już na początku` |
| `savedViews.menu.moveDownDisabled` | `Already last` | `Już na końcu` |
| `savedViews.menu.delete` | `Delete view` | `Usuń widok` |

### 11.3 `tasks.savedViews.modal.*`

| Klucz | EN | PL |
|-------|----|----|
| `savedViews.modal.createTitle` | `Save view` | `Zapisz widok` |
| `savedViews.modal.editTitle` | `Edit view` | `Edytuj widok` |
| `savedViews.modal.subtitle` | `A view stores the current filters so you can reapply them later.` | `Widok zapisuje bieżące filtry, byś mógł je później ponownie zastosować.` |
| `savedViews.modal.nameLabel` | `Name` | `Nazwa` |
| `savedViews.modal.namePlaceholder` | `e.g. Overdue & mine` | `np. Po terminie i moje` |
| `savedViews.modal.nameHint` | `Up to 60 characters` | `Maks. 60 znaków` |
| `savedViews.modal.iconLabel` | `Icon` | `Ikona` |
| `savedViews.modal.iconPlaceholder` | `Choose an icon…` | `Wybierz ikonę…` |
| `savedViews.modal.iconSearch` | `Search icons…` | `Szukaj ikony…` |
| `savedViews.modal.iconEmpty` | `No icons match your search.` | `Brak ikon pasujących do wyszukiwania.` |
| `savedViews.modal.create` | `Save view` | `Zapisz widok` |
| `savedViews.modal.saveChanges` | `Save changes` | `Zapisz zmiany` |

### 11.4 `tasks.savedViews.modal.dateMode.*` (D1)

| Klucz | EN | PL |
|-------|----|----|
| `savedViews.modal.dateMode.sectionLabel` | `Deadline dates` | `Daty terminu` |
| `savedViews.modal.dateMode.fromLabel` | `From date` | `Data od` |
| `savedViews.modal.dateMode.toLabel` | `To date` | `Data do` |
| `savedViews.modal.dateMode.absolute` | `Fixed date` | `Sztywna data` |
| `savedViews.modal.dateMode.relative` | `Relative to today` | `Względem dziś` |
| `savedViews.modal.dateMode.hint` | `Fixed date stays the same. Relative shifts by a number of days from today, recalculated each time the view is loaded.` | `Sztywna data jest zawsze taka sama. Względna to przesunięcie o liczbę dni od dziś, przeliczane przy każdym wczytaniu widoku.` |
| `savedViews.modal.dateMode.offsetToday` | `today` | `dziś` |
| `savedViews.modal.dateMode.offsetFuture` | `in {days} day(s)` | `za {days} dni` |
| `savedViews.modal.dateMode.offsetPast` | `{days} day(s) ago` | `{days} dni temu` |

### 11.5 `tasks.savedViews.confirm.*` i `tasks.savedViews.toast.*`

| Klucz | EN | PL |
|-------|----|----|
| `savedViews.confirm.deleteTitle` | `Delete view?` | `Usunąć widok?` |
| `savedViews.confirm.deleteMessage` | `"{name}" will be permanently removed. This can't be undone.` | `„{name}" zostanie trwale usunięty. Nie można tego cofnąć.` |
| `savedViews.confirm.discardTitle` | `Discard unsaved changes?` | `Odrzucić niezapisane zmiany?` |
| `savedViews.confirm.discardMessage` | `Your changes to "{name}" haven't been saved.` | `Twoje zmiany w „{name}" nie zostały zapisane.` |
| `savedViews.confirm.discardConfirm` | `Discard` | `Odrzuć` |
| `savedViews.toast.created` | `View saved` | `Widok zapisany` |
| `savedViews.toast.updated` | `View updated` | `Widok zaktualizowany` |
| `savedViews.toast.deleted` | `View deleted` | `Widok usunięty` |
| `savedViews.toast.reordered` | `Views reordered` | `Kolejność widoków zmieniona` |
| `savedViews.toast.saveError` | `Couldn't save the view` | `Nie udało się zapisać widoku` |

### 11.6 `tasks.savedViews.errors.*` (mapowanie 422 backendu)

| Klucz frontu | Klucz backendu (z 422) | EN | PL |
|--------------|------------------------|----|----|
| `savedViews.errors.nameTaken` | `filter_tabs.errors.name_taken` | `You already have a view with this name.` | `Masz już widok o tej nazwie.` |
| `savedViews.errors.limitReached` | `filter_tabs.errors.limit_reached` | `You've reached the limit of 30 saved views.` | `Osiągnięto limit 30 zapisanych widoków.` |
| `savedViews.errors.filtersTooLarge` | `filter_tabs.errors.filters_too_large` | `These filters are too large to save.` | `Te filtry są zbyt duże, by je zapisać.` |
| `savedViews.errors.invalidReorder` | `filter_tabs.errors.invalid_reorder_set` | `Couldn't reorder views. Please refresh.` | `Nie udało się zmienić kolejności. Odśwież stronę.` |

### 11.7 Chipy filtrów — rozszerzenie `tasks.filters.chip.*` (stany widoku)

| Klucz | EN | PL |
|-------|----|----|
| `filters.chip.extraAria` | `{label} (added on top of the view)` | `{label} (dodany ponad widok)` |
| `filters.chip.disabledAria` | `{label} (removed from the view — activate to restore)` | `{label} (usunięty z widoku — aktywuj, aby przywrócić)` |
| `filters.chip.restore` | `Restore filter: {label}` | `Przywróć filtr: {label}` |

### 11.8 (Opcjonalnie) globalne — jeśli `filterBar.*` ma nieść stany

Jeśli `restore`/stany mają być generyczne dla każdego `FilterBar` (nie tylko Tasks), zamiast 11.7
dodać do namespace `filterBar`:

| Klucz | EN | PL |
|-------|----|----|
| `filterBar.restoreFilter` | `Restore filter: {label}` | `Przywróć filtr: {label}` |
| `filterBar.chipExtra` | `{label} (added)` | `{label} (dodany)` |
| `filterBar.chipDisabled` | `{label} (removed — restore)` | `{label} (usunięty — przywróć)` |

Rekomendacja: ponieważ Saved Views to na razie wyłącznie Tasks, użyć 11.7 (`tasks.filters.chip.*`).
Gdy Saved Views trafią na inne listy, przenieść do `filterBar.*` (11.8). **Decyzja: 11.7 teraz.**

---

## 12. Pliki do utworzenia/zmiany w Etapie 4 (dla frontend-agenta)

Nowe:
- `resources/js/next/pages/tasks/SavedViewsBar.vue` (pasek + empty/loading/error).
- `resources/js/next/pages/tasks/SavedViewPill.vue` (pigułka + dirty badge + kebab).
- `resources/js/next/pages/tasks/SaveViewModal.vue` (create/edit + picker ikon + D1 daty).
- `resources/js/next/ui/forms/IconSelect.vue` (next-owy picker ikon wzorowany na Labels) — chyba że
  R1 rozstrzygnie inaczej.
- Composable widoków (Pinia/composable) — LOGIKA, poza zakresem tej specyfikacji.

Zmiany:
- `resources/js/next/ui/patterns/FilterBar.vue` — `tabState` per chip, 3 warianty, event `restore-filter`.
- `resources/js/next/ui/primitives/Badge.vue` — (R5) opcjonalny `trailingAction` (rekomendowane).
- `resources/js/next/pages/tasks/TasksView.vue` — osadzenie `SavedViewsBar`, snapshot/dirty, obsługa
  `restore-filter`, prop `tabState` do `activeFilters`.
- `resources/js/next/app/i18n/en.ts` + `pl.ts` — klucze z §11.
