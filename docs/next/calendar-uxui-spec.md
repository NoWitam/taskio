# Kalendarz (R3) — specyfikacja UX/UI

> **Batch B5** rozdziału R3. Dokument jest kontraktem dla `frontend-agent` (batch B6+).
>
> Powstał **po** ukończonym backendzie i **przeciwko** jego rzeczywistemu kontraktowi —
> nie obok niego. Każde pole opisane niżej ma pokrycie w kodzie `app/modules/Calendar/`,
> `app/modules/Tasks/Calendar/` i `app/modules/Workflows/Calendar/`. Nic tutaj nie jest
> zmyślonym kluczem payloadu (znany błąd Etapu 5: agenci frontendu wymyślali pola, których
> API nie miało).
>
> **Rzeczy, których kontrakt nie ma, a UI ich potrzebuje, są zgłoszone jako luki**
> w sekcji [23. Luki kontraktu](#23-luki-kontraktu) — i **nie zostały po cichu dopisane
> do specyfikacji**. Luki L1, L2 i L6 zostały od tego czasu **zamknięte** w kodzie;
> §23 notuje status przy każdej z nich zamiast cicho je usuwać.
>
> **Nic w tym dokumencie nie jest logiką biznesową ani kontraktem backendu.**

---

## Spis treści

1. [Fundamenty](#1-fundamenty)
2. [Kontrakt backendu — pełny inwentarz](#2-kontrakt-backendu--pełny-inwentarz)
3. [Architektura informacji, trasa, stan w URL](#3-architektura-informacji-trasa-stan-w-url)
4. [Okno danych i strefa czasowa](#4-okno-danych-i-strefa-czasowa)
5. [Ekran A — siatka miesiąca](#5-ekran-a--siatka-miesiąca)
6. [Kafelek wystąpienia](#6-kafelek-wystąpienia)
7. [Przepełnienie dnia i serie gęste](#7-przepełnienie-dnia-i-serie-gęste)
8. [Ekran B — agenda](#8-ekran-b--agenda)
9. [FilterBar + zapisane widoki](#9-filterbar--zapisane-widoki)
10. [Legenda źródeł](#10-legenda-źródeł)
11. [Podgląd wystąpienia i deep-linki](#11-podgląd-wystąpienia-i-deep-linki)
12. [Szuflada wydarzenia — tworzenie i edycja](#12-szuflada-wydarzenia--tworzenie-i-edycja)
13. [Trzy rodzaje straty](#13-trzy-rodzaje-straty)
14. [Awaria pojedynczego źródła](#14-awaria-pojedynczego-źródła)
15. [Stany: ładowanie, pusto, błąd](#15-stany-ładowanie-pusto-błąd)
16. [Kolory, tokeny, dark mode](#16-kolory-tokeny-dark-mode)
17. [Responsywność](#17-responsywność)
18. [Dostępność](#18-dostępność)
19. [Inwentarz komponentów](#19-inwentarz-komponentów-reuse--extend--create)
20. [Copy i klucze i18n](#20-copy-i-klucze-i18n)
21. [Handoff do frontend-agent](#21-handoff-do-frontend-agent)
22. [Czego NIE ma w R3](#22-czego-nie-ma-w-r3)
23. [Luki kontraktu](#23-luki-kontraktu)

---

## 1. Fundamenty

### 1.1 Słownik (PL ↔ dane)

| PL (UI) | EN (kod / pole) | Co to jest |
| --- | --- | --- |
| Wystąpienie | occurrence | JEDNA rzecz na siatce, z dowolnego źródła. `CalendarOccurrenceResource`. |
| Źródło | source | Moduł, który coś wystawia. Dziś cztery: `task`, `workflow_schedule`, `workflow_run`, `event`. |
| Wydarzenie | calendar event | JEDYNY temat, który Kalendarz posiada i który da się edytować. `CalendarEventResource`. |
| Całodniowe | `all_day: true` | Zajmuje DZIEŃ. Nosi `start_date` (`Y-m-d`), **nigdy** nie ma godziny. |
| Z godziną | `all_day: false` | Dzieje się w CHWILI. Nosi `starts_at` (ISO UTC), opcjonalnie `ends_at`. |
| Plakietka | badge | Gotowa, przetłumaczona **na serwerze** proza + kolor. Frontend jej NIE tłumaczy. |
| Gęsta seria | `dense: true` | Ta pozycja powtarza się częściej, niż widać. |
| Ucięcie | truncation | Stwierdzenie „to źródło nie pokazuje wszystkiego, i oto jakiego rodzaju wszystkiego". |
| Podmiot | subject | `{ type, id }` — alias morficzny + id, do deep-linku. Backend go **nie rozwija**. |
| Okno | window | `from`–`to`, dni kalendarzowe w strefie workspace'u. Maks. 62 dni. |

### 1.2 Doktryna, która musi być słyszalna w interfejsie

1. **Dzień to nie chwila.** Termin zadania nie ma godziny i nigdy jej nie dostanie.
   Wystąpienie harmonogramu ma godzinę i musi ją pokazywać. Interfejs nigdy nie
   dorabia godziny do dnia ani dnia do chwili.
2. **Strefa jest workspace'u, nie przeglądarki.** Użytkownik z innej strefy musi
   rozumieć, czyją północ ogląda.
3. **Cichy brak danych to defekt.** Każdy rodzaj straty jest nazwany osobno; nieznana
   liczba zostaje nieznana.
4. **Kalendarz nie zna nikogo.** Frontend też nie może: filtry, etykiety i legenda
   powstają z `meta.sources`, nigdy z zakodowanej listy. Czwarte źródło (R4 Publikacje)
   ma się pojawić na ekranie **bez zmiany kodu frontendu**.
5. **Część kratek się edytuje, część nie** — i widać to, zanim użytkownik kliknie.

### 1.3 Twarde reguły projektu, których ta specyfikacja przestrzega

| Reguła | Jak zastosowana |
| --- | --- |
| Ikona modułu na jego stronie: biało-na-primary | `PageHeader` z `icon="calendar"` renderuje bąbel sam. Bez `iconClass`. |
| Każdy ekran listy: `FilterBar` + `FilterTabBar` w `#top` | §9. Obowiązkowe, nie opcjonalne. |
| Szkielety imitują realny element, kilka sztuk | §15.1 — szkielet **jest siatką 6×7**, nie prostokątem. |
| Akcje przez `Button` (nigdy `<button>`) | Wszędzie, w tym zamykanie szuflady. Kompaktowe: `icon-sm` / `icon-xs`. |
| Kolejność afordancji trailing | Warunkowy `✕` **przed** stałym chevronem/kebabem. |
| Wszystko i18n, PL + EN | §20. `en.ts` jest źródłem typu, `pl.ts` 1:1. |
| Kolor nigdy jedynym sygnałem | Każdy kafelek ma ikonę źródła; „dziś" ma obwódkę **i** pogrubiony numer. |
| Importy **względne** | `import PageHeader from '../../ui/patterns/PageHeader.vue';` — `@next/*` nie ma aliasu w Vite. |
| Zero nowych pakietów npm | Biblioteka kalendarza byłaby regresem. §19. |

### 1.4 Decyzje projektowe

Każda: **co**, **dlaczego**, **co odrzucono**.

---

**D1 — Siatka 6×7 powstaje z `buildMonthWeeks()`, a `CalendarPanel.vue` NIE jest reużywany.**
`resources/js/next/ui/forms/date/dateCore.ts` eksportuje przetestowany, bezzależnościowy
`buildMonthWeeks(viewDate, weekStartsOn)` → `CalendarCell[][]` (6 wierszy × 7,
`{ date, iso, day, inCurrentMonth }`). To jest **jedyna** matematyka siatki, jakiej ten
ekran potrzebuje, i ma być użyta wprost.
`CalendarPanel.vue` **nie** jest reużywany jako komponent: to powierzchnia *wyboru daty*
(model `yyyy-mm-dd`, tryb zakresu, hover-preview, komórki `h-9 w-9`, `today()` liczone
**lokalnie w przeglądarce**). Kalendarz miesiąca potrzebuje komórek o wysokości kilku
wierszy, treści w środku i „dziś" liczonego **w strefie workspace'u**. Rozszerzanie
`CalendarPanel` o te dwa światy zamieniłoby wspólny picker w komponent z dwoma trybami
i jedną wspólną regresją.
→ **Wzorzec a11y kopiujemy z `CalendarPanel` 1:1** (role siatki, roving tabindex, mapa
klawiszy, live-region) — to jest właśnie to, co „przeszło" i nie ma być wymyślane od nowa.
*Odrzucone:* prop `variant="month-view"` na `CalendarPanel` — jedna zmiana w pickerze dat
psułaby wtedy kalendarz i odwrotnie.
*Odrzucone:* jakakolwiek biblioteka kalendarza (FullCalendar, v-calendar) — nowa
zależność, własne tokeny, własny dark mode, własna a11y do nadpisania.

**D2 — JEDNO okno danych dla obu trybów: 42 dni siatki.**
Zarówno siatka, jak i agenda czytają ten sam zakres `from = weeks[0][0].iso`,
`to = weeks[5][6].iso` (dokładnie 42 dni ≤ 62 z `config/calendar.php`).
Powody: (a) przełączenie trybu **nie odpytuje serwera** i jest natychmiastowe,
(b) jeden komplet komunikatów o stracie — użytkownik przełączający tryb nie może
zobaczyć dwóch różnych opowieści o tym samym miesiącu, (c) jedna kontrolka nawigacji
obsługuje oba tryby.
Konsekwencja: agenda pokazuje też dni „przelewowe" z sąsiednich miesięcy. To jest
uczciwe (są w oknie) i wymaga tylko, by nagłówek grupy dnia zawierał nazwę miesiąca.
*Odrzucone:* agenda = kalendarzowy miesiąc (1–31). Wymuszałaby refetch przy każdym
przełączeniu trybu i mogła pokazać inny raport ucięcia dla „tego samego" miesiąca.

**D3 — Brak przeciągania. Wszystko jest klikalne.**
Decyzja, nie zapomnienie: wystąpienia harmonogramu są nieprzesuwalne z definicji
(`editable: false` w `WorkflowScheduleCalendarSource`), więc „przeciągnij, żeby przenieść"
byłoby prawdą dla jednego źródła na cztery. Mieszana afordancja uczy nieufności do całego
ekranu. Zmiana daty wydarzenia odbywa się w szufladzie (§12).
*Odrzucone:* drag tylko dla `editable: true` — kratki wyglądają identycznie, a chwytają
się różnie; to gorsze niż brak chwytania.

**D4 — Edytowalność jest widoczna PRZED kliknięciem, przez **kierunek** kliknięcia.**
Każdy kafelek niesie stały, nie-hoverowy glif końcowy:
`pencil` (xs, muted) gdy `editable: true`, `external-link` (xs, muted) gdy `false`.
Nie „jest/nie ma znaczka", tylko **dwa różne znaczki**: użytkownik uczy się nie tylko
tego, że coś się edytuje, ale i tego, że reszta *otworzy się gdzie indziej*.
`editable` jest **podpowiedzią afordancji**; prawdą o uprawnieniach są `can_be_edited` /
`can_be_deleted` z `GET /calendar/events/{event}` (§12.1).
*Odrzucone:* pokazywanie ołówka tylko na hover — łamie wymóg „widoczne, zanim kliknie",
i nie istnieje na dotyku.

**D5 — Plakietki (`badge`) NIE renderują się w kafelku siatki.**
Renderują się w agendzie, w popoverze dnia i w podglądzie wystąpienia. W komórce dnia
o szerokości ~11 rem plakietka zjada tytuł, a tytuł jest tym, po czym użytkownik skanuje.
Nic nie jest ukryte bez wyjścia: plakietka jest o jedno kliknięcie (popover dnia) albo
o jedno przełączenie trybu.
*Odrzucone:* plakietka jako sama kropka koloru w kafelku — kolor kafelka **już** niesie
`color`, druga kropka o innym znaczeniu byłaby nieodczytywalna.

**D6 — Filtr źródeł, legenda i ikony powstają z `meta.sources`, nigdy z listy w kodzie.**
Mapa ikon per źródło ma **obowiązkowy fallback** (`circle`) dla nieznanego id.
To jest jedyny mechanizm, który utrzymuje obietnicę „czwarte źródło bez zmiany frontendu"
prawdziwą po pierwszej interakcji z filtrem.
*Odrzucone:* `const SOURCES = ['task', 'workflow_schedule', …]` — R4 Publikacje
wywróciłoby filtr i legendę w tym samym tygodniu.

**D7 — `meta.truncated` decyduje TYLKO o tym, czy sekcja komunikatów istnieje.**
Nigdy nie jest źródłem treści komunikatu. Treść zawsze pochodzi z `truncations[]`,
osobno per `(source, kind)`. Jeden komunikat na trzy rodzaje byłby nieprawdziwy
w dwóch przypadkach na trzy — dokładnie dlatego backend to rozdzielił.

**D8 — Pusty MIESIĄC nie dostaje `EmptyState` w trybie siatki.**
Kalendarz z niczym w środku to nadal kalendarz — dni istnieją i są klikalne (można na nich
utworzyć wydarzenie). Komunikat idzie do `#results` FilterBara („0 wystąpień")
plus akcja „Wyczyść filtry", gdy filtry są aktywne.
Agenda **dostaje** `EmptyState` — pusta lista jest genuinie pusta.
*Odrzucone:* nakładka `EmptyState` nad siatką — zasłania powierzchnię, na której
użytkownik ma właśnie coś stworzyć.

**D9 — Zapisany widok zapamiętuje `sources`, `q` i `mode`; NIE zapamiętuje miesiąca.**
Widok przypięty do „sierpnia 2026" jest nieaktualny z chwilą zmiany miesiąca — to ta sama
przyczyna, dla której na tym ekranie **nie ma kontrolki zakresu dat** (§9.3).
`mode` jest częścią widoku, bo „Moje terminy — agenda" to sensowna nazwana konfiguracja.

**D10 — Brak `ModuleAside` / `ModuleTabs`.**
Kalendarz to JEDEN ekran w dwóch trybach, nie moduł z sekcjami. Przełącznik trybu to
`SegmentedControl` w `PageHeader #actions`. Dodanie shella z aside sugerowałoby sekcje,
których nie ma.

---

## 2. Kontrakt backendu — pełny inwentarz

Wszystko poniżej odczytane z kodu. Klient HTTP: `api` z `app/lib/api.ts`
(`baseURL: '/api'`), tablice jako **powtarzane** parametry `key[]=…`
(konwencja `serializeRunFilters` w `app/stores/workflowRuns.ts`).

### 2.1 `GET /api/calendar/occurrences` — odczyt siatki

Parametry (`CalendarWindowRequest`):

| Param | Wymagany | Reguła | Uwaga |
| --- | --- | --- | --- |
| `from` | tak | `date_format:Y-m-d` | Dzień kalendarzowy w strefie workspace'u. |
| `to` | tak | `date_format:Y-m-d`, `after_or_equal:from` | Włącznie. |
| `sources[]` | nie | `Rule::in(zarejestrowane id)` | **Nieznane id → 422**, nie ciche pominięcie. Brak parametru = WSZYSTKIE źródła. |
| `q` | nie | `string`, `max:255` | Każde źródło samo decyduje, co „pasuje". |

**Nie ma parametru `tz`** (`CalendarTimezoneResolver` — klient nie ma głosu).
**Nie ma paginacji** (`CalendarOccurrenceController`: świadomie).
Okno > `62` dni → **422** na polu `to`, komunikat `calendar.validation.window_too_large`.
Brak aktywnego workspace'u → **400** (`RequireWorkspace`); obcy workspace → **403**.

Odpowiedź — `data[]` (`CalendarOccurrenceResource`):

| Pole | Typ | Znaczenie w UI |
| --- | --- | --- |
| `id` | `string` | Stabilne między odświeżeniami (`task:{uuid}`, `workflow_schedule:{uuid}:{iso}`). **Klucz `:key` w v-for i klucz zaznaczenia.** |
| `source` | `string` | Id źródła — do filtra, ikony i legendy. |
| `editable` | `bool` | Afordancja (D4). Dziś `true` tylko dla `source: 'event'`. |
| `all_day` | `bool` | **ROZRÓŻNIK. Czytaj jako pierwszy.** |
| `start_date` | `string\|null` | `Y-m-d`. Ustawione **wtw** `all_day`. **Nigdy nie parsować jako chwili, nigdy nie konwertować.** |
| `starts_at` | `string\|null` | ISO-8601 UTC. Ustawione **wtw** `!all_day`. Renderować w `meta.timezone`. |
| `ends_at` | `string\|null` | ISO-8601 UTC lub `null` (przebieg wciąż trwa / brak znanego końca). |
| `title` | `string` | Tekst kafelka. |
| `color` | `enum` | `neutral \| primary \| success \| warning \| danger \| info`. Mapa tokenów: §16.1. |
| `badge` | `{ label, color } \| null` | `label` to **gotowa, przetłumaczona proza** — NIE tłumaczyć po stronie klienta. |
| `dense` | `bool` | „Jest tego więcej, niż widzisz". §7.2. |
| `cadence_label` | `string\|null` | Gotowa, przetłumaczona proza o okresie powtarzania („Co 5 min") — **zawsze obecne, zwykle `null`**; niepuste tylko dla zagęszczonej kadencji interwałowej. §7.2. |
| `subject.type` | `string` | Alias morficzny: `task`, `workflow`, `workflow_run`, `calendar_event`. |
| `subject.id` | `string` | UUID podmiotu — do deep-linku (§11.2). |

**Kolejność `data[]` jest gotowa** (dzień → całodniowe przed godzinowymi → godzina → id).
**Frontend nie sortuje ponownie** — bucketuje po dniu, zachowując kolejność tablicy.

Odpowiedź — `meta`:

| Pole | Typ | Znaczenie w UI |
| --- | --- | --- |
| `timezone` | `string` (IANA) | Strefa, w której odmierzono KAŻDĄ granicę dnia w tej odpowiedzi. §4.2. |
| `truncated` | `bool` | Wyłącznie „czy renderować sekcję komunikatów" (D7). |
| `truncations[]` | `{ source, kind, omitted_occurrences, affected_items }` | §13. `kind ∈ { window_trimmed, item_densified, items_dropped }`. Liczby są `int` **albo `null` = nieznane** (nigdy 0). Maks. jeden wpis na parę `(source, kind)`. |
| `sources[]` | `{ id, label }` | **Katalog WSZYSTKICH zarejestrowanych źródeł**, niezależny od filtra `sources` (lista nie kurczy się do zaznaczenia). Źródło Kalendarza używa `label` z `calendar.sources.event`; pozostałe — z własnych modułów. |
| `unavailable_sources[]` | `{ source, reason }[]` | Źródła zapytane, które nie odpowiedziały — **z powodem**, nie sama lista id. `reason ∈ { failed, not_constructed, malformed }` (`CalendarUnavailableReason`), zamknięty słownik. Zawsze obecne (pusta tablica ≠ brak klucza). §14. |

> **Uwaga o `label`.** Gdy źródło nie da się skonstruować, `CalendarSourceRegistry::labelFor()`
> zwraca `null`, a serwis wstawia **surowe id** jako etykietę. UI renderuje `label`
> dosłownie i nie próbuje go tłumaczyć ani upiększać.

### 2.2 Które źródło co produkuje (obserwowane w kodzie)

| Źródło (`id`) | Kształt | `color` | `badge` | `editable` | `dense` | `subject.type` |
| --- | --- | --- | --- | --- | --- | --- |
| `task` | `all_day` | z `TaskPriority::tone()` (pilność!) | status zadania | `false` | nigdy | `task` |
| `workflow_schedule` | z godziną, `ends_at: null` | zawsze `info` | `workflows.calendar.scheduled_badge` | `false` | **możliwe** | `workflow` |
| `workflow_run` | z godziną, `ends_at` = koniec **lub `null` gdy trwa** | ze stanu przebiegu | stan przebiegu | `false` | nigdy | `workflow_run` |
| `event` | **oba** kształty | stały `primary` (żadne wydarzenie nie ma własnego koloru — patrz niżej) | **zawsze `null`** | **per wiersz przez policy** | nigdy | `calendar_event` |

Trzy fakty z kodu, które **zmieniają projekt ekranu**:

1. **Przeszły miesiąc nie ma „Zaplanowanych automatyzacji" — i to nie jest awaria.**
   `WorkflowScheduleCalendarSource` kotwiczy projekcję na `max(now, początek okna)`;
   dla okna w całości przeszłego zwraca `complete([])` **bez żadnego ucięcia**.
   Legenda i filtr **nie mogą** sugerować, że źródło zawiodło. §10.3.
2. **Gęsta seria tworzy „urwisko", nie równomierny szum.**
   Budżet 64 wystąpień na pozycję jest wypełniany od kotwicy w przód: kadencja co 5 minut
   zużywa cały budżet w ~5 godzin **jednego dnia**, a dalsze dni okna są puste.
   Pusty dzień po urwisku **nie znaczy „nic się nie dzieje"** i copy musi to powiedzieć (§13.3).
3. **Zdarzenie z godziną trafia na siatkę tylko w dniu, w którym się ZACZYNA.**
   `EventCalendarSource::timedEventsIn()` filtruje po `starts_at`. Zdarzenie
   zaczynające się przed oknem i trwające w nie **nie wróci**. UI **nie rysuje rozpiętości
   przez dni** (§22).

**Czwarty fakt, dodany po decyzji ownera: `color` zdarzenia jest stały, nie wybieralny.**
Każde inne źródło koloruje wystąpienie **faktem**, który zna o swoim podmiocie: termin
zadania — pilnością, przebieg — wynikiem, projekcja harmonogramu — tym, że jest projekcją.
Zdarzenie nie ma takiego faktu, więc `EventCalendarSource` emituje **tę samą** wartość
(`CalendarColor::PRIMARY`) dla każdego zdarzenia. Świadomie ani `info` (należy do projekcji
harmonogramu — użycie dla zdarzenia zlałoby dwa różne znaczenia w jednej siatce), ani
`neutral` (to wartość **degradacji**, do której `CalendarColor::fromTone()` schodzi przy
nieznanym tonie — użycie jej dla zdarzeń zlałoby „to jest zdarzenie" z „nie umiem tego
zinterpretować"). Konsekwencja po stronie zapisu: formularz zdarzenia **nie ma** kontrolki
koloru (§12.2), a `POST`/`PUT` odrzuca pole `color` jako 422 (§2.3). Jeśli kiedyś wróci
potrzeba wizualnego grupowania własnych wydarzeń, właściwym kształtem jest **rodzaj
zdarzenia**, z którego kolor się wywodzi — nie ręczny wybór barwy; zapisane jako
*planowane, niezaimplementowane* w ADR-0051 D10.

### 2.3 `…/calendar/events` — jedyna powierzchnia zapisu

| Metoda | Ścieżka | Zwrot |
| --- | --- | --- |
| `POST` | `/api/calendar/events` | `CalendarEventResource` (201/200) |
| `GET` | `/api/calendar/events/{uuid}` | `CalendarEventResource` |
| `PUT` | `/api/calendar/events/{uuid}` | `CalendarEventResource` |
| `DELETE` | `/api/calendar/events/{uuid}` | `204`, bez ciała |

**Nie ma `GET /calendar/events` (listy).** Wydarzenia czyta się na siatce.
**Nie ma `restore`** mimo soft-delete.

`CalendarEventResource`:

| Pole | Typ | Uwaga |
| --- | --- | --- |
| `id` | `uuid` | |
| `title` | `string` | ≤ 255 |
| `description` | `string\|null` | ≤ 5000, **zwykły tekst** (brak kontraktu markdown) |
| `all_day` | `bool` | rozróżnik |
| `start_date` | `string\|null` | `Y-m-d`, wtw `all_day` |
| `starts_at` / `ends_at` | `string\|null` | ISO UTC, wtw `!all_day` |
| `subject` | `{ type, id } \| null` | **wskaźnik wydarzenia NA coś** — inne znaczenie niż `subject` wystąpienia (tam podmiotem jest samo wydarzenie) |
| `creator` | `CreatorResource` | gdy załadowany; może być `workflow_run` (krok `create_event`) |
| `is_owner` | `bool` | **autorstwo ludzkie**; dla wydarzenia z workflow zawsze `false` |
| `can_be_edited` / `can_be_deleted` | `bool` | **twórca LUB właściciel workspace'u** — bramkować UI na tych polach, **nigdy na `is_owner`** |
| `created_at` / `updated_at` | ISO | |

**Nie ma klucza `color` — w ogóle, nie tylko `null`.** Wydarzenie nie ma własnego koloru
(§2.2 „Czwarty fakt"); pole zniknęło z zasobu tak samo jak z payloadu zapisu poniżej.

Payload `POST` / `PUT` (`StoreCalendarEventRequest`; `PUT` ma **te same reguły**):

```
title         string, required, max:255
description   string|null, max:5000
all_day       bool, REQUIRED (nigdy nie wnioskowany z pozostałych pól)
start_date    Y-m-d   — WYMAGANE gdy all_day, ZABRONIONE gdy !all_day
starts_at     date    — WYMAGANE gdy !all_day, ZABRONIONE gdy all_day
ends_at       date    — opcjonalne, after_or_equal:starts_at
subject_type  string|null  ) OBA albo ŻADNE
subject_id    uuid|null    )
```

> ### ⚠️ `color` NIE JEST POLEM ZAPISU — WYSŁANIE GO TO 422
>
> `color` jest `['prohibited']` na `StoreCalendarEventRequest`/`UpdateCalendarEventRequest`:
> obecne i niepuste → 422 na kluczu `color` (`calendar.validation.color_not_accepted`),
> nieobecne → OK. Odrzucone, nie po cichu pominięte — klient wciąż wysyłający starą wartość
> ma się o tym dowiedzieć, a nie wyglądać na poprawnego i mylić się w milczeniu.

> ### ⚠️ PUT JEST ZAPISEM CAŁEGO WYDARZENIA, NIE ŁATKĄ
>
> `UpdateCalendarEventRequest` dziedziczy komplet reguł, a `CalendarEventService::attributes()`
> zapisuje **wszystkie** kolumny przy każdym zapisie, zerując nieużywaną grupę czasową.
> **Formularz, który nie odeśle `description`, `subject_type`, `subject_id`,
> wyzeruje je w bazie.** Wydarzenie utworzone przez krok workflow ma wskaźnik `subject`
> — pominięcie go przy edycji po cichu zrywa powiązanie.
> **Wymóg:** szuflada edycji trzyma pełny obiekt z `GET` i odsyła komplet pól (§12.4).

Komunikaty 422 przychodzą **już przetłumaczone** (`lang/{pl,en}/calendar.php`) na kluczach
`title`, `start_date`, `starts_at`, `ends_at`, `subject_type`, `subject_id`, `to` — oraz na
kluczu `color`, ale **tylko jako odrzucenie** (patrz ramka wyżej), nigdy jako zwykła walidacja
wartości. UI pokazuje treść z serwera, nie własną.

---

## 3. Architektura informacji, trasa, stan w URL

### 3.1 Nawigacja globalna

`resources/js/next/pages/AppLayout.vue`, `primaryNav` — **nowa pozycja zaraz po `tasks`**
(terminy zadań to najbliższy sąsiad semantyczny):

```ts
{ key: 'calendar', labelKey: 'nav.calendar', icon: 'calendar', to: '/calendar' },
```

### 3.2 Trasa

```
/calendar   name: 'next.calendar'
            component: pages/calendar/CalendarView.vue
            meta: { requiresAuth: true, titleKey: 'nav.calendar' }
```

Bez tras potomnych i bez `ModuleLayout` (D10).

### 3.3 Stan w URL (deep-link)

| Param | Wartość | Domyślnie |
| --- | --- | --- |
| `month` | `YYYY-MM` | bieżący miesiąc **w strefie workspace'u** |
| `mode` | `grid` \| `agenda` | `grid` |
| `sources` | powtarzalny `sources=task&sources=event` | brak = wszystkie |
| `q` | tekst | brak |
| `event` | `uuid` | — otwiera szufladę podglądu wydarzenia |
| `edit` | `1` | — szuflada w trybie edycji (razem z `event`) |
| `new` | `1` | — szuflada tworzenia |
| `date` | `Y-m-d` | — dzień zasiewający formularz tworzenia |

Konwencja 1:1 z `TasksView.vue` (`?task=`, `?edit=1`, `?new=1`).

> **Uwaga:** `useRouteQueryHydration` **istnieje tylko w legacy** (`resources/js/`)
> i jest po drugiej stronie granicy — nie wolno go importować. W `next` synchronizacja
> z URL jest ręczna: `computed` czytające `route.query.*` przez helper `str()` plus
> `router.replace` / `router.push` przy zapisie — dokładnie wzorzec z
> `pages/tasks/TasksView.vue` (linie ~810–921) i `pages/workflows/WorkflowsView.vue`.

Zmiana miesiąca i filtrów → `router.replace` (nie zaśmieca historii);
otwarcie szuflady → `router.push` (Wstecz zamyka).

---

## 4. Okno danych i strefa czasowa

### 4.1 Okno

```
weeks = buildMonthWeeks(pierwszyDzieńMiesiąca(month), 1)   // poniedziałek
from  = weeks[0][0].iso
to    = weeks[5][6].iso                                    // 42 dni
```

Refetch przy zmianie: `month`, `sources`, `q`. **Nie** przy zmianie `mode` (D2).
Poprzedni wynik zostaje na ekranie w stanie `refreshing` (§15.4).

### 4.2 Strefa — gdzie użytkownik ją widzi

`meta.timezone` jest **jedyną** prawdą o dniu i godzinie na tym ekranie.
Przeglądarkowe `new Date()` nie decyduje o niczym poza animacjami.

Trzy miejsca, w których strefa jest widoczna — i to jest odpowiedź na „osoba w innej
strefie musi rozumieć, czyją północ ogląda":

1. **Stale, w nagłówku miesiąca** — obok tytułu miesiąca, chip
   `Badge variant="neutral" tone="subtle" icon="clock"` z treścią `meta.timezone`
   (np. `Europe/Warsaw`). Zawsze, dla każdego — nie tylko dla „obcych", bo interfejs
   nie wie, kto jest obcy, a chip, który czasem znika, jest gorszy niż chip stały.
2. **Wzmocnienie, gdy strefa przeglądarki ≠ `meta.timezone`** — ten sam chip dostaje
   `variant="warning"`, `icon="alert-triangle"` i `Tooltip`/`title`:
   *„Kalendarz pokazuje dni i godziny w strefie zespołu ({tz}). Twoja przeglądarka jest
   w {localTz}."* Porównanie: `Intl.DateTimeFormat().resolvedOptions().timeZone`.
3. **W szufladzie wydarzenia**, przy polach godzinowych — trwały tekst pomocniczy
   *„Godzina w strefie zespołu: {tz}"* (§12.3). Tam to nie ozdoba: użytkownik wpisuje
   godzinę i musi wiedzieć, czyją.

### 4.3 Dwie czyste funkcje, których dziś nie ma i trzeba je napisać

Nowy moduł `pages/calendar/calendarZone.ts` — **czysty, bez Vue, testowany Vitestem**.
Świadomie **poza `dateCore.ts`**: `dateCore` jest z definicji bezstrefowy
(*„we never call `toISOString()` on a calendar-day value"*) i ta właściwość ma zostać.

| Funkcja | Sygnatura | Do czego |
| --- | --- | --- |
| `workspaceToday(tz)` | `(tz: string) => IsoDate` | „Dziś" na siatce i domyślny miesiąc. Implementacja: `Intl.DateTimeFormat('en-US', { timeZone: tz, year, month, day }).formatToParts()` + złożenie `Y-m-d` (nigdy `toISOString()`). |
| `instantToZonedParts(iso, tz)` | `(iso: string, tz: string) => { day: IsoDate; time: string }` | Bucketowanie wystąpień z godziną do komórek dnia **i** render `HH:mm`. Ten sam `formatToParts`, `hour12: false`. |
| `zonedWallClockToInstant(local, tz)` | `(local: IsoDateTime, tz: string) => string` (ISO z **jawnym offsetem**) | Zapis `starts_at`/`ends_at` — patrz **L1** w §23 (zamknięta; jawny offset pozostaje poprawny niezależnie od naprawy backendu). Algorytm: `guess = Date.UTC(...)`, dwie iteracje korekty o offset odczytany przez `formatToParts` w `tz` (druga iteracja domyka przejście DST). |

**Wymagane testy Vitest:** granica DST wiosną (godzina, która nie istnieje) i jesienią
(godzina, która istnieje dwa razy), strefa na zachód od UTC, `tz === 'UTC'`,
północ/koniec dnia.

---

## 5. Ekran A — siatka miesiąca

### 5.1 Struktura strony (obowiązująca dla obu trybów)

```
PageHeader  (icon="calendar", title=t('calendar.title'))
  #actions: [ SegmentedControl mode ] [ Button primary "Nowe wydarzenie" ]

FilterBar (sticky)
  #top:      FilterTabBar  ← zapisane widoki, OBOWIĄZKOWE
  default:   [ SegmentedControl multiple: źródła ]      ← z meta.sources
  search:    q
  #results:  "N wystąpień" | "0 wystąpień" (+ „Wyczyść filtry")

[ Alert danger  ]  ← unavailable_sources        (§14)
[ Alert warning ]  ← truncations, jeden wiersz na (source, kind)  (§13)

Pasek nawigacji miesiąca:
  [‹] [ czerwiec 2026 ] [›]   [ Dziś ]      … [ chip strefy ]

⟨ siatka 6×7 ⟩  albo  ⟨ agenda ⟩

Legenda źródeł (§10)
```

### 5.2 Pasek nawigacji miesiąca

- `‹` / `›`: `Button variant="ghost" size="icon-sm"`, `aria-label` z i18n; zmieniają
  `?month` o ±1 miesiąc.
- Tytuł: `monthYearLabel(view, locale)` z `dateCore` (`capitalize`), z rezerwacją
  szerokości na najdłuższą nazwę miesiąca w locale — **dokładnie ten trik co
  `CalendarPanel` (`headingMinWidth`)**, żeby przewijanie miesięcy nie przestawiało paska.
- `Dziś`: `Button variant="secondary" size="sm"`. **Disabled, gdy widoczny miesiąc już
  zawiera `workspaceToday(tz)`**, z `title` wyjaśniającym powód (reguła
  „disabled musi pozostać zrozumiały").
- Chip strefy: §4.2.

### 5.3 Siatka

- Kontener: `role="grid"`, `aria-label` = etykieta miesiąca.
- Wiersz nagłówków dni tygodnia: `weekdayNames(locale, 1, 'short')`, `aria-hidden="true"`
  (jak w `CalendarPanel`), `uppercase text-next-2xs text-next-muted-foreground`.
- 6 wierszy `role="row"` × 7 komórek `role="gridcell"`.
- Kolumny równe: `grid-cols-7`, komórki `min-h-[7.5rem]` (`next-xl`) /
  `min-h-[6.5rem]` (`next-lg`).

### 5.4 Anatomia komórki dnia

```
┌──────────────────────────────┐
│ 12                        +3 │   ← numer dnia (start) · licznik nadmiaru (end)
│ ▌ 09:00  Publikacja posta  ✎ │   ← kafelki wystąpień, w kolejności z API
│ ▌        Deadline: raport  ↗ │
│ ▌ 14:30  Kampania Q3       ↗ │
└──────────────────────────────┘
```

| Element | Reguła | Pole kontraktu |
| --- | --- | --- |
| Numer dnia | `tabular-nums`. Poza bieżącym miesiącem: `text-next-muted-foreground/60`. | `CalendarCell.inCurrentMonth` |
| „Dziś" | Komórka: `bg-next-primary-subtle`; numer: `font-next-semibold` + pastylka `bg-next-primary text-next-primary-foreground`; `aria-current="date"`. **Trzy sygnały, nie sam kolor.** | `workspaceToday(meta.timezone)` |
| Weekend | `bg-next-muted/40` (subtelnie, pod „dziś") | z indeksu kolumny |
| Kafelki | Maks. `3` (≥`next-xl`) / `2` (`next-lg`), kolejność **z API bez zmian** | `data[]` |
| Nadmiar | `+N więcej` → popover dnia (§7.1) | policzone lokalnie |
| Pusta komórka | Bez treści. Hover: `bg-next-accent` + `plus` (xs, muted) w rogu → tworzenie wydarzenia na ten dzień | — |

**Kliknięcie w tło komórki** (nie w kafelek) otwiera szufladę tworzenia z
`?new=1&date=<iso>` i zasianym dniem. **Enter/Spacja na sfokusowanej komórce** otwiera
popover dnia, a gdy dzień jest pusty — szufladę tworzenia.

---

## 6. Kafelek wystąpienia

Jeden komponent: `pages/calendar/OccurrenceChip.vue`. Trzy warianty gęstości:
`grid` (komórka), `agenda` (wiersz), `list` (popover dnia).

### 6.1 Anatomia

| Slot | Wariant `grid` | Wariant `agenda` / `list` | Pole |
| --- | --- | --- | --- |
| Pasek koloru | `w-1` po lewej, `bg-next-{color}` solid | to samo | `color` |
| Ikona źródła | `Icon` xs, muted | `Icon` sm | `source` → mapa + fallback (D6) |
| Godzina | `HH:mm`, `tabular-nums`, muted — **tylko gdy `all_day === false`** | `HH:mm–HH:mm` gdy `ends_at`; `HH:mm` + `…` gdy `ends_at === null` | `starts_at`, `ends_at`, `meta.timezone` |
| Tytuł | `truncate`, 1 linia | 1 linia, `truncate` | `title` |
| Znacznik gęstości | `Badge` xs `neutral/subtle` z ikoną serii | to samo + liczba pokazanych | `dense` |
| Plakietka | **BRAK** (D5) | `Badge` sm, `variant` z `badge.color`, tekst = `badge.label` **dosłownie** | `badge` |
| Glif końcowy | `pencil` gdy `editable`, `external-link` gdy nie (D4) | to samo | `editable` |

### 6.2 Reguła całodniowości — nienegocjowalna

```
all_day === true   → NIE renderuj slotu godziny. W ogóle. Nie „00:00", nie „—".
                     Data pochodzi z start_date i NIE jest konwertowana ani parsowana
                     do obiektu Date z myślą o strefie.
all_day === false  → godzina JEST wymagana i pochodzi z instantToZonedParts(starts_at, tz).
```

Test regresyjny (obowiązkowy): wystąpienie `task` z `start_date: '2026-08-09'` przy
`meta.timezone: 'Pacific/Kiritimati'` **musi** wylądować w komórce `2026-08-09`.

### 6.3 Stany kafelka

| Stan | Wygląd |
| --- | --- |
| default | tło `bg-next-{color}-subtle`, tekst `text-next-{color}-subtle-foreground` |
| hover | `brightness`/`bg-next-accent` na warstwie tła + kursor `pointer` |
| focus-visible | `ring-2 ring-next-ring ring-offset-1` |
| przeszłe (`starts_at` < teraz, źródło `workflow_schedule`) | bez zmian — backend takich nie zwraca |
| przeszłe (dowolne inne) | `opacity-70` w trybie siatki, pełne w agendzie |
| dense | jak default + znacznik serii (§7.2) |

---

## 7. Przepełnienie dnia i serie gęste

### 7.1 „+3 więcej"

- Liczy się **po** zwinięciu serii gęstych (§7.2), inaczej licznik kłamie.
- Kontrolka: `Button variant="ghost" size="xs"`, tekst `+{n} więcej`,
  `aria-label` = *„Pokaż wszystkie wystąpienia: {pełna data}"*.
- Otwiera `Modal` (`ui/overlay/Modal.vue`, size `md`) — **nie** anchorowany `Popover`;
  powód i implementacja: §23, „Uwaga systemowa — brak zewnętrznej kotwicy w `Popover`".
  Nagłówek = `fullDateLabel(date, locale)`, lista wszystkich wystąpień dnia w wariancie
  `list` (z plakietkami), przewijana, `max-h-[24rem]`.
- Ten „arkusz dnia" jest jedyną drogą do kafelków z klawiatury (§18.2).

### 7.2 Serie gęste — jeden chip, nigdy 288 kropek

Reguła zwijania (w komórce dnia i w grupie dnia agendy):

```
pogrupuj wystąpienia dnia po subject.id
jeśli którekolwiek w grupie ma dense === true:
    renderuj JEDEN kafelek reprezentujący całą grupę
    tytuł = title
    godzina = godzina PIERWSZEGO wystąpienia grupy w tym dniu
    znacznik = Badge xs: t('calendar.dense.chip', { shown: n })
    kliknięcie → popover dnia z pełną (niezwiniętą) listą tej grupy
```

**Kadencja — zamknięta luka L2.** Wystąpienie niesie opcjonalne `cadence_label`
(gotowa, przetłumaczona proza od źródła: *„Co 5 min"*, *„Co 2 h, 09:00–17:00"*) —
tylko dla kadencji INTERWAŁOWEJ (`every_minutes`/`every_hours`); tryb `at` (lista
stałych godzin) niesie `null`, bo uczciwe zdanie musiałoby uwzględnić też oś dnia/
miesiąca. Chip renderuje `cadence_label`, gdy jest, i **degraduje** do
*„Seria — pokazano 12"*, gdy go nie ma — copy **nadal nigdy nie zgaduje** okresu
samodzielnie. Niedopuszczalne: wymyślanie *„co 5 min"* czy *„288 wystąpień"* po
stronie klienta, gdy `cadence_label` jest `null`.

**Urwisko.** Zwinięcie sprawia, że siatka wygląda jak „automatyzacja odpaliła rano
i przestała". To nie jest prawda i sam chip tego nie naprawia — naprawia to komunikat
`item_densified` (§13.3), który **musi** się pojawić, bo backend zawsze go dołącza,
gdy coś zagęścił.

---

## 8. Ekran B — agenda

Ta sama strona, ten sam nagłówek, ten sam FilterBar, te same dane (D2).

```
┌ poniedziałek, 12 czerwca 2026 ───────────────── 3 ┐   ← sticky nagłówek grupy
│ ▌ 🗓  09:00–10:00  Nagranie odcinka   [Zaplanowane] ✎ │
│ ▌ ✅  cały dzień   Deadline: raport Q2 [W toku]     ↗ │
│ ▌ ⚙  14:30        Kampania Q3         [Zakończony] ↗ │
└──────────────────────────────────────────────────────┘
┌ czwartek, 15 czerwca 2026 ─────────────────────── 1 ┐
│ … │
```

| Reguła | Szczegół |
| --- | --- |
| Grupowanie | Po dniu (`start_date` albo `instantToZonedParts(starts_at, tz).day`). |
| **Dni puste są pomijane** | Agenda to lista tego, co jest — nie kalendarz. |
| Kolejność | Grup: rosnąco po dniu. W grupie: **kolejność z API**, bez re-sortu. |
| Nagłówek grupy | `fullDateLabel()` (zawiera miesiąc — obsługuje dni przelewowe, D2) + licznik po prawej. `position: sticky`, `bg-next-bg/95 backdrop-blur`. |
| „Dziś" | Nagłówek grupy dostaje `Badge primary subtle` z tekstem „Dziś" + `aria-current="date"`. |
| Kafelki | Wariant `agenda`: pełna godzina/zakres, **plakietka widoczna**, glif kierunku. |
| Serie gęste | Zwijane tak samo (§7.2), z tą różnicą, że chip pokazuje liczbę zwiniętych. |
| Wysokość | Bez wirtualizacji: 42 dni × maks. 1000 wystąpień to górna granica, której realny workspace nie dotyka. |

---

## 9. FilterBar + zapisane widoki

### 9.1 Skład

```vue
<FilterBar
  v-model:search="q"
  searchable
  sticky
  :active-filters="decoratedFilters"
  :search-placeholder="t('calendar.filters.searchPlaceholder')"
>
  <template #top>
    <FilterTabBar … />   <!-- OBOWIĄZKOWE na każdym ekranie listy w `next` -->
  </template>

  <SegmentedControl
    v-model="sourceFilter"
    multiple
    select-all
    :options="sourceOptions"           <!-- z meta.sources -->
    :aria-label="t('calendar.filters.sources')"
  />

  <template #results>…</template>
</FilterBar>
```

- `sourceOptions` powstaje **wyłącznie** z `meta.sources` (D6):
  `{ value: id, label, icon: sourceIcon(id) }`, `sourceIcon` z fallbackiem `circle`.
- Chipy aktywnych filtrów: jeden chip **na wartość** (nigdy „3 zaznaczone”),
  klucze `source:{id}` i `q`.
- Debounce wyszukiwania: domyślne 300 ms (`FilterBar`).

### 9.2 Zapisane widoki

- `useFilterTabs('calendar')` + `useFilterTabsStore()` + `SaveViewModal` — wzorzec 1:1
  z `WorkflowRunsListView.vue`.
- Snapshot: `{ sources?: string[], q?: string, mode?: 'grid'|'agenda' }`. Bez `month` (D9).
- Chipy dekorowane przez `savedViews.decorateActiveFilters()` (stany `extra` /
  `tab-disabled` / `tab-active`).

### 9.3 Jawnie BEZ kontrolki zakresu dat

**`DateRangeFilter` / `DateRangePicker` NIE pojawiają się na tym ekranie.**
Nawigacja siatki *jest* zakresem: `?month` w całości determinuje `from`/`to`. Druga
kontrolka daty byłaby sprzeczna sama ze sobą — użytkownik ustawiałby zakres, który
siatka i tak nadpisuje przy pierwszym kliknięciu w `›`. To ta sama przyczyna, dla której
zapisany widok nie zapamiętuje miesiąca (D9).

---

## 10. Legenda źródeł

### 10.1 Umiejscowienie

Pod siatką / pod agendą, w `Surface` o niskim priorytecie wizualnym:
poziomy, zawijający się rząd `Badge`ów, po jednym na wpis z `meta.sources`.

### 10.2 Co legenda naprawdę tłumaczy

**Ikonę, nie kolor.** Kolor kafelka pochodzi z `occurrence.color`, czyli z *pilności /
stanu*, a nie ze źródła — w jednym źródle (`task`) będą kafelki `danger` i `neutral`
jednocześnie. Legenda kolorów byłaby więc kłamstwem. Legenda mapuje **ikonę → nazwę
źródła**, plus osobny, krótki wiersz znaczeń koloru:

```
Źródła:   ✅ Terminy zadań   ⏱ Zaplanowane automatyzacje   ⚙ Przebiegi automatyzacji   🗓 Wydarzenia
Kolor:    oznacza pilność albo stan pozycji, nie jej źródło.
```

### 10.3 Źródło bez wystąpień w tym oknie

Etykieta źródła zostaje w legendzie w stanie normalnym (nie wygaszonym). Nie dodajemy
„0" ani „brak" — przeszły miesiąc **z definicji** nie ma zaplanowanych automatyzacji
(§2.2 pkt 1) i sugerowanie awarii byłoby fałszywe. Wygaszenie jest zarezerwowane
wyłącznie dla `unavailable_sources` (§14).

---

## 11. Podgląd wystąpienia i deep-linki

### 11.1 Kliknięcie kafelka

| `subject.type` | Zachowanie |
| --- | --- |
| `calendar_event` | `router.push({ query: { …, event: subject.id } })` → **szuflada wydarzenia** (§12). |
| pozostałe | **Popover podglądu** zakotwiczony w kafelku, zbudowany wyłącznie z pól wystąpienia. |

### 11.2 Popover podglądu (wystąpienia nieedytowalne)

Zawartość — wszystko z `occurrence`, nic dorobionego:

```
[ikona źródła]  {title}
{badge.label jako Badge}                     ← gdy badge !== null
Kiedy: {cały dzień · 12 czerwca 2026}        ← all_day
       {12 czerwca 2026, 14:30–15:10}        ← !all_day, ends_at
       {12 czerwca 2026, od 14:30}           ← !all_day, ends_at === null
Źródło: {meta.sources[source].label}
Strefa: {meta.timezone}                      ← przy formie godzinowej
[ Otwórz →  ]                                ← deep-link
```

**Popover NIE dociąga szczegółów podmiotu.** Kalendarz świadomie nie rozwija aliasu
morficznego; frontend też nie może udawać, że go zna. Wszystko, co da się powiedzieć,
niesie kafelek.

### 11.3 Mapa deep-linków (istniejące trasy `next`)

| `subject.type` | Cel | Sprawdzone w |
| --- | --- | --- |
| `task` | `/tasks?task={id}` | `pages/tasks/TasksView.vue` (`drawerTaskId`) |
| `workflow` | `/workflows?workflow={id}` | `pages/workflows/WorkflowsModuleLayout.vue` (`workflowParam`) |
| `workflow_run` | `/workflows?run={id}` | `pages/workflows/WorkflowsModuleLayout.vue` (`runParam`) |
| `calendar_event` | szuflada na miejscu (`?event=`) | — |
| **nieznany** | **brak przycisku „Otwórz"**, reszta popovera renderuje się normalnie | wymóg D6 |

Ostatni wiersz jest obowiązkowy: R4 doda `subject.type`, którego ta mapa nie zna, i ekran
ma to przetrwać bez błędu i bez martwego linku.

---

## 12. Szuflada wydarzenia — tworzenie i edycja

`Drawer side="right" size="md"`. Jedna szuflada, trzy tryby: **podgląd**, **edycja**,
**tworzenie**. Otwierana z URL (§3.3), więc jest deep-linkowalna i zamykana przyciskiem
Wstecz.

### 12.1 Tryb podglądu (`?event={uuid}`)

1. `GET /api/calendar/events/{uuid}`.
2. Póki leci — szkielet imitujący układ szuflady (tytuł, 2 wiersze meta, blok opisu).
3. Treść: tytuł, blok „Kiedy" (identyczne reguły co §11.2), opis
   (`whitespace-pre-wrap`, **nie markdown**), `CreatorBadge` z `creator`, daty
   `created_at`/`updated_at` w `DescriptionList`. **Bez plakietki koloru** — zasób
   `CalendarEventResource` nie niesie `color` (§2.3); kolor na siatce jest stałą serwera,
   nie atrybutem tego wydarzenia do pokazania w podglądzie.
4. Akcje w stopce: `Edytuj` (`v-if="can_be_edited"`), `Usuń`
   (`variant="danger"`, `v-if="can_be_deleted"`).
   **Bramkowanie wyłącznie na `can_*`, nigdy na `is_owner`** — wydarzenie utworzone przez
   przebieg workflow ma `is_owner: false`, a właściciel workspace'u **może** je poprawić.
5. Gdy oba `can_*` są `false`: brak przycisków + tekst wyjaśniający
   *„To wydarzenie może edytować jego autor lub właściciel przestrzeni."* (reguła
   „disabled/nieobecna akcja musi pozostać zrozumiała").

### 12.2 Formularz (edycja i tworzenie)

| Pole | Kontrolka | Reguła |
| --- | --- | --- |
| `title` | `TextInput` | required, `maxlength=255`, licznik od 200 znaków |
| `all_day` | `Switch` | **Rozróżnik. Zawsze widoczny, zawsze wysyłany.** |
| `start_date` | `DatePicker` | widoczne **wtw** `all_day`; model `yyyy-mm-dd` — dokładnie kontrakt `dateCore` |
| `starts_at` / `ends_at` | **pole złączone** (§12.3) | widoczne **wtw** `!all_day` |
| `description` | `Textarea` `rows=4` | `maxlength=5000` |
| `subject_*` | **brak kontrolki** — patrz §12.4 | wartości przenoszone, nie edytowane |

**Brak kontrolki koloru — świadomie, nie brak dowozu.** Formularz nie ma pola `color`.
Wydarzenie nie ma własnego koloru do wyboru (§2.2 „Czwarty fakt"); dodanie tu
`SegmentedControl` sześciu wartości byłoby przywróceniem wyboru, który serwer odrzuca 422-ką.

**Przełączenie `all_day` nie kasuje wpisanych wartości w stanie formularza** — użytkownik,
który przełączy tam i z powrotem, odzyska swoją godzinę. Wysyłana jest tylko grupa
odpowiadająca bieżącemu `all_day` (druga jest przez API **zabroniona**, nie ignorowana:
nadmiarowe `starts_at` przy `all_day: true` to 422).

### 12.3 Pole złączone daty i godziny (jointed field)

Zamiast dwóch osobnych, równoprawnych kolumn „Data" i „Godzina" — jedna grupa
`FieldShell` z etykietą **„Początek"** i dwoma wewnętrznymi kontrolkami
(`DatePicker` + `TimePicker`) rozdzielonymi hairline'em, oraz druga, opcjonalna,
z etykietą **„Koniec"**. Powód: to jest jedna wartość (chwila), a nie dwa niezależne
pola; dwukolumnowy formularz sugerowałby, że da się zapisać samą godzinę.

Pod grupą — trwały (nie na hover) tekst pomocniczy:
*„Godzina w strefie zespołu: {meta.timezone}"*.

**Serializacja (luka L1 — ZAMKNIĘTA po stronie backendu, patrz §23):**

```
lokalnie:  '2026-08-09' + '14:30'  →  '2026-08-09T14:30'
wysyłka:   zonedWallClockToInstant('2026-08-09T14:30', meta.timezone)
           → '2026-08-09T14:30:00+02:00'      ← ISO z JAWNYM offsetem
```

**Historia (fakt sprzed naprawy).** Wysłanie wartości bez strefy (`'2026-08-09T14:30'`, czyli
natywny model `DateTimePicker`) było w tym module błędem: `$request->date()` interpretowała ją
w `config('app.timezone')` = `UTC`, podczas gdy siatka rysowała w strefie workspace'u.
**Dziś backend (`CalendarInstantResolver`) czyta gołą wartość w strefie workspace'u — czyli
poprawnie — więc obejście nie jest już wymagane.** UI mimo to nadal wysyła ISO z jawnym
offsetem: nieszkodliwe (jawny offset zawsze wygrywa) i nie ma powodu tego upraszczać w tym
rozdziale.

### 12.4 Zapis — pełny obiekt, zawsze

```
PUT /api/calendar/events/{id}
{
  title, description, all_day,
  start_date | starts_at (+ ends_at),
  subject_type, subject_id        ← PRZENIESIONE z GET-a, nie z formularza
}
```

**Bez `color` — jego wysłanie to 422**, nie łatka do wyzerowania (§2.3). Store nie ma
tego klucza w draftcie, więc nie ma go skąd dołożyć do payloadu.

Pominięcie `subject_*` **wyzeruje wskaźnik** wydarzenia utworzonego przez krok
`create_event`. Store trzyma pełny obiekt z `GET` i buduje payload z niego, nadpisując
tylko edytowane pola.

### 12.5 Stany zapisu

| Stan | UI |
| --- | --- |
| gotowy | `Button` primary aktywny |
| walidacja lokalna nie przeszła | primary **disabled** + `title` z powodem (reguła czytelnego disabled) |
| zapis w toku | `Button loading` (spinner **w przycisku**, tekst zostaje, szerokość stała) — pola `readonly`, nie `disabled` (żeby nie znikały z drzewa a11y) |
| 422 | błędy pod polami, treść **z serwera**; focus na pierwsze błędne pole; toast **nie** |
| 4xx/5xx | `Alert danger` na górze szuflady + toast; **formularz zostaje wypełniony** |
| sukces | szuflada zamyka się, toast sukcesu, siatka odświeża bieżące okno |

### 12.6 Usuwanie

`ConfirmDialog` (danger): tytuł *„Usunąć wydarzenie?"*, treść z nazwą, przycisk
*„Usuń"*. Po `204` — zamknięcie szuflady, toast, refetch.
**Nie obiecujemy przywracania**: wiersze są soft-delete, ale endpointu `restore` nie ma,
więc copy nie może mówić „możesz to cofnąć".

---

## 13. Trzy rodzaje straty

To jest sedno tej specyfikacji. Backend rozdzielił stratę na trzy **strukturalnie różne**
rodzaje właśnie po to, żeby interfejs nie mówił jednego zdania o trzech różnych faktach.

### 13.0 Zasady wspólne

| Zasada | Konsekwencja w kodzie |
| --- | --- |
| Sekcja istnieje wtw `meta.truncated === true` | `meta.truncated` **nie wchodzi** do żadnego napisu (D7) |
| Jeden `Alert variant="warning"` z listą — **jeden wiersz na `(source, kind)`** | `v-for` po `meta.truncations` |
| Nazwa źródła zawsze z `meta.sources`, po `id` | nigdy zakodowana |
| **`omitted_occurrences` / `affected_items` mogą być `null` = NIEZNANE** | **`count ?? 0` jest zakazane.** `null` wybiera **inny klucz i18n**, nie inną wartość liczby |
| Nigdy „0", nigdy puste nawiasy | dwa warianty copy na rodzaj: `…WithCount` / `…Unknown` |
| Copy bez odmiany liczebnika | `t()` nie ma maszynerii liczby mnogiej — kształt „Etykieta: N", nie „3 automatyzacje" |

**Co realnie przychodzi z serwera** (odczytane z kodu — przydatne przy pisaniu testów):

| `kind` | `omitted_occurrences` | `affected_items` |
| --- | --- | --- |
| `window_trimmed` | `int` z globalnego przycięcia, **albo `null`**, gdy źródło samo się przycięło (scalanie: nieznane zatruwa sumę) | zawsze `null` |
| `item_densified` | zawsze `null` | `int ≥ 1` |
| `items_dropped` | zawsze `null` | zawsze `null` |

Interfejs **nie może** na tym polegać (kontrakt dopuszcza `null` wszędzie), ale test
jednostkowy komponentu ma pokryć wszystkie sześć kombinacji.

### 13.1 `window_trimmed` — ucięty daleki koniec okna

> To, co widzisz, jest **kompletne do pewnego momentu**; dalej jest pusto, bo zostało
> ucięte, a nie dlatego, że nic tam nie ma.

- **Z liczbą:** *„{źródło}: nie zmieściło się {n} wystąpień z końca tego zakresu.
  Wcześniejsze dni są kompletne."*
- **Bez liczby:** *„{źródło}: część wystąpień z końca tego zakresu nie zmieściła się
  w widoku. Wcześniejsze dni są kompletne."*
- Akcja w wierszu: *„Zawęź filtry"* — `Button ghost xs`, przewija do FilterBara i ustawia
  focus na filtrze źródeł.

### 13.2 `items_dropped` — całych pozycji nie ma wcale

> **Najgroźniejszy w odbiorze.** Użytkownik nie ogląda niepełnego widoku wszystkiego —
> ogląda **kompletny widok niektórych rzeczy i żaden widok innych**. Cała automatyzacja
> znika z siatki, a nie jej końcówka.

- **Bez liczby (przypadek realny):** *„{źródło}: część pozycji nie zmieściła się w tym
  widoku **w całości** — nie brakuje im końcówki, nie ma ich wcale. Zawęź filtry albo
  wyszukaj konkretną pozycję."*
- **Z liczbą:** *„…: {n} pozycji nie ma w tym widoku w całości…"*
- Wiersz tego rodzaju dostaje **ikonę `alert-triangle`** (pozostałe: `info`), bo to jedyny
  rodzaj, przy którym użytkownik może podjąć **złą decyzję**, ufając pustej kratce.
- Akcja: *„Szukaj po nazwie"* — focus w pole `q`.

### 13.3 `item_densified` — pojedyncza pozycja zagęszczona

> Na ekranie jest **próbka** serii, nie seria.

- **Z liczbą:** *„{źródło}: pozycje powtarzające się częściej, niż da się pokazać: {n}.
  Widzisz **początek serii** — puste dni po niej nie znaczą, że nic się nie dzieje."*
- **Bez liczby:** to samo bez członu z liczbą.
- Drugie zdanie jest **wymagane**, nie ozdobne: budżet 64 wystąpień wypełnia się od
  kotwicy w przód, więc kadencja minutowa rysuje urwisko w jednym dniu i pustkę dalej
  (§2.2 pkt 2). Bez tego zdania użytkownik odczyta pustkę jako „automatyzacja stanęła".
- Powiązanie wizualne: kafelki tej pozycji niosą `dense: true` i znacznik serii (§7.2),
  więc komunikat ma na siatce swoje odbicie.

---

## 14. Awaria pojedynczego źródła

Backend jest fail-soft: `CalendarSourceRegistry` loguje i **pomija** źródło, które nie
odpowiedziało, a reszta siatki się renderuje. `meta.unavailable_sources` niesie listę
`{ source, reason }` — **z powodem**, nie samą listę id (patrz §2.1) — i powód **rozgałęzia
zachowanie UI, nie tylko treść komunikatu**.

**Oś, która się liczy: czy ponowienie coś da.**

| `reason` | Znaczenie | Ponowienie |
| --- | --- | --- |
| `failed` | Źródło zapytano i **rzuciło** — chwilowa awaria, timeout. | **Tak — jedyny z trzech.** |
| `not_constructed` | Źródło nigdy nie powstało (konfiguracja, zepsuty boot). | Nie — identyczny wynik za chwilę. |
| `malformed` | Źródło odpowiedziało, ale nie kalendarzowym kształtem. | Nie — defekt kodu źródła, powtórzy się dokładnie. |
| *(nieznany, przyszły kod)* | Backend nowszy niż ten frontend. | **Traktowany jako nieponawialny** — „nie wiemy, czy się naprawi" nie jest podstawą, by obiecać, że tak. Źródło i tak jest **nazwane**, nigdy nie pomijane za nierozpoznanie. |

| Reguła | Szczegół |
| --- | --- |
| **Kalendarz się NIE gasi** | Siatka renderuje wszystko, co przyszło. Zero pustego ekranu. |
| Komunikat | Osobny `Alert variant="danger"` **nad** komunikatami ucięcia — to awaria, nie limit, i nie wolno ich mieszać w jednej liście. |
| Okno mieszane (część `failed`, część nie) | **Dwa wiersze w jednym Alercie**, nigdy jedno uśrednione zdanie: wiersz źródeł ponawialnych **bezpośrednio nad przyciskiem** (żeby nie było wątpliwości, czego dotyczy), potem wiersz źródeł nieponawialnych, potem stały wiersz „Reszta kalendarza jest aktualna." |
| Copy (ponawialne) | *„Nie udało się wczytać: {lista}. To zwykle chwilowe — spróbuj ponownie."* |
| Copy (nieponawialne) | *„Nie udało się wczytać: {lista}. Ponowna próba tego nie zmieni; ktoś musi się temu przyjrzeć."* |
| Akcja | `Button` *„Spróbuj ponownie"* → ten sam refetch okna. **Renderowany wyłącznie, gdy istnieje choć jedno źródło z `reason: failed`** — dla samych `not_constructed`/`malformed` przycisku **nie ma**: klik nic by nie naprawił. |
| Legenda | Etykiety niedostępnych źródeł: `opacity-60` + `line-through` + ikona `alert-circle`, `title` = jeden ogólny komunikat („Nie udało się wczytać") — **nie** rozróżnia `reason` w treści `title`; rozróżnienie żyje wyłącznie w Alercie powyżej. Jedyne miejsce, gdzie źródło jest wygaszone (§10.3). |
| Filtr | Chip źródła zostaje **włączalny** — po naprawie ma zadziałać bez czyszczenia filtrów. |
| `unavailable ≠ pusto` | „Brak zaplanowanych automatyzacji" i „harmonogramy się nie wczytały" to dwa różne zdania i nigdy nie renderują tego samego. |

Implementacja: `calendarMeta.ts::isRetryableUnavailability` (jedyne miejsce czytające `reason`;
nieznany kod → `false`) + `CalendarView.vue::unavailableGroups` (dzieli wpisy na `retryable`/
`permanent`, po jednym renderowalnym wierszu na grupę).

---

## 15. Stany: ładowanie, pusto, błąd

### 15.1 Ładowanie (pierwsze wejście) — szkielet **jest siatką**

Reguła projektu: *szkielet imituje realny element, i pokazujemy kilka sztuk.*

**Tryb siatki:** renderuje się **prawdziwa siatka 6×7** — nagłówki dni tygodnia,
ramki komórek, numery dni jako `Skeleton variant="text" width="1.5rem"` — a w środku
komórek `Skeleton` w kształcie kafelka (`variant="rect"`, `height="1.25rem"`,
`radius="sm"`), **deterministycznie** 0/1/2/3 sztuki wg `index % 4`, żeby układ nie
migotał przy re-renderze. Kontener: `Skeleton` `label` → jeden uprzejmy
`role="status"` na całą siatkę.

**Tryb agendy:** 3 grupy dni, w każdej nagłówek (`text`, 12 rem) + 2–3 wiersze
(kropka koloru `circle` + `text` 4 rem godziny + `text` 60% tytułu + `rect` plakietki).

**Zakazane:** `Spinner` + „Ładowanie…" jako główny stan tego ekranu.

### 15.2 Pusto

| Sytuacja | Zachowanie |
| --- | --- |
| Siatka, `data: []`, brak filtrów | **Bez `EmptyState`** (D8). Siatka pusta, `#results`: „0 wystąpień". |
| Siatka, `data: []`, filtry aktywne | jw. + w `#results` link `Button ghost xs` „Wyczyść filtry". |
| Agenda, `data: []`, brak filtrów | `EmptyState` `variant="default"`, `icon="calendar"`, tytuł *„Nic w tym zakresie"*, opis *„Terminy, automatyzacje i wydarzenia z tego miesiąca pojawią się tutaj."*, `#action`: **Nowe wydarzenie**. |
| Agenda, `data: []`, filtry aktywne | `EmptyState` `variant="search"`, tytuł *„Brak wyników dla tych filtrów"*, `#action`: **Wyczyść filtry**. |

### 15.3 Błąd całego zapytania

`EmptyState variant="error"` (`role="alert"`) zamiast siatki/agendy:
tytuł *„Nie udało się wczytać kalendarza"*, opis z treścią błędu, `#action`
**Spróbuj ponownie**. Nagłówek, FilterBar i nawigacja miesiąca **zostają aktywne** —
użytkownik ma móc zmienić miesiąc albo filtr i tym samym ponowić.

**Przypadek szczególny 422 `window_too_large`:** nie powinien wystąpić (42 < 62), a jeśli
wystąpi, to znaczy, że ktoś zmienił budowanie okna. Komunikat serwera renderujemy
dosłownie — to jest sygnał defektu, nie stan użytkownika.

### 15.4 Odświeżanie (zmiana miesiąca / filtra)

Poprzednie dane **zostają na ekranie**, kontener dostaje `aria-busy="true"` i
`opacity-60 pointer-events-none`, a strzałki miesiąca zostają aktywne (szybkie
przewijanie miesięcy nie może się blokować). Wyścigi: store trzyma token żądania i
odrzuca odpowiedzi starsze niż ostatnie.

### 15.5 Sukces zapisu

`useToast` — wariant sukcesu, treść *„Wydarzenie zapisane"* / *„Wydarzenie usunięte"*.
Toast **nie** jest używany do błędów walidacji (te są przy polach).

---

## 16. Kolory, tokeny, dark mode

### 16.1 Mapa `color` → tokeny (zamknięty enum, 6 wartości)

Wartości `CalendarColor` to **dokładnie** warianty, które rozumie
`ui/primitives/Badge.vue` — źródło wybiera **znaczenie**, design system wybiera piksele.

| `color` | Pasek kafelka (solid) | Tło kafelka | Tekst kafelka | Wariant `Badge` |
| --- | --- | --- | --- | --- |
| `neutral` | `bg-next-muted-foreground` | `bg-next-muted` | `text-next-fg` | `neutral` |
| `primary` | `bg-next-primary` | `bg-next-primary-subtle` | `text-next-primary-subtle-foreground` | `primary` |
| `success` | `bg-next-success` | `bg-next-success-subtle` | `text-next-success-subtle-foreground` | `success` |
| `warning` | `bg-next-warning` | `bg-next-warning-subtle` | `text-next-warning-subtle-foreground` | `warning` |
| `danger` | `bg-next-danger` | `bg-next-danger-subtle` | `text-next-danger-subtle-foreground` | `danger` |
| `info` | `bg-next-info` | `bg-next-info-subtle` | `text-next-info-subtle-foreground` | `info` |

Mapa mieszka w **jednym** module `pages/calendar/calendarMeta.ts` (obok mapy ikon źródeł),
z fallbackiem `neutral` dla nieznanej wartości — dotyczy `occurrence.color`/`badge.color`
(zawsze jedną z sześciu, ale przyszły enum może urosnąć), **nie** `CalendarEventResource`,
który tego pola już w ogóle nie ma (§2.3).
**Zakaz `bg-[#…]`, zakaz `variant`u budowanego stringowo bez whitelisty** (Tailwind v4
nie wygeneruje klasy, której nie widzi w źródle).

### 16.2 Dark mode

Wszystko powyżej to tokeny semantyczne — dark mode działa przez podmianę tokenów pod
`.next-root.dark`, **bez jednej odwróconej wartości w komponentach**. Punkty, które trzeba
sprawdzić wizualnie w obu motywach:

| Element | Ryzyko | Wymóg |
| --- | --- | --- |
| Komórka „dziś" (`bg-next-primary-subtle`) | W dark `primary-subtle` to `hsl(325 40% 18%)` — blisko `card` (12%) | Kontrast niesie też pastylka numeru (solid primary) i pogrubienie — trzy sygnały |
| Weekend (`bg-next-muted/40`) | W dark `muted` = `hsl(320 10% 18%)` na `bg` 8% | Musi być czytelnie **inny** od komórki spoza miesiąca; sprawdzić razem |
| Kafelek `neutral` | `bg-next-muted` + `text-next-fg` | AA dla tekstu 12–13 px |
| Pasek koloru `w-1` | Solidne tokeny mają w dark wyższą jasność (np. `success` 34%→46%) | Bez zmian w kodzie — to właśnie robi podmiana tokenów |
| Hairline siatki | `border-next-border` | W dark to 28% — sprawdzić, czy siatka nie znika na `bg` 8% |

### 16.3 Kontrast i „kolor nigdy sam"

- Kafelek: kolor **+ ikona źródła + tekst**.
- „Dziś": kolor **+ pastylka + `font-next-semibold` + `aria-current`**.
- Plakietka: kolor **+ proza z `badge.label`**.
- Gęstość: kolor **nie uczestniczy** — znacznik to `Badge` z tekstem.
- Edytowalność: **glif**, nie odcień (D4).

---

## 17. Responsywność

| Breakpoint | Układ |
| --- | --- |
| ≥ `next-xl` | Siatka 6×7, komórki `min-h-[7.5rem]`, **3** kafelki + `+N`. Legenda w jednym rzędzie. |
| `next-lg` … `next-xl` | Siatka 6×7, komórki `min-h-[6.5rem]`, **2** kafelki + `+N`. |
| `next-md` … `next-lg` | Siatka 6×7 **bez tekstu w kafelkach**: każdy dzień pokazuje numer + do 4 **kropek** koloru (`h-1.5 w-1.5`, `aria-hidden`), a licznik `N` obok numeru. Cały dzień jest jednym przyciskiem otwierającym popover dnia. Nagłówek dnia tygodnia skraca się do 1 litery (`weekdayNames(..., 'narrow')`). |
| < `next-md` | **Agenda**, zawsze. Siatka 2-D w szerokości telefonu przestaje być czytelna, a jej skurczona wersja to dokładnie ten antywzorzec, który reguła „nie zmniejszaj tabeli, przekształć ją" zakazuje. |

**Zachowanie przełącznika trybu poniżej `next-md`:** `SegmentedControl` **znika**
(nie jest disabled — disabled sugeruje, że coś jest zepsute), a `?mode` **zostaje
w URL nietknięte**. Po poszerzeniu okna / obróceniu urządzenia siatka wraca dokładnie
tam, gdzie była. Dokładnie ta sama zasada, którą stosuje `Table` z `responsive="stack"`:
komponent decyduje o prezentacji na podstawie viewportu, a preferencja użytkownika
przeżywa.

**Agenda na wąskim ekranie:** nagłówki grup `sticky`, kafelek łamie się na dwie linie
(linia 1: godzina + tytuł; linia 2: plakietka + źródło), glif kierunku zostaje po prawej
w linii 1. Docelowy rozmiar celu dotykowego ≥ 44 px.

**Szuflada:** `size="md"` od `next-md`; poniżej `size="full"`.

---

## 18. Dostępność

### 18.1 Siatka — wzorzec skopiowany z `CalendarPanel.vue`

| Element | ARIA |
| --- | --- |
| Kontener | `role="grid"`, `aria-label` = etykieta miesiąca |
| Wiersz | `role="row"` |
| Komórka dnia | `role="gridcell"`, `aria-label` = `fullDateLabel(date, locale)` **+ liczba wystąpień** |
| Dziś | `aria-current="date"` |
| Poza bieżącym miesiącem | `aria-disabled` **NIE** — te dni są w oknie i są klikalne |
| Sfokusowany dzień | roving `tabindex` (dokładnie jeden `0` w siatce) |
| Anons | `<span class="sr-only" aria-live="polite">` z pełną datą + liczbą wystąpień |

Klawiatura — **identyczna** z `CalendarPanel` (użytkownik zna ją z każdego pickera dat):

| Klawisz | Akcja |
| --- | --- |
| `←` / `→` | poprzedni / następny dzień |
| `↑` / `↓` | poprzedni / następny tydzień |
| `PageUp` / `PageDown` | poprzedni / następny miesiąc (zmienia `?month`) |
| `Shift+PageUp/Down` | poprzedni / następny rok |
| `Home` / `End` | pierwszy / ostatni dzień **tygodnia** |
| `Enter` / `Spacja` | popover dnia (dzień pusty → szuflada tworzenia) |
| `Esc` | zamyka popover / szufladę (przez `useOverlayStack`) |

**Kafelki NIE są osobnymi punktami tabulacji.** Siatka to jeden punkt (roving tabindex);
41 dodatkowych stopów zamieniłoby przejście przez ekran w wędrówkę. Dostęp do kafelków
prowadzi przez popover dnia, którego lista jest zwykłą, fokusowalną listą przycisków.
To standardowy wzorzec `grid` i jest to świadomy kompromis, nie oszczędność.

### 18.2 Reszta

| Powierzchnia | Wymóg |
| --- | --- |
| Agenda | Zwykły przepływ dokumentu; nagłówek grupy to prawdziwy `<h2>`, grupa to `role="group" aria-labelledby`. Każdy kafelek — `Button`. |
| Popover dnia | `useAnchoredPosition`, focus do środka po otwarciu, powrót na wyzwalacz po zamknięciu, `Esc` zamyka. |
| Szuflada | `role="dialog" aria-modal`, focus-trap, `Esc`, powrót fokusu (daje `Drawer.vue`). |
| Komunikaty ucięcia | `Alert` `warning` → `role="alert"`. Renderowany **raz** po wczytaniu — nie migocze przy każdym re-renderze (klucz stabilny per `(source, kind)`). |
| Awaria źródła | `Alert danger` → `role="alert"`. |
| Chip strefy | Nie samo `title` — treść chipa jest realnym tekstem. |
| Kolor kafelka | Nigdy jedyny nośnik informacji (§16.3). |
| Reduced motion | Przejście miesiąca: bez animacji przesuwu przy `prefers-reduced-motion` (globalna reguła `.next-root` + lokalny fallback). |
| Kontrast | Tekst kafelka ≥ AA na `*-subtle` w obu motywach — do sprawdzenia ręcznie dla `warning` (najtrudniejszy). |

---

## 19. Inwentarz komponentów (reuse / extend / create)

### 19.1 Reuse — bez zmian

`PageHeader`, `FilterBar`, `FilterTabBar`, `SaveViewModal`, `SegmentedControl`
(`multiple` + `selectAll`), `Badge`, `Button`, `Icon`, `Skeleton`, `EmptyState`,
`Alert`, `Modal`, `Drawer`, `ConfirmDialog`, `TextInput`, `Textarea`, `Switch`,
`DatePicker`, `TimePicker`, `FieldShell`, `FormField`, `DescriptionList`,
`CreatorBadge`, `Surface`, `Stack`, `Toast` (przez `useToast`).

**Nie** `Popover` (`ui/overlay/Popover.vue`) — mimo nazw plików `DayPopover.vue` /
`OccurrencePopover.vue`, oba renderują `Modal`. Powód: §23, „Uwaga systemowa — brak
zewnętrznej kotwicy w `Popover`".

Composables (te, które **naprawdę istnieją** w `next/app/composables/`):
`useI18n`, `useToast`, `useConfirm`, `useFilterTabs`, `useAnchoredPosition`,
`useDebounce`, `useOverlayStack`, `useFocusTrap`, `useOutsideClick`.
**`useRouteQueryHydration` NIE istnieje w `next`** — synchronizacja z URL ręcznie (§3.3).

Z `dateCore`: `buildMonthWeeks`, `weekdayNames`, `monthNames`, `monthYearLabel`,
`fullDateLabel`, `toIsoDate`, `fromIsoDate`, `addMonths`, `startOfMonth`, `isSameDay`.

### 19.2 Extend — jedna pozycja

| Plik | Zmiana | Uzasadnienie |
| --- | --- | --- |
| `ui/primitives/icons.ts` | dodać `'repeat'` do `IconName` **i** do `ICONS` | Znacznik serii gęstej. `more-horizontal` czyta się jako menu akcji, `rotate-ccw` jest już zajęta przez „przywróć filtr". Rejestr jest z założenia rozszerzalny (tak dodano `map-pin`, `package`). |

**Zaimplementowane.** `'repeat'` jest w `IconName`/`ICONS` (z tym samym uzasadnieniem
zapisanym jako komentarz w kodzie, `icons.ts`) i w użyciu w `OccurrenceChip.vue` (znacznik
gęstej serii, §7.2). Zastępczy `clock` z pierwotnej wersji tej tabeli już nie obowiązuje.

### 19.3 Create — nowe pliki modułu

| Plik | Rola |
| --- | --- |
| `pages/calendar/CalendarView.vue` | Ekran: nagłówek, FilterBar, nawigacja miesiąca, przełącznik trybu, komunikaty, legenda, obie powierzchnie. |
| `pages/calendar/MonthGrid.vue` | Siatka 6×7: role, roving tabindex, klawiatura, live-region. |
| `pages/calendar/DayCell.vue` | Komórka: numer, „dziś", weekend, kafelki, `+N`, pusty hover. |
| `pages/calendar/AgendaList.vue` | Grupy dni, sticky nagłówki, pomijanie dni pustych. |
| `pages/calendar/OccurrenceChip.vue` | Kafelek w trzech wariantach gęstości (§6). |
| `pages/calendar/DayPopover.vue` | Pełna lista dnia (nadmiar + rozwinięcie serii). |
| `pages/calendar/OccurrencePopover.vue` | Podgląd wystąpienia nieedytowalnego + deep-link. |
| `pages/calendar/EventDrawer.vue` | Podgląd / edycja / tworzenie wydarzenia. |
| `pages/calendar/TruncationNotices.vue` | Trzy rodzaje straty, sześć wariantów copy. |
| `pages/calendar/calendarMeta.ts` | Mapy: `color`→tokeny, `source`→ikona (z fallbackiem), `subject.type`→trasa (z fallbackiem). **Czyste, testowalne.** |
| `pages/calendar/calendarZone.ts` | Trzy funkcje strefowe z §4.3. **Czyste, testowalne.** |
| `pages/calendar/types.ts` | Typy 1:1 z kontraktem §2. Bez wymyślonych pól. |
| `app/stores/calendar.ts` | Pinia: okno, `data`, `meta`, filtry, token żądania, CRUD wydarzeń. |

**Zero nowych zależności npm.**

### 19.4 Testy Vitest — minimum

1. `calendarZone` — DST wiosną/jesienią, strefa zachodnia, UTC, granice doby.
2. Bucketowanie: całodniowe wystąpienie **nie zmienia dnia** w skrajnej strefie.
3. `TruncationNotices` — 3 rodzaje × {liczba znana, `null`}; asercja, że nigdzie nie pada `0`.
4. Zwijanie serii gęstej: N wystąpień jednego `subject.id` → jeden kafelek.
5. `+N` liczone **po** zwinięciu.
6. Nieznany `source` → ikona fallback; nieznany `subject.type` → brak „Otwórz", brak błędu.
7. `unavailable_sources` niepuste → siatka nadal renderuje wystąpienia.
8. Payload `PUT` zawiera `subject_type`/`subject_id` z `GET`-a.

---

## 20. Copy i klucze i18n

Namespace `calendar.*` w `resources/js/next/app/i18n/{en,pl}.ts` (oba katalogi 1:1,
`en.ts` jest źródłem typu) + `nav.calendar`.

```
nav.calendar

calendar.title
calendar.subtitle

calendar.mode.grid | calendar.mode.agenda | calendar.mode.label
calendar.nav.prevMonth | nextMonth | today | todayDisabled

calendar.timezone.chip            "Strefa: {tz}"
calendar.timezone.mismatch        "Kalendarz pokazuje dni i godziny w strefie zespołu ({tz}). Twoja przeglądarka jest w {localTz}."
calendar.timezone.fieldHint       "Godzina w strefie zespołu: {tz}"

calendar.day.more                 "+{n} więcej"
calendar.day.showAll              "Pokaż wszystkie wystąpienia: {date}"
calendar.day.count                "Wystąpienia: {n}"
calendar.day.empty                "Brak wystąpień"
calendar.day.createHere           "Dodaj wydarzenie na ten dzień"

calendar.occurrence.allDay        "cały dzień"
calendar.occurrence.from          "od {time}"
calendar.occurrence.range         "{from}–{to}"
calendar.occurrence.when          "Kiedy"                (etykieta w OccurrencePopover)
calendar.occurrence.source        "Źródło"                (etykieta w OccurrencePopover)
calendar.occurrence.timezone      "Strefa"                (etykieta w OccurrencePopover, tylko !all_day)
calendar.occurrence.open          "Otwórz"                (link.kind === 'open')
calendar.occurrence.openList      "Otwórz listę uruchomień" (link.kind === 'list' — patrz L7 w §23; NIGDY "Otwórz" dla tego wiersza)
calendar.occurrence.editable      "Można edytować tutaj"
calendar.occurrence.external      "Otworzy się w innym module"

calendar.dense.chip               "Seria — pokazano {shown}"
calendar.dense.aria               "Ta pozycja powtarza się częściej, niż widać na siatce"

calendar.filters.searchPlaceholder | .sources | .clearAll
calendar.results.count            "Wystąpienia: {n}"
calendar.results.none             "0 wystąpień"

calendar.legend.sources           "Źródła"
calendar.legend.colorNote         "Kolor oznacza pilność albo stan pozycji, nie jej źródło."
calendar.legend.unavailable       "Nie udało się wczytać"

calendar.truncation.title         "Ten widok nie pokazuje wszystkiego"
calendar.truncation.windowTrimmed.withCount
calendar.truncation.windowTrimmed.unknown
calendar.truncation.itemsDropped.withCount
calendar.truncation.itemsDropped.unknown
calendar.truncation.itemDensified.withCount
calendar.truncation.itemDensified.unknown
calendar.truncation.action.narrowFilters
calendar.truncation.action.searchByName

calendar.unavailable.retryable     "Nie udało się wczytać: {sources}. To zwykle chwilowe — spróbuj ponownie."
calendar.unavailable.permanent     "Nie udało się wczytać: {sources}. Ponowna próba tego nie zmieni; ktoś musi się temu przyjrzeć."
calendar.unavailable.rest          "Reszta kalendarza jest aktualna."
calendar.unavailable.retry         "Spróbuj ponownie"

calendar.empty.title | .description | .action
calendar.empty.filtered.title | .action
calendar.error.title | .description | .retry

calendar.event.new | .edit | .view
calendar.event.field.title | .description | .allDay | .start | .end
calendar.event.save | .cancel | .delete
calendar.event.deleteConfirm.title | .message | .confirm
calendar.event.saved | .deleted
calendar.event.noPermission       "To wydarzenie może edytować jego autor lub właściciel przestrzeni."
calendar.event.createdByRun       "Utworzone przez automatyzację"
```

**Nie ma kluczy na:** nazwy źródeł (`meta.sources[].label`), treści plakietek
(`badge.label`), komunikaty walidacji (serwer). Tłumaczenie ich po stronie klienta
złamałoby obietnicę „czwarte źródło bez zmiany frontendu".

**Nie ma `calendar.event.field.color` ani `calendar.event.color.<...>` — świadomie usunięte,
nie zapomniane.** `i18n.spec.ts` asertuje wprost, że obu wzorców nie ma w żadnym z dwóch
katalogów (a razem z nimi — `workflows.step.create_event.color`/`.colorHint`): nazwa koloru
wracająca do katalogu tłumaczeń jest tanią połową powrotu kontrolki wyboru koloru.

**Bez odmiany liczebników** — `t()` nie ma maszynerii liczby mnogiej, więc każdy licznik
ma kształt „Etykieta: {n}".

---

## 21. Handoff do frontend-agent

### UX Goal

Jedna oś czasu workspace'u, w której **cztery niezależne moduły** dają się przeczytać jak
jedna rzecz — bez utraty tego, czym się od siebie różnią: dzień vs. chwila, plan vs.
historia, edytowalne vs. cudze. Ekran ma być uczciwy co do tego, czego **nie** pokazuje.

### User Flow

1. Menu → **Kalendarz** → siatka bieżącego miesiąca (strefa workspace'u).
2. `‹` / `›` / `Dziś` / `PageUp`/`PageDown` → zmiana `?month` → refetch okna 42 dni.
3. Filtr źródeł (z `meta.sources`) i/lub szukanie `q` → refetch. Zestaw da się zapisać
   jako widok (`FilterTabBar`).
4. Kliknięcie kafelka: wydarzenie → szuflada; reszta → popover + „Otwórz" do modułu
   właściciela.
5. Kliknięcie tła dnia albo **Nowe wydarzenie** → szuflada tworzenia z zasianym dniem.
6. Przełącznik **Siatka / Agenda** → zmiana prezentacji **bez** odpytania serwera.
7. Gdy odpowiedź jest niepełna — komunikaty (§13); gdy źródło padło — komunikat awarii
   (§14), a kalendarz działa dalej.

### Screen Structure

`PageHeader` (+ tryb, + „Nowe wydarzenie”) → `FilterBar` (`#top`: `FilterTabBar`) →
`Alert` awarii → `Alert` ucięć → pasek miesiąca (+ chip strefy) → **siatka 6×7** albo
**agenda** → legenda. Szuflada i popovery jako nakładki. Szczegóły: §5.1.

### Components Needed

Reuse: §19.1. Extend: ikona `repeat` (§19.2). Create: 13 plików modułu `pages/calendar/`
+ store (§19.3). **Zero nowych zależności npm.**

### States

Ładowanie (**szkielet w kształcie siatki, kilka sztuk**), odświeżanie (stare dane +
`aria-busy`), pusto (siatka — bez `EmptyState`; agenda — z `EmptyState`), pusto po
filtrach (`variant="search"`), błąd całości (`EmptyState variant="error"` + retry),
**awaria pojedynczego źródła** (grid renderuje, `Alert danger`; przycisk retry **tylko**,
gdy choć jedno źródło ma `reason: failed` — §14), **ucięcie ×3 rodzaje ×2 warianty liczby**,
stany zapisu w szufladzie (§12.5).

### Responsive Rules

≥`next-xl`: 3 kafelki/dzień · `next-lg`: 2 · `next-md`–`next-lg`: kropki + popover ·
<`next-md`: **agenda zawsze**, przełącznik znika, `?mode` zachowane. Szuflada `full`
poniżej `next-md`. Szczegóły: §17.

### Accessibility

`role="grid"`/`row`/`gridcell` + roving tabindex + pełna mapa klawiszy **skopiowana
z `CalendarPanel.vue`**; `aria-current="date"`; polite live-region z datą i liczbą
wystąpień; kafelki dostępne przez popover dnia (nie 41 stopów tabulacji); focus-trap
w szufladzie; `role="alert"` na komunikatach straty i awarii; kolor nigdy jedynym
sygnałem. Szczegóły: §18.

### Copy / Microcopy

§13 (trzy rodzaje straty, sześć wariantów), §14 (awaria ≠ pusto), §20 (klucze).
Twarde: **`null` ≠ `0`**; nazw źródeł i plakietek **nie tłumaczymy**; nie obiecujemy
przywracania usuniętego wydarzenia; nie zgadujemy kadencji serii.

### Tailwind / Design Tokens

Wyłącznie tokeny `next-*` (§16.1) — zero `bg-[#…]`, zero tokenów legacy `app.css`.
Dark mode przez podmianę tokenów, bez odwracania kolorów w komponentach (§16.2).
Mapa `color`→klasy w jednym module z whitelistą (Tailwind v4 nie wygeneruje klasy
złożonej dynamicznie).

### Frontend Handoff

Zaczynaj od `calendarZone.ts` + `calendarMeta.ts` + `types.ts` (czyste, testowalne,
niosą wszystkie pułapki), potem store, potem `MonthGrid` (a11y), potem kafelek,
potem agenda, na końcu szuflada. **Luka L1 zamknięta** (`CalendarInstantResolver`,
patrz §23 L1) i szuflada jest zbudowana — ten akapit zostaje jako historia sekwencji,
nie jako blokada.

### Consistency Risks

1. **Wymyślenie pola.** Kadencji („co 5 min”), nazwy podmiotu, końca terminu zadania,
   listy wydarzeń — nic z tego nie istnieje. Sprawdzaj §2.
2. **`count ?? 0`.** Zamienia „nie wiemy” w „nic nie brakuje”. Zakaz.
3. **Konwersja `start_date`.** `new Date('2026-08-09')` to instant UTC. Nigdy.
4. **`new Date()` jako „dziś”.** „Dziś” jest w strefie workspace'u.
5. **Zakodowana lista źródeł.** Zabija obietnicę R4.
6. **`is_owner` jako bramka akcji.** Bramką są `can_be_edited` / `can_be_deleted`.
7. **`PUT` jako łatka.** Wyzeruje `description`, `subject_*`.
8. **Re-sortowanie `data[]`.** Kolejność przychodzi gotowa i jest częścią kontraktu.
9. **Fork `CalendarPanel`.** Kopiujemy wzorzec a11y, nie plik.
10. **Kontrolka zakresu dat w FilterBarze.** Świadomie zakazana (§9.3).
11. **Przywrócenie kontrolki koloru na formularzu zdarzenia.** Usunięta świadomie (§2.2,
    §12.2); serwer odrzuca `color` 422-ką. Jeśli wróci potrzeba grupowania wizualnego, to
    kategoria zdarzenia, nie paleta — ADR-0051 D10.

---

## 22. Czego NIE ma w R3

| Nie ma | Dlaczego |
| --- | --- |
| Przeciągania / zmiany rozmiaru | D3 — 3 z 4 źródeł są nieprzesuwalne z definicji. |
| Widoku tygodnia i dnia | Backend zna okno i tryby; dwa widoki wystarczają. Okno 62 dni je uniesie później. |
| Rysowania rozpiętości przez wiele dni | Model wystąpienia to jedna kratka; `EventCalendarSource` filtruje po `starts_at` (§2.2 pkt 3). |
| Wielodniowego wydarzenia całodniowego | Kontrakt nie ma `end_date`; jedno wydarzenie = jeden dzień. Luka **L4**. |
| Cyklicznych wydarzeń | Świadomie poza zakresem modelu (`CalendarEvent`: „RECURRENCE IS OUT OF SCOPE"). |
| Kosza wydarzeń / przywracania | Wiersze są soft-delete, ale endpointu `restore` nie ma. |
| Wyboru „powiązanego elementu" przy wydarzeniu | Kalendarz nie rozwija aliasu morficznego, więc picker pokazywałby `task: 9f3e…`. Wartości są **przenoszone** przy edycji (§12.4). |
| Eksportu iCal / subskrypcji | Brak endpointu. |
| Powiadomień o wydarzeniu | `CalendarEvent`: „nothing ever executes because an event exists". |

---

## 23. Luki kontraktu

Zgłoszone, **nie** dopisane po cichu do specyfikacji.

---

### L1 — ZAMKNIĘTA: zapis godziny nie miał kontekstu strefy, odczyt miał

**Status: rozwiązana w tym rozdziale.** `App\Modules\Calendar\Services\CalendarInstantResolver`
jest teraz **jedynym** miejscem, w którym reguła strefy jest zapisana, i obie strony zapisu
przez nią przechodzą: `StoreCalendarEventRequest`/`UpdateCalendarEventRequest` (ten endpoint) i
krok workflow `create_event` (`CreateEventStep`), który był **trzecimi, cichymi drzwiami** do
`calendar_events` — czytał gołą godzinę przez `Carbon::parse()` na `config('app.timezone')`,
czyli inaczej niż API, o dwie godziny w przypadku Warszawy. Reguła obowiązująca dziś:

- wartość **bez strefy** → czytana w strefie **workspace'u** (ta sama, w której rysuje siatka);
- wartość z **jawną** strefą (`+02:00`, `Z`, pełny identyfikator) → **wygrywa**, strefa
  workspace'u jest wtedy ignorowana.

**Historia dla kontekstu (fakt sprzed naprawy).** `POST`/`PUT /calendar/events` przyjmowały
`starts_at` regułą `date` i konwertowały przez `$request->date('starts_at')` →
`Carbon::parse($v)` z **domyślną strefą aplikacji** (`config/app.php` = `'timezone' => 'UTC'`).
Dla workspace'u ze strefą `Europe/Warsaw` payload `"2026-08-09T14:30"` (natywny model
`DateTimePicker` z `dateCore`) zapisywał się jako `14:30 UTC` i rysował na siatce jako
**16:30** — bez błędu, bez ostrzeżenia. Ta klasa defektu jest teraz zamknięta po obu stronach.

**Obejście z §12.3 zostaje, i to świadomie.** UI nadal wysyła ISO z **jawnym offsetem**
(`zonedWallClockToInstant`) — nie dlatego, że to wciąż obowiązkowe (backend interpretuje
teraz poprawnie i gołą wartość), tylko dlatego, że jawny offset **zawsze wygrywa**, więc
podejście jest niezmiennie poprawne i nie ma powodu go upraszczać w tym rozdziale.

---

### L2 — ZAMKNIĘTA: gęsta seria nie niosła swojej kadencji

**Status: rozwiązana w tym rozdziale, wariantem (a) z rekomendacji.** Wystąpienie niesie
teraz opcjonalne `occurrence.cadence_label` — gotowa proza od źródła (`ScheduleCadenceLabel`,
`App\Modules\Workflows\Support`), obliczana raz na workflow, nie na wystąpienie. Niesiona
wyłącznie przez kadencję INTERWAŁOWĄ (`every_minutes`/`every_hours`); tryb `at` (lista stałych
godzin) niesie `null` celowo — uczciwe zdanie musiałoby uwzględnić też oś dnia/miesiąca, a to
inny silnik niż znacznik gęstości. Frontend renderuje `cadence_label`, gdy jest, i degraduje do
„Seria — pokazano {n}", gdy go nie ma (§7.2) — dokładnie zgodnie z rekomendacją, zero zmiany w
UI wymuszonej przez brak pola.

---

### L3 — `window_trimmed` gubi liczbę, gdy źródło samo się przycięło

**Fakt.** Źródła (`task`, `workflow_run`, `event`) zgłaszają `window_trimmed` **bez
żadnej liczby**; globalne przycięcie w `CalendarQueryService` zgłasza dokładną liczbę.
Scalanie `addOrUnknown()` sprawia, że nieznane **zatruwa** sumę: gdy zadziałały oba,
`omitted_occurrences` wraca jako `null`.

**Ocena.** To jest **poprawne i zamierzone** — lepsze niż raportowanie znanej części jako
całości. Zgłaszam jako lukę **projektową**, nie defekt: w praktyce najczęstszy przypadek
przekroczenia limitu pokaże wariant „bez liczby", więc copy **bez liczby musi być tak samo
dobre jak z liczbą** i nie może brzmieć jak degradacja. §13.1 to uwzględnia.

**Nic do zrobienia po stronie backendu.** Zapisane, żeby nikt nie „naprawił" tego
domyślnym zerem.

---

### L4 — Wydarzenie całodniowe jest zawsze jednodniowe

**Fakt.** Tabela ma `start_date`, nie ma `end_date`; `ends_at` istnieje tylko dla formy
godzinowej.

**Skutek.** „Urlop 10–20 sierpnia" jako jedno wydarzenie jest niereprezentowalny.
Użytkownik utworzy 11 wydarzeń albo jedno „z godziną", które siatka narysuje wyłącznie
w dniu startu (§2.2 pkt 3).

**Priorytet: niski** dla R3 (brief tego nie wymaga), ale to pierwsza rzecz, o którą
upomni się użytkownik zespołowego kalendarza. Wymagałoby kolumny **i** modelu rysowania
rozpiętości — czyli decyzji produktowej, nie łatki.

---

### L5 — Wskaźnik `subject` wydarzenia jest zapisywalny, ale nieodczytywalny

**Fakt.** `POST`/`PUT` przyjmują `subject_type`/`subject_id`; `CalendarEventResource`
zwraca je surowe; Kalendarz świadomie **nigdy** ich nie rozwija.

**Skutek.** UI nie ma jak pokazać *„To wydarzenie dotyczy: Zadanie «Nagranie odcinka»"* —
tylko alias i UUID. Dlatego R3 nie eksponuje tego pola (§22) i tylko je przenosi (§12.4).

**Rekomendacja:** żadna zmiana w Kalendarzu (jego niezależność jest cenniejsza).
Gdy pojawi się potrzeba etykiety, właściwym miejscem jest **osobny, ogólny endpoint
rozwiązywania podmiotów** (`POST /subjects/resolve` z listą par alias+id), z którego
skorzysta też pointer wiedzy — nie relacja `morphTo` w Kalendarzu.

---

### L6 — ZAMKNIĘTA: `unavailable_sources` nie mówiło, co się stało

**Status: rozwiązana w tym rozdziale — i wbrew własnej wcześniejszej ocenie tej luki.**
Poprzednia wersja tego wpisu uznawała listę gołych id za „wystarczającą, bo użytkownik i tak
nie zrobi nic poza ponowieniem" — założenie było błędne: ponowienie jest sensowne tylko dla
JEDNEGO z trzech powodów (`failed`), więc bez powodu przycisk „Spróbuj ponownie" musiał albo
pojawiać się zawsze (obiecując naprawę, której nie ma dla `not_constructed`/`malformed`), albo
nigdy (chowając jedyny przypadek, w którym coś by pomógł).

`meta.unavailable_sources` jest dziś listą `{ source, reason }` — `CalendarUnavailableReason`
(`failed | not_constructed | malformed`), zamknięty słownik po stronie backendu
(`docs/backend/calendar-api.md` → „The unavailable-source report"). UI branch'uje na `reason`:
przycisk retry renderowany wyłącznie, gdy istnieje choć jedno źródło `failed`; nieznany,
przyszły kod jest traktowany jako nieponawialny, nigdy jako pomijalny. Pełne zachowanie: §14.

---

### L7 — Deep-link do konkretnego przebiegu workflow jest niewykonalny

**Fakt.** `subject.type === 'workflow_run'` niesie tylko UUID przebiegu. Żadna trasa
`next`, jaka dziś istnieje, nie potrafi go rozwiązać samodzielnie:

- `/workflows?run=<id>` na `WorkflowsModuleLayout.vue` czyta `run` jako parametr modala
  **„uruchom teraz"** (`TargetPickerModal :workflow-id="runParam"`) i oczekuje id
  **workflow**, nie id **przebiegu** — podanie mu id przebiegu otworzyłoby ten modal
  przeciwko workflow'owi, który nie istnieje.
- Jedyny deep-link, który realnie otwiera SZCZEGÓŁ przebiegu, to `?run_detail=<id>` na
  `/workflows/<workflowId>/runs` — a on wymaga id **workflow** w samej ścieżce. Wystąpienie
  kalendarza niesie tylko id przebiegu (`subject.type: 'workflow_run'`), więc id workflow
  nie da się z niego wyprowadzić po stronie klienta; `GET /workflows/{id}/runs/{run}` jest
  także zagnieżdżony pod workflow, więc nie da się go rozwiązać zapytaniem.

**Skutek.** Kalendarz nie może zaoferować „Otwórz" prowadzącego do konkretnego przebiegu.
Najwięcej, co ten kontrakt unosi, to lista uruchomień (`/workflows/runs`), bez
przefiltrowania do jednego wiersza.

**Przyjęte rozwiązanie (zaimplementowane, `pages/calendar/calendarMeta.ts::subjectLink`).**
`workflow_run` mapuje na `/workflows/runs`, z `kind: 'list'` zamiast `'open'`;
`OccurrencePopover.vue` czyta `kind` i renderuje **inną** etykietę przycisku
(`calendar.occurrence.openList`, „Zobacz listę") zamiast `calendar.occurrence.open`
(„Otwórz") — nigdy tę samą etykietę dla dwóch różnych obietnic. **To nie przeoczenie —
nie przywracać etykiety „Otwórz" dla tego wiersza.** Przycisk, który mówi „Otwórz" i
ląduje na liście bez zaznaczenia, jest małym kłamstwem powtarzanym codziennie; to właśnie
ta zasada zmieniła etykietę.

**Rekomendacja dla backendu (nieblokująca).** Gdyby istniał odpowiednik
`GET /workflows/{id}/runs/{run}` NIE zagnieżdżony pod workflow (np. odpowiedź przebiegu
niosąca też `workflow_id`, albo trasa płaska `GET /workflow-runs/{run}`), klient mógłby
zbudować `?run_detail=` samodzielnie i przywrócić `kind: 'open'`. Nic pilnego — lista jest
uczciwa wobec tego, co potrafi, i jest tak oznaczona.

---

### Uwaga systemowa (NIE luka kontraktu): brak zewnętrznej kotwicy w `Popover`

Zgłoszona tutaj mimo że to nie jest brakujące pole API, bo zmieniła budowę tego ekranu, a
ktoś czytający tylko §7.1 / §11.2 / §19 mógłby nie zrozumieć, dlaczego pliki
`DayPopover.vue` i `OccurrencePopover.vue` faktycznie renderują `Modal`, nie `Popover`,
mimo własnej nazwy.

**Fakt.** `ui/overlay/Popover.vue` pozycjonuje się względem wyzwalacza, który **owija**
(`useAnchoredPosition(triggerRef, …)`, `triggerRef` na własnym `inline-flex` korzeniu) —
nie ma propa zewnętrznej kotwicy. Owinięcie komórki siatki (`role="gridcell"`) wstawiłoby
element spoza roli `gridcell` między `role="row"` a jego komórki — łamiąc ARIA w jedynym
miejscu tego ekranu, gdzie role są nośne (§18.1) — a `inline-flex div` wewnątrz
`grid-cols-7` złamałby też układ siatki.

**Rozwiązanie (zaimplementowane).** Zarówno arkusz dnia (`DayPopover.vue`, §7.1), jak i
podgląd wystąpienia (`OccurrencePopover.vue`, §11.2), są `Modal`, nie anchorowanym
`Popover`em: ten sam design system, ten sam focus-trap, ten sam `Esc`, ten sam
`useOverlayStack`, identyczne zachowanie z kliknięcia, klawiatury i dotyku. Nazwy plików
zostały takie, jak w inwentarzu komponentów (§19.3) — rozjazd między nazwą a
implementacją jest udokumentowany komentarzem na górze obu plików, nie milczący.

**Konsekwencja dla design systemu (do koordynacji z `ux-ui-agent`).** Jeśli `Popover`
kiedyś dostanie prop zewnętrznej kotwicy (`anchorRef`, niezależny od `triggerRef`), oba
komponenty kalendarza są kandydatami do powrotu na lżejszy, nie-modalny popover — ale to
zmiana we wspólnym `ui/overlay/Popover.vue`, poza zakresem modułu Kalendarza, i nie ma
dziś żadnego zgłoszonego powodu, żeby ją robić.
