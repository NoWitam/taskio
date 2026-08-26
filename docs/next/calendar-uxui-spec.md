# Kalendarz (R3) — specyfikacja UX/UI

> **Batch B5** rozdziału R3, **rozszerzony o B6** (wydarzenia cykliczne — §24).
> Dokument jest kontraktem dla `frontend-agent`.
>
> **§24 jest samodzielnym rozdziałem i tam mieszka wszystko, co dotyczy powtarzalności.**
> Sekcje 1–23 opisują siatkę i wydarzenie jednorazowe; miejsca, w których B6 zmienił
> kontrakt, są poprawione **w miejscu** i oznaczone odnośnikiem do §24, a nie zostawione
> jako nieprawda, do której frontend zaraz zajrzy.
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
> **§24 zbudowany i zielony** (frontend 3277 testów / 274 pliki; backend 3273/0/13).
> Dziewięć miejsc, w których zbudowany kod rozminął się z tym, co ta specyfikacja mówiła
> — w każdym **wygrał kod**, z uzasadnieniem — są zebrane i skrzyżowane z sekcją, której
> dotyczą, w [24.17 Rozjazdy specyfikacji z implementacją](#2417-rozjazdy-specyfikacji-z-implementacją).
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
24. [Wydarzenia cykliczne (B6)](#24-wydarzenia-cykliczne-b6)

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
| `cadence_label` | `string\|null` | Gotowa, przetłumaczona proza o tym, **jak często powtarza się PODMIOT** — „Co 5 min" (harmonogram), „Co tydzień: wt." (seria wydarzeń). **Zawsze obecne, często `null`.** Niepuste dla: interwałowej kadencji harmonogramu **oraz każdego wystąpienia serii wydarzeń** (§24.7). Klucz **niepusty ⇒ podmiot się powtarza**; odwrotnie **nie** — harmonogram w trybie stałych godzin powtarza się i niesie `null`. §7.2, §24.7. |
| `recurring` | `bool` | **Zawsze obecne.** `true`, gdy ten kwadrat jest policzony z reguły powtarzania — każde wystąpienie serii wydarzeń **oraz** każda projekcja harmonogramu, `false` dla terminu zadania, przebiegu i jednorazowego wydarzenia. To jest **pozytywne stwierdzenie faktu** — nie wolno go odzyskiwać z `cadence_label !== null` (ta implikacja jest wystarczająca, ale nie konieczna: patrz wiersz wyżej). §24.7 buduje na tym polu, nie na `cadence_label`. Zamknięta luka **L8**. |
| `occurrence_date` | `string\|null` (`Y-m-d`) | **Zawsze obecne.** Który dzień serii to jest, na zegarze **serii** (`recurrence_timezone`, nie `meta.timezone`) — dokładnie ten string, który wraca jako `occurrence_date` przy zapisie ze `scope`. Niepuste **wyłącznie** dla wystąpienia serii wydarzeń; `null` dla wydarzenia jednorazowego i dla każdej projekcji harmonogramu (nic tam nie adresuje jednego odpalenia). **Niezmiennik, na którym się rozgałęzia:** `editable && recurring` ⇒ `occurrence_date` jest niepuste — to jest jedyny moment, w którym otwiera się dialog zakresu (§24.4), a nie zwykła edycja pojedynczego wydarzenia. Zamknięta luka **L8**. |
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
| `unavailable_sources[]` | `{ source, reason }[]` | Źródła zapytane, które nie odpowiedziały — **z powodem**, nie sama lista id. `reason ∈ { failed, not_constructed, malformed, broken }` (`CalendarUnavailableReason`), zamknięty słownik. Zawsze obecne (pusta tablica ≠ brak klucza). §14. |

> **Uwaga o `label`.** Gdy źródło nie da się skonstruować, `CalendarSourceRegistry::labelFor()`
> zwraca `null`, a serwis wstawia **surowe id** jako etykietę. UI renderuje `label`
> dosłownie i nie próbuje go tłumaczyć ani upiększać.

### 2.2 Które źródło co produkuje (obserwowane w kodzie)

| Źródło (`id`) | Kształt | `color` | `badge` | `editable` | `dense` | `subject.type` |
| --- | --- | --- | --- | --- | --- | --- |
| `task` | `all_day` | z `TaskPriority::tone()` (pilność!) | status zadania | `false` | nigdy | `task` |
| `workflow_schedule` | z godziną, `ends_at: null` | zawsze `info` | `workflows.calendar.scheduled_badge` | `false` | **możliwe** | `workflow` |
| `workflow_run` | z godziną, `ends_at` = koniec **lub `null` gdy trwa** | ze stanu przebiegu | stan przebiegu | `false` | nigdy | `workflow_run` |
| `event` | **oba** kształty | stały `primary` (żadne wydarzenie nie ma własnego koloru — patrz niżej) | **zawsze `null`** | **per wiersz przez policy** | **możliwe** (tylko sufit odpowiedzi — §24.7) | `calendar_event` |

> **Zmiana B6.** Wiersz `event` opisywał wydarzenie jednorazowe. Od B6 to samo źródło rysuje
> także **każde wystąpienie serii**: `id` ma wtedy trzeci segment (`event:{uuid}:{Y-m-d}`),
> `cadence_label` jest **niepuste**, a `subject.id` **nadal** wskazuje wiersz wydarzenia — więc
> klik otwiera szufladę dokładnie tak samo, bez parsowania czegokolwiek z `id`. Pełny opis: §24.

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
| `recurrence` | `{ day, month, exclusions, until } \| null` | **B6.** `null` = wydarzenie jednorazowe. Gdy jest — to **dokładnie** blok, który przyjmuje zapis (round-trip), a wszystkie cztery klucze są zawsze obecne (`null`, gdy reguła nic o nich nie mówi). §24.2 |
| `recurrence_timezone` | `string\|null` | **B6, READ-ONLY.** Strefa, w której regułę **ostemplowano przy zapisie** — nie musi być dzisiejszą strefą workspace'u. **Nie odsyłać** (zapis odrzuca `recurrence.tz` 422-ką). To ona, nie `meta.timezone`, nazywa dzień wystąpienia. Zapis `scope=series` stempluje ją na nowo, bieżącą strefą workspace'u — czytać **z odpowiedzi zapisu**, nigdy z pamięci (§24.6.6, L13). §24.3 |
| `recurrence_label` | `string\|null` | **B6, READ-ONLY.** To samo przetłumaczone zdanie, które każde wystąpienie tej serii niesie jako `cadence_label` na siatce — opublikowane tu, bo szuflada nie zawsze dostała się do sprawy przez kliknięcie kwadratu (deep-link, seria bez wystąpienia w oknie). **Nie** `cadence_label` — ta nazwa jest zajęta przez pole wystąpienia; zobacz L9 w §24.15 po uzasadnienie. `null` razem z `recurrence`/`recurrence_timezone` dla wydarzenia jednorazowego; populowane razem dla powtarzającego się (lub gdy reguła jest zbyt złożona, żeby ułożyć zdanie — ten sam `null` dla obu przypadków). Zamknięta luka **L9**. |
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

**B6 dołożył do tej powierzchni trzy rzeczy i żadnej z nich nie ma w bloku wyżej:**
`recurrence` (reguła powtarzania), `scope` (`series` | `occurrence` | `following`) oraz
`occurrence_date`. Kompletny inwentarz — z każdą ścieżką błędu 422 — jest w **§24.2**;
nie powielam go tutaj, żeby nie powstały dwa opisy jednego kontraktu.
Dwie rzeczy warte zapamiętania już tutaj:
`scope`/`occurrence_date` są na `POST` **zabronione** (422), a `PUT` z zakresem innym niż
`series` potrafi zwrócić **inny wiersz niż ten z URL-a** (201 zamiast 200 — §24.2).

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
>
> **B6 dokłada do tej listy `recurrence`.** `PUT`, który go pominie, **kasuje regułę
> powtarzania** — a `PUT`, który odeśle regułę bez `exclusions.dates`, **wskrzesi
> wystąpienia usuwane pojedynczo**. Blok z `GET`-a wraca na drut dosłownie (§24.2).

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
(gotowa, przetłumaczona proza od źródła). Chip renderuje `cadence_label`, gdy jest,
i **degraduje** do *„Seria — pokazano 12"*, gdy go nie ma — copy **nigdy nie zgaduje**
okresu samodzielnie. Niedopuszczalne: wymyślanie *„co 5 min"* czy *„288 wystąpień"*
po stronie klienta, gdy `cadence_label` jest `null`.

> **Poprawka B6 — kontrakt tego pola się zmienił i poprzednie zdanie w tym miejscu było
> już nieprawdą.** `cadence_label` nie jest znacznikiem gęstości i nie ogranicza się do
> kadencji interwałowej harmonogramu. Dziś niosą je **dwa** źródła i z **dwóch** powodów:
> `workflow_schedule` — tylko dla trybów interwałowych (`every_minutes`/`every_hours`),
> `event` — dla **każdego** wystąpienia serii, niezależnie od gęstości („Co tydzień: wt.",
> „Co miesiąc: 3. wt.", „Co miesiąc, ostatniego dnia").
> Skutek dla tego paragrafu: **niepuste `cadence_label` ≠ „ta pozycja jest zagęszczona"**.
> To dwa różne fakty, mają dwa różne znaczniki i nie wolno ich zlać — pełna reguła: §24.7.

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
| `failed` | Źródło zapytano i **rzuciło** — chwilowa awaria, timeout. | **Tak — jedyny z czterech.** |
| `not_constructed` | Źródło nigdy nie powstało (konfiguracja, zepsuty boot). | Nie — identyczny wynik za chwilę. |
| `malformed` | Źródło odpowiedziało, ale nie kalendarzowym kształtem. | Nie — defekt kodu źródła, powtórzy się dokładnie. |
| `broken` | Źródło zapytano i rzuciło błędem bazy, który nazywa **schemat**, nie chwilę: brak tabeli, brak kolumny, brak uprawnienia (SQLSTATE klasy 42). Zwykła przyczyna — migracja, której nikt nie uruchomił. | **Nie — i to jest twierdzenie strukturalne, nie zgadywanie**, w przeciwieństwie do `failed`: następne identyczne zapytanie trafia na identyczny schemat. Ktoś musi zmigrować albo wdrożyć. Wydzielony z `failed`, bo brak tabeli renderował się jako „to zwykle chwilowe — spróbuj ponownie" — rada nie tylko bezużyteczna, ale wprost myląca (kieruje użytkownika na własne połączenie, zamiast na niedokończony install) |
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
| ~~Cyklicznych wydarzeń~~ | **Nieaktualne.** Wydarzenia cykliczne są w kontrakcie od B4/B5 (reguła na wierszu, seria rzutowana na siatkę, trzy zakresy operacji) i mają własny rozdział: **§24**. |
| Kosza wydarzeń / przywracania | Wiersze są soft-delete, ale endpointu `restore` nie ma. |
| Wyboru „powiązanego elementu" przy wydarzeniu | Kalendarz nie rozwija aliasu morficznego, więc picker pokazywałby `task: 9f3e…`. Wartości są **przenoszone** przy edycji (§12.4). |
| Eksportu iCal / subskrypcji | Brak endpointu. |
| Powiadomień o wydarzeniu | `CalendarEvent`: „nothing ever executes because an event exists". |

---

## 23. Luki kontraktu

Zgłoszone, **nie** dopisane po cichu do specyfikacji.

> **Luki batcha B6 (L8–L13) mieszkają w §24.15**, na końcu swojego rozdziału — razem
> z kontraktem, przeciwko któremu powstały. Tutaj zostają luki B5 (L1–L7), z ich
> statusami.

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

---

## 24. Wydarzenia cykliczne (B6)

> **Batch B6.** Powstał **po** ukończonym i zielonym backendzie (BE 3267/0/13) i
> **przeciwko** jego rzeczywistemu kontraktowi: `app/modules/Calendar/Http/Requests/*`,
> `Http/Resources/*`, `Sources/EventCalendarSource.php`, `Services/CalendarRecurrenceService.php`,
> `DTOs/CalendarRecurrence.php`, `Support/CalendarCadenceLabel.php`, `Enums/CalendarEventScope.php`,
> `app/Support/Recurrence/*`, `lang/{pl,en}/calendar.php`, `config/calendar.php`,
> `docs/decisions/ADR-0052-shared-recurrence-layer.md`.
>
> Każde pole opisane niżej ma pokrycie w kodzie. **Rzeczy, których kontrakt nie ma,
> są zgłoszone jako luki w §24.15 i nie zostały po cichu dopisane.**

### 24.0 Stan wyjściowy: cała powierzchnia zapisu jest dziś nieosiągalna z interfejsu

To jest jedyna rzecz, od której wolno zacząć, bo zmienia priorytety całego batcha.

Backend potrafi trzy rzeczy, których UI nie potrafi **wywołać**:

| Backend potrafi | Frontend dziś |
| --- | --- |
| edytować / usunąć **jedno wystąpienie** serii (`scope=occurrence`) | brak — nie ma czym nazwać wystąpienia |
| edytować / usunąć **to i wszystkie następne** (`scope=following`) | brak |
| edytować / usunąć **całą serię** (`scope=series`) | **jedyna dostępna ścieżka, i jest domyślna** |

Konkretnie, w kodzie: `CalendarView.vue::onSelectOccurrence()` robi
`setQuery({ event: occurrence.subject.id })` — czyli otwiera **wydarzenie**, a nie
**wystąpienie**; `EventDrawer.vue` wysyła `PUT` bez `scope`, co serwer czyta jako
`series`; `app/stores/calendar.ts` nie ma w ogóle pojęcia `scope` ani `occurrence_date`,
a `OccurrenceChip.vue` rysuje ołówek przy **każdym** wystąpieniu serii.

**Efekt netto: „edytuj ten wtorek" po cichu edytuje wszystkie wtorki.** Nic tego nie
zgłasza, bo z punktu widzenia kontraktu wszystko jest w porządku — klient poprosił o zapis
całego wydarzenia i dostał zapis całego wydarzenia.

Dlatego **kolejność prac w B6 jest odwrotna do intuicyjnej**: najpierw dialog zakresu
(§24.4), potem kontrolka powtarzalności (§24.5). Kontrolka bez dialogu to nowy sposób
tworzenia serii, których użytkownik nie umie potem punktowo poprawić; dialog bez kontrolki
naprawia rzecz, która już jest zepsuta dla serii utworzonych przez API i przez krok
`create_event`.

---

### 24.1 Doktryna B6 — pięć zdań, które muszą być słyszalne w interfejsie

1. **Użytkownik ma wiedzieć, w co klika, ZANIM kliknie.** Ołówek na kafelku serii nie może
   znaczyć czegoś innego niż ołówek na kafelku wydarzenia jednorazowego, jeżeli wygląda
   tak samo — więc kafelek serii **nazywa się serią** (§24.7), a wybór zakresu jest
   osobnym, jawnym krokiem (§24.4).
2. **Zmiana reguły przepisuje przeszłość.** Seria trzyma **jedną** regułę przez całe życie
   (`calendar_events.recurrence`), więc przeniesienie cotygodniowego spotkania na środy
   zamienia w środy także zeszłoroczne poniedziałki — w siatce, w każdym zrzucie ekranu,
   bez śladu, że kiedykolwiek były poniedziałkami. Podział (`following`) istnieje **właśnie
   po to**, i użytkownik ma rozumieć różnicę **w momencie wyboru**, a nie z dokumentacji.
3. **Dzień identyfikuje wystąpienie.** Seria kalendarza ma dokładnie **jedną** godzinę,
   więc jedno wystąpienie na dzień, więc `Y-m-d` wystarcza za identyfikator — to ta sama
   reguła, na której stoi `id` wystąpienia i `occurrence_date` w zapisie.
4. **Dzień wystąpienia liczy się na zegarze SERII, nie workspace'u.** `recurrence_timezone`
   to strefa **ostemplowana przy zapisie**; `meta.timezone` to strefa, w której rysuje
   siatka. Zwykle są takie same. Kiedy nie są — obowiązuje pierwsza (§24.3).
5. **Podzbiór jest wąski celowo.** Kontrolka mówi językiem osoby planującej spotkanie, nie
   autora automatyzacji. Czego nie oferuje i dlaczego — §24.5.4, wprost, żeby nikt tego nie
   „uzupełnił" jako przeoczenia.

---

### 24.2 Kontrakt zapisu — pełny inwentarz

Wszystko odczytane z `StoreCalendarEventRequest`, `UpdateCalendarEventRequest`,
`DestroyCalendarEventRequest`, `CalendarEventController`, `CalendarRecurrence`,
`ScheduleLimits`, `config/calendar.php`.

#### 24.2.1 Blok `recurrence` (POST i PUT)

Nieobecny albo pusty = **wydarzenie dzieje się raz**. To jest kształt każdego payloadu
sprzed B4 i on nie zmienił znaczenia.

| Klucz | Typ / zakres | Kiedy |
| --- | --- | --- |
| `recurrence.day.mode` | `every_day` \| `weekdays` \| `month_days` \| `special` | oś dnia; brak osi = codziennie |
| `recurrence.day.weekdays[]` | `int 0..6`, distinct, 1..7 pozycji (**0 = niedziela**) | wtw `mode=weekdays` |
| `recurrence.day.days[]` | `int 1..31`, distinct, 1..31 pozycji | wtw `mode=month_days` |
| `recurrence.day.special` | `last_day` \| `nth_weekday` \| `last_weekday` | wtw `mode=special` |
| `recurrence.day.ordinal` | `int 1..5` | wtw `special=nth_weekday` |
| `recurrence.day.weekday` | `int 0..6` | wtw `special ∈ {nth_weekday, last_weekday}` |
| `recurrence.month.mode` | `every_month` \| `months` | oś miesiąca; brak osi = co miesiąc |
| `recurrence.month.months[]` | `int 1..12`, distinct, 1..12 pozycji | wtw `mode=months` |
| `recurrence.exclusions.dates[]` | `Y-m-d`, distinct, **maks. 50** (`EXCLUSIONS_DATES_MAX`) | dni pominięte |
| `recurrence.until` | `Y-m-d`, `>=` dzień kotwicy | koniec serii — **albo to** |
| `recurrence.count` | `int 1..366` (`calendar.recurrence_count_max`) | **albo to**, nigdy oba |

**Klucze ZABRONIONE (422, nie ciche pominięcie):** `recurrence.time`, `recurrence.tz`,
`recurrence.exclusions.months`, `recurrence.exclusions.weekdays` — oraz **każdy klucz
spoza tabeli** wewnątrz `recurrence` / `recurrence.exclusions` (błąd na ścieżce
`recurrence.<klucz>`, komunikat nazywa pole). Godzina serii **jest** godziną wydarzenia,
strefa **jest** strefą workspace'u w chwili zapisu; obu serwer nie przyjmuje właśnie
dlatego, że kto je wysyła, sądzi, że coś ustawia.

**Trzy reguły, których nie widać w tabeli, a których złamanie kończy się 422:**

| Reguła | Ścieżka błędu | Kiedy realnie wystąpi w UI |
| --- | --- | --- |
| **Początek wydarzenia musi być pierwszym wystąpieniem reguły** | `start_date` / `starts_at` (`anchor_not_an_occurrence`) | **Nigdy** dla zwykłej ścieżki (wybór sub-trybu / zmiana daty), jeżeli oś jest seedowana z kotwicy i re-seedowana przy jej zmianie (§24.5.2 — AS-BUILT: profil osi zastąpił presety, §24.17 poz. 10); **oraz nigdy** dla reguły NIETKNIĘTEJ w tej sesji, niezależnie od dryfu strefy (§24.5.6) |
| **Seria musi mieć choć jedno wystąpienie** (kadencja MINUS pominięte dni, w granicach `until`) | `recurrence.until` albo `recurrence.exclusions.dates` (`series_has_no_occurrences`) | Skrócenie `until` serii, z której powycinano dni |
| **Wydarzenie cykliczne z godziną zaczyna się o pełnej minucie** | `starts_at` (`whole_minute`) | `TimePicker` daje `HH:mm`, więc nie |

#### 24.2.2 `scope` i `occurrence_date` (tylko PUT i DELETE)

| Klucz | Wartości | Domyślnie |
| --- | --- | --- |
| `scope` | `series` \| `occurrence` \| `following` | **brak = `series`** — bajt w bajt zachowanie sprzed B4 |
| `occurrence_date` | `Y-m-d` **na zegarze serii** | wymagane wtw `scope ≠ series`; przy `series` **zabronione** |

Na `POST` oba są `prohibited` (422 `scope_on_create`).
`DELETE` czyta je przez `input()`, więc mogą jechać w **query stringu** albo w ciele —
zalecamy query (`api.delete(url, { params })`), bo nie każdy klient wysyła ciało w DELETE.

Co robi każdy zakres — **to jest treść dialogu z §24.4, nie ozdoba**:

| `scope` | `PUT` robi | `DELETE` robi | Co z przeszłością |
| --- | --- | --- | --- |
| `series` | przepisuje **cały wiersz**, razem z regułą | soft-delete wiersza | **przepisuje ją** — stara reguła znika bez śladu |
| `occurrence` | dodaje dzień do `exclusions` **i** tworzy **nowe, jednorazowe** wydarzenie z payloadu (**odczepienie**, nie nadpisanie) | dodaje dzień do `exclusions`; wiersz zostaje | nie rusza |
| `following` | zamyka starą serię **dzień wcześniej** i tworzy **nowe** wydarzenie od tego dnia | zamyka starą serię dzień wcześniej | **zachowuje ją** |

**Gdy podział nie zostawiłby nic za sobą** (typowo: wskazane wystąpienie jest pierwsze),
`following` **zwija się do `series`**: `PUT` edytuje wiersz w miejscu, `DELETE` kasuje go
w całości. Serwer sprawdza to **projekcją** (`splitLeavesSomethingBehind`), a nie
porównaniem dat — patrz §24.4.3, bo z tego wynika, czego frontendowi **nie wolno**
twierdzić.

#### 24.2.3 Kod odpowiedzi mówi, który wiersz wrócił

| Kod | Znaczy |
| --- | --- |
| `200` | wrócił **ten** event, którego id jest w URL-u (każdy `scope=series` i `following` na pierwszym wystąpieniu) |
| `201` | wrócił **NOWY** wiersz z nowym `id` — odczepione wystąpienie albo druga połowa podziału |

**Klient nie widzi statusu**: `app/lib/api.ts` zwraca `response.data`. **Nie rozszerzać
wspólnego klienta dla jednego przypadku** — `201` jest równoważne
„`response.data.id` ≠ id, o które prosiliśmy", a `id` jest w ciele. Store porównuje id i na
tej podstawie wie, że trzymany identyfikator **przestał być tym, który edytuje się dalej**.

#### 24.2.4 Wszystkie ścieżki 422, jakie może zwrócić powierzchnia reguły

Komunikaty przychodzą **przetłumaczone** (`lang/{pl,en}/calendar.php` →
`calendar.validation.recurrence.*`). UI renderuje treść z serwera, **nigdy własną**.

| Ścieżka | Wywołuje | Gdzie UI ma to pokazać |
| --- | --- | --- |
| `recurrence` | `unsupported`, `occurrence_has_no_rule` | pod kontrolką powtarzania |
| `recurrence.day.mode` / `.day.special` / `.month.mode` | `*_not_supported` | pod kontrolką powtarzania |
| `recurrence.day.*` / `recurrence.month.*` | `mode_required`, `list_required`, `field_not_allowed`, `special_*` | pod kontrolką powtarzania |
| `recurrence.until` | `end_before_start`, `series_has_no_occurrences` | pod kontrolką końca |
| `recurrence.count` | `end_is_one_thing`, `count_unreachable` | pod kontrolką końca |
| `recurrence.exclusions.dates` | `series_has_no_occurrences` | pod kontrolką końca (**tam jest lek**) |
| `recurrence.time` / `.tz` / `.exclusions.months` / `.exclusions.weekdays` / `recurrence.<obcy klucz>` | `prohibited`, `field_not_allowed` | **Alert `danger` na górze szuflady** — to defekt klienta, nie stan użytkownika |
| `start_date` / `starts_at` | `anchor_not_an_occurrence`, `whole_minute`, `split_starts_before_the_split` | pod polem początku |
| `scope` | `event_does_not_repeat` | Alert `danger` + akcja „Wybierz zakres ponownie" |
| `occurrence_date` | `occurrence_date_required`, `occurrence_date_without_scope`, `not_an_occurrence`, `exclusions_full` | w **dialogu zakresu**, przy wybranej opcji (§24.4.5) |

**Wymóg implementacyjny:** `EventDrawer.vue::FORM_FIELDS` (dziś zamknięty zbiór sześciu
nazw) musi rozpoznawać **prefiks** `recurrence.` jako „mam dla tego kontrolkę". Inaczej
każdy błąd reguły trafi do gałęzi *homeless* i wyląduje w Alercie na górze — czyli komunikat
o polu, które jest na ekranie, zostanie pokazany **nie przy nim**.

---

### 24.3 Jak frontend nazywa wystąpienie

**Status: L8 zamknięta (§24.15) — ten paragraf opisuje wyprowadzenie, które przestało być
obowiązkowe.** `CalendarOccurrenceResource` niesie dziś `occurrence_date` bezpośrednio;
klik w kafelek daje datę wystąpienia **bez** liczenia czegokolwiek i **bez** czekania na
`GET` wydarzenia. Formuła niżej zostaje w tej specyfikacji z dwóch powodów: wyjaśnia, **co
znaczy** `occurrence_date` (przydatne przy czytaniu `id`, przy debugowaniu, i dla
`recurrence_timezone`, którego rola się nie zmieniła), i pokazuje, że pole na drucie **nie
jest zgadywane** — jest przepisaniem tego samego wyliczenia, które serwer już wykonał.
**Frontend nie ma powodu, żeby ją implementować jako osobny moduł** (`occurrenceDate.ts`
z §24.12.3 jest przez to zbędny — patrz tam) — jedyne miejsce, w którym B6 wymagało czegoś
policzyć po stronie klienta, przestało istnieć.

```
// Historyczne / poglądowe — TO SAMO, co serwer już zwraca jako occurrence.occurrence_date:
occurrence_date =
  all_day === true    →  occurrence.start_date                          // bez konwersji, nigdy
  all_day === false   →  instantToZonedParts(occurrence.starts_at,
                                             event.recurrence_timezone).day
```

**Dlaczego `recurrence_timezone`, a nie `meta.timezone` — to pytanie zostaje aktualne, choć
wyprowadzenie już nie jest wymagane.** Serwer bije `id` wystąpienia i przyjmuje
`occurrence_date` na zegarze, którym **ostemplował regułę przy zapisie**
(`CalendarRecurrence::timezone()`), a nie na dzisiejszym zegarze workspace'u. Zwykle to ta
sama strefa. Kiedy workspace zmienił strefę po utworzeniu serii — nie jest, i dzień potrafi
różnić się o jeden (patrz też **L13**: zapis całej serii sam potrafi przestemplować tę
strefę). Wtedy `meta.timezone` dałoby datę, której serwer nie uzna: 422
`not_an_occurrence` („Ta seria nie ma wystąpienia w tym dniu"). To dlatego szuflada wciąż
czyta `recurrence_timezone` w §24.6.5 — nie po to, żeby coś policzyć, ale żeby pokazać
użytkownikowi, na jakim zegarze liczy się dzień, gdy różni się od siatki.

**Konsekwencja przepływu — zawężona.** Szuflada **wciąż** musi wykonać `GET` wydarzenia
przed jakimkolwiek zapisem — ale dziś z dwóch powodów, nie trzech: potrzebuje
`can_be_edited` i reguły `recurrence` do odesłania (whole-event `PUT`). Trzeci, dawny powód
— że bez `GET`-a nie da się policzyć `occurrence_date` — **odpadł**: ta wartość jest już na
kafelku, zanim szuflada w ogóle się otworzy. Kolejność „klik → szuflada → GET → dialog
zakresu" (§24.4.1) zostaje niezmieniona, bo pozostałe dwa powody wciąż ją wymagają — ale
dialog zakresu może dziś pokazać `occurrence_date` w nagłówku kontekstu **natychmiast**, bez
czekania na `GET`, jeśli okaże się to potrzebne dla odczuwalnej responsywności.

#### 24.3.1 Stan w URL

> **AS-BUILT — patrz §24.17 poz. 1.** Ten paragraf opisywał `on`/`at` jako **dwa
> wykluczające się** klucze-identyfikatory (jeden dla serii całodniowej, drugi dla serii
> z godziną) z regułą rozstrzygania konfliktu, gdy oba przyjdą naraz. Zbudowany kod robi
> coś prostszego: **jeden identyfikator, zawsze ten sam klucz**, plus drugi klucz
> pomocniczy, który **towarzyszy** mu, a nie z nim konkuruje. Tabela i reguły odporności
> niżej opisują już zbudowany kształt.

Dokładane do konwencji z §3.3, bez zmiany istniejących kluczy:

| Param | Wartość | Znaczenie |
| --- | --- | --- |
| `on` | `Y-m-d` | **Identyfikator wystąpienia — zawsze `occurrence.occurrence_date`**, dla serii całodniowej i z godziną jednakowo. Przepisany z kafelka, nigdy nie liczony (L8, §24.15) |
| `at` | ISO-8601 UTC | **Pomocniczy, tylko dla serii z godziną** — `occurrence.starts_at`, towarzyszy `on`. Zasiewa dokładną chwilę formularza pod zakresem, który zaczyna nową serię (`occurrence`/`following`, §24.6.1); sam niczego nie identyfikuje i nieobecny dla serii całodniowej |
| `scope` | `occurrence` \| `following` | wybrany zakres edycji (razem z `edit=1`); `series` **nie jest** zapisywany, bo jest domyślny |

**Jeden identyfikator, nie dwa.** Skoro `occurrence_date` przychodzi z serwera gotowe (L8)
i jest tym samym `Y-m-d` dla obu kształtów wystąpienia, nie ma już dwóch spornych sposobów
nazwania tego samego dnia — `on` jest identyfikatorem zawsze, niezależnie od `all_day`.
`at` nie jest **drugim** identyfikatorem w innym kształcie: to dodatkowa chwila, którą
`onSelectOccurrence()` dokłada **obok** `on` dla wystąpienia z godziną, bo scalony zakres
(`occurrence`/`following`) potrzebuje czegoś więcej niż dnia, żeby zasiać pole początku
z godziną kliknięcia, a nie z kotwicy serii (§24.6.1). Oba klucze normalnie **współistnieją**
na tym samym URL-u wystąpienia z godziną — to nie jest stan błędu.

**Reguły odporności (obowiązkowe):**

- `scope` wymagający wystąpienia bez `on` → szuflada spada do `series`, z widoczną notką
  (§24.6.4). `at` bez `on` nie powstaje z tego ekranu, więc nie ma osobnej reguły dla tego
  przypadku;
- `on`/`at` przy wydarzeniu, które **nie** jest serią → ignorowane bez notki (to nie błąd
  użytkownika, tylko nieaktualny link — wydarzenie mogło przestać się powtarzać).

Zmiana zakresu → `router.replace` (nie zaśmieca historii). Otwarcie szuflady → `push`, jak
dziś.

---

### 24.4 Dialog zakresu

Jeden komponent, dwa tryby: **edycja** i **usuwanie**. `Modal` (nie `Popover` — §23,
uwaga systemowa), `size="sm"`, `RadioGroup` + `Radio` (`description` niesie zdanie
o skutku), stopka z dwoma `Button`ami.

**Nie pokazuje się w ogóle dla wydarzenia jednorazowego** (`recurrence === null`). Edycja
i usuwanie takiego wydarzenia zostają **bajt w bajt** takie jak dziś — to samo, co gwarantuje
backend brakiem `scope` w payloadzie.

#### 24.4.1 Kiedy się otwiera

| Wyzwalacz | Warunek |
| --- | --- |
| „Edytuj" w stopce szuflady (tryb podglądu) | `recurrence !== null` |
| „Usuń" w stopce szuflady | `recurrence !== null` |

**Nigdy z kafelka.** Kliknięcie kafelka otwiera szufladę w trybie podglądu — użytkownik
najpierw widzi, o czym mowa, potem decyduje o zakresie. Dialog wyskakujący wprost z siatki
pytałby o zakres zmian, których użytkownik jeszcze nie zna.

#### 24.4.2 Edycja — trzy wyjścia

Tytuł: **„Co chcesz edytować?"**
Nagłówek kontekstu (nie opcja): *„Wybrane wystąpienie: {fullDateLabel(occurrence_date)}"*.

| Opcja | Etykieta | Zdanie o skutku (`description`) |
| --- | --- | --- |
| `occurrence` *(domyślna)* | **Tylko to wystąpienie** | „Ten dzień wyjdzie z serii i stanie się osobnym wydarzeniem. Reszta serii zostaje bez zmian." |
| `following` | **To i wszystkie następne** | „Wcześniejsze wystąpienia zostaną takie, jakie były. Od tego dnia powstanie nowa seria." |
| `series` | **Całą serię** | „Wszystkie wystąpienia — także te, które już się odbyły. Zmiana reguły przepisze historię: przeniesienie spotkania na środy zamieni w środy również zeszłoroczne poniedziałki." |

Przycisk potwierdzenia **nazywa wybór**: „Edytuj to wystąpienie" / „Edytuj od {data}" /
„Edytuj całą serię". Drugi: „Anuluj".

**Domyślnie zaznaczony jest najwęższy zakres** — i to jest decyzja, nie wygoda. Odruchowy
Enter ma zrobić **najmniejszą** możliwą szkodę, a etykieta przycisku i tak przez cały czas
mówi, co się stanie; brak wartości domyślnej kosztowałby dodatkowy klik w najczęstszym
przypadku i nie kupił niczego, czego nie kupuje dynamiczna etykieta.

#### 24.4.3 Wystąpienie pierwsze — notka, nie ukrywanie opcji

Gdy `occurrence_date === dzień kotwicy` (`start_date`, a dla serii z godziną
`instantToZonedParts(starts_at, recurrence_timezone).day`), opcja `following` dostaje
**dodatkowe zdanie**: *„To pierwsze wystąpienie serii, więc ta opcja obejmie całą serię."*

**Czego frontendowi nie wolno:** twierdzić rzeczy odwrotnej. `occurrence_date > kotwica`
**nie dowodzi**, że coś zostanie za podziałem — seria, z której wycięto wszystkie
wcześniejsze dni, wciąż ma kotwicę przed podziałem, a serwer i tak zwinie operację do całej
serii. Serwer odpowiada na to **projekcją**, nie porównaniem dat, i frontend tej projekcji
nie ma. Dlatego zdanie o `following` jest napisane tak, żeby było prawdziwe w **obu**
przypadkach: „wcześniejsze wystąpienia zostaną takie, jakie były" jest prawdą także wtedy,
gdy zbiór wcześniejszych wystąpień jest pusty.

#### 24.4.4 Usuwanie — trzy wyjścia, inny ciężar

Tytuł: **„Co usunąć?"**, `variant="danger"`.

| Opcja | Etykieta | Zdanie o skutku |
| --- | --- | --- |
| `occurrence` *(domyślna)* | **Tylko to wystąpienie** | „{data} zniknie z serii. Pozostałe wystąpienia zostają." |
| `following` | **To i wszystkie następne** | „Seria skończy się dzień wcześniej. Wcześniejsze wystąpienia zostają." |
| `series` | **Całą serię** | „Wydarzenie zniknie z kalendarza razem z całą historią. **W interfejsie nie da się tego cofnąć.**" |

Przycisk: „Usuń to wystąpienie" / „Usuń wystąpienia od {data}" / „Usuń całą serię",
`variant="danger"`.

**Dwie różnice wobec dialogu edycji, obie wynikają z kontraktu:**

1. **To jest cała operacja**, nie brama do formularza. Po potwierdzeniu leci `DELETE`, więc
   dialog ma **stan ładowania** (`Button loading`) i **miejsce na błąd serwera** (§24.4.5).
   Dialog edycji tylko przełącza tryb i nie ma czym się wywrócić.
2. **Copy nie obiecuje przywracania w żadnej opcji.** Wiersz jest soft-delete, ale endpointu
   `restore` nie ma, a usunięte pojedyncze wystąpienie to wpis w `exclusions`, którego ten
   batch nie umie cofnąć (§24.14). Zdanie o nieodwracalności pada **tylko** przy `series`,
   gdzie strata jest największa — dopisanie go do wszystkich trzech zamieniłoby ostrzeżenie
   w szum.

#### 24.4.5 Błąd serwera wewnątrz dialogu usuwania

Realny i jedyny częsty: **`occurrence_date` → `exclusions_full`** — „Ta seria ma już
maksymalną liczbę pominiętych dni (50). Podziel serię zamiast pomijać kolejne wystąpienia."

| Wymóg | Powód |
| --- | --- |
| Komunikat renderuje się **w dialogu, pod wybraną opcją**, treścią z serwera | Odsyła do **innej opcji tego samego dialogu**; toast wyrzuciłby lek poza zasięg |
| Pozostałe dwie opcje **zostają aktywne** | Serwer właśnie powiedział, co zrobić zamiast — droga ma być o jeden klik |
| Dialog **nie zamyka się** po błędzie | Zamknięcie kazałoby przejść całą ścieżkę od nowa |
| Wybrana opcja **zostaje zaznaczona** | Zresetowanie wyboru wygląda jak „nic się nie stało" |

#### 24.4.6 Dostępność dialogu

| Element | Wymóg |
| --- | --- |
| `Modal` nad `Drawer` | Stos przez `useOverlayStack`; `Esc` zamyka **tylko wierzchni** — to już zachowanie `Modal.vue`, nie wolno go obchodzić |
| Focus po otwarciu | Pierwszy `Radio` (zaznaczony), nie przycisk potwierdzenia |
| Powrót fokusu | Na przycisk, który dialog otworzył („Edytuj" / „Usuń" w stopce szuflady) |
| Grupa opcji | `RadioGroup` daje strzałki + roving tabindex; `description` każdej opcji jest podpięty przez `aria-describedby` (robi to `Radio.vue`) |
| Nagłówek kontekstu | Prawdziwy tekst nad grupą, **nie** `title=` — data wystąpienia to nie podpowiedź |
| Etykieta przycisku | Musi zawierać nazwę wybranej opcji; sterowanie głosem musi mieć co powiedzieć |

---

### 24.5 Kontrolka powtarzalności

> **AS-BUILT (2026-08-26, commit `62a73e4`) — §24.5 świadomie odmówiło reużycia komponentu z
> edytora przepływu; owner tę decyzję ODWRÓCIŁ po obejrzeniu modułu na żywo. Pełne uzasadnienie
> i rejestr rozbieżności: §24.17 poz. 10.** Krótko: obawa, którą §24.5.4 zapisało jako powód
> odmowy ("słownictwo automatyzacji w oknie o spotkaniu"), była **słuszna** — i pozostaje
> słuszna. Nie zniknęła; **odpowiedzią okazał się PROFIL, nie odmowa reużycia**. Kontrolka
> wydarzenia i edytor harmonogramu przepływu są dziś **tym samym komponentem**
> (`ui/recurrence/RecurrenceAxisEditor.vue`), różnią je wyłącznie dane, jakie ten komponent
> przyjmuje jako `profile` (`ui/recurrence/recurrenceAxes.ts`): kalendarz dostaje profil, który
> **ukrywa** całą oś czasu (serwer sam ją autoryzuje — `recurrence.time` jest `prohibited`),
> tryby modulo („co N dni"/„co N miesięcy" — siatka resetowana co miesiąc i co rok), „ostatni
> dzień roboczy" i — bo to żyje w Workflows, nie w tym komponencie — asystenta AI oraz pasek
> podglądu najbliższych odpaleń. Ten podrozdział opisuje **aktualny** stan; poniższe tabele
> zastępują opis presetowej kontrolki, którą B6 zbudowało i którą ten refaktor usunął
> (`pages/calendar/recurrencePresets.ts` — skasowany plik).
>
> Poniższy opis jest celowo **zwięzły**: mechanika (seedowanie z kotwicy, walidacja,
> mapowanie na drut) jest wyczerpująco udokumentowana w kodzie —
> `resources/js/next/ui/recurrence/recurrenceAxes.ts`,
> `resources/js/next/pages/calendar/calendarRecurrence.ts` i
> `resources/js/next/pages/calendar/RecurrenceField.vue` — i ta dokumentacja **linkuje** do
> nich zamiast je powielać.

Jedna kontrolka w formularzu wydarzenia, poniżej pola początku/końca, powyżej opisu.
`FormField label="Powtarzanie"` + `Switch` „Powtarza się" + (gdy włączony) `fieldset` z
`RecurrenceAxisEditor` na `CALENDAR_RECURRENCE_PROFILE` + warunkowa kontrolka końca
(niezmieniona, §24.5.3).

#### 24.5.1 Słownictwo — dziś: profil osi, nie lista presetów

**AS-BUILT.** Zamiast listy gotowych presetów o pełnych, odmienionych etykietach ("W każdy
wtorek", "Co miesiąc: 4. wtorek"…), kontrolka renderuje `RecurrenceAxisEditor` w DWÓCH
zakładkach — **Dzień** i **Miesiąc** (bez zakładki Czas — profil kalendarza jej nie
wymienia). Każda zakładka to `RecurrenceOptionCards`: radiogrupa kart, gdzie ZAZNACZONA
karta rozwija się o swoje pola wplecione w zdanie (`{n}` = `NumberInput`, "od–do" = wspólne
`RecurrenceWindowField`). Tytuły kart są **ogólnymi** kluczami i18n
(`recurrenceEditor.day.mode.*` / `recurrenceEditor.month.mode.*`, np. "On weekdays", "On days
of the month") — kontrolka **nie składa już żadnego zdania o kadencji**. Jedyne zdanie o
**zapisanej** regule, jakie użytkownik kiedykolwiek czyta, pochodzi z serwera
(`cadence_label` / `recurrence_label`) — fakt 4 nagłówka `RecurrenceField.vue`; klient nie
komponuje prozy kadencji w żadnym miejscu.

Mapowanie starego słownictwa presetów na dzisiejszy mechanizm — dla ciągłości z resztą tego
dokumentu:

| Dawny preset (usunięty) | Dziś |
| --- | --- |
| — (brak) | `Switch` „Powtarza się" wyłączony — nieobecny klucz `recurrence`, bez zmian |
| codziennie | Zakładka Dzień → karta „Every day" (`day: { mode: 'every_day' }`, bez zmian kształtu) |
| tygodniowo | Zakładka Dzień → karta „On weekdays", chip = dzień tygodnia kotwicy — **dziś można zaznaczyć więcej niż jeden chip** (AS-BUILT, §24.17 poz. 10) |
| miesięcznie, dnia N | Zakładka Dzień → karta „On days of the month", chip = dzień miesiąca kotwicy — **dziś można zaznaczyć więcej niż jeden dzień** |
| miesięcznie, N-ty dzień tygodnia | Zakładka Dzień → karta „A specific weekday": `Select` **ordynału** (1.–5. + jawna pozycja „ostatni") + `Select` dnia tygodnia — jawnie edytowalne, nie tylko wyprowadzone |
| miesięcznie, ostatni dzień | Zakładka Dzień → karta „Last day of the month" (`special: 'last_day'`) — **wciąż nazywa regułę, nie ją wyprowadza z kotwicy** (patrz §24.5.2, ostatni akapit) |
| rocznie | Zakładka Dzień „On days of the month" **razem z** zakładką Miesiąc „In selected months" — dwa niezależne wybory osi, nie jeden preset |
| ostatni {dzień tygodnia} (piąty) | Karta „A specific weekday" seeduje się na `last_weekday`, gdy kotwica jest piątym takim dniem w miesiącu — dokładnie jak dawniej — ale teraz **edytowalna** z powrotem na jawne „5." (z tą samą notką ostrzegawczą) |

Trzy z warunkowych zachowań dawnego §24.5.1 przeżyły w nowej postaci:

| Warunek | Zachowanie dziś |
| --- | --- |
| ordynał piątego dnia tygodnia w miesiącu | Karta „A specific weekday" seeduje się na `last_weekday` (`seedDay`, `ordinal === 5`) — to samo automatyczne podstawienie, teraz odwracalne ręcznie na jawne „5." z `recurrenceEditor.day.fifthWeekdayNote` |
| dzień miesiąca ≥ 29 wybrany na karcie „On days of the month" | `calendar.recurrence.shortMonthsNote` pod kontrolką, nie zmieniło się |
| kotwica nie jest ostatnim dniem swojego miesiąca, a wybrano „Last day of the month" | **Inaczej niż dawniej.** Karta jest zawsze dostępna do wyboru (nie ma już warunkowego chowania jednego wiersza listy) — konsekwencje przeniosły się do §24.5.2 |

Prawdziwe klucze i18n: §24.13. Kart/zakładek edytora (`recurrenceEditor.*`) ta kontrolka
**nie zawiera** we własnym namespace — patrz §24.13, akapit o dwóch namespace'ach.

#### 24.5.2 Kotwica seeduje wybór sub-trybu — i przy zmianie daty RE-SEEDUJE, ale tylko to, czego nie ruszył człowiek

**AS-BUILT — mechanizm zastąpiony, skutek zachowany.** Zamiast przeliczać "który gotowy
preset pasuje do tej daty" (dawny algorytm poniżej — usunięty razem z
`recurrencePresets.ts`), każdy sub-tryb ma teraz własną funkcję seedu
(`ui/recurrence/recurrenceAxes.ts` — `seedDay(mode, anchorDay)` / `seedMonth(mode,
anchorDay)`), wywoływaną w DWÓCH momentach: gdy użytkownik dopiero WYBIERA ten sub-tryb (kartę),
i — dla RecurrenceField.vue, przez `watch(() => props.anchorDay, …)` — gdy data początku się
ZMIENIA.

**Re-seed przy zmianie daty jest WARUNKOWY, nie bezwarunkowy — to jest krawędź, której dawny
algorytm nie miał, bo nie musiał: gdy jedynym sposobem uzyskania reguły był preset, każda
reguła BYŁA tym, co preset by wyprowadził.** Dziś oś edytuje się swobodnie (kilka dni
tygodnia naraz, kilka dni miesiąca naraz…), więc re-seed musi odróżnić "to, co ten sub-tryb
sam by podstawił" od "to, co użytkownik świadomie ułożył". Test jest strukturalny: `RecurrenceField.vue`
re-seeduje daną oś **tylko** gdy jej bieżąca wartość równa się temu, co ten sam sub-tryb
wyprodukowałby dla POPRZEDNIEJ kotwicy — czyli tylko wtedy, gdy nikt jej ręcznie nie
zmienił od czasu ostatniego seedu. Reguła, którą ktoś świadomie ułożył (np. "poniedziałek i
środa"), **nigdy nie jest po cichu przepisywana** — jeśli nowa data ją unieważnia, o tym mówi
błąd kotwicy (§24.5.6), nie cicha podmiana.

**Efekt widoczny — dokładnie ten sam, co dawniej.** Dla nietkniętej reguły: wybór
tygodniowy zostaje tygodniowy (na nowym dniu tygodnia), dnia-N-miesiąca zostaje na nowym
dniu, N-ty-dzień-tygodnia zostaje na nowym `ordinal`/`weekday` (z tym samym automatycznym
podstawieniem piątego tygodnia na "ostatni", §24.5.1), miesięczny w wybranych miesiącach —
na nowym miesiącu; „codziennie" i „co miesiąc" nie mają czego przeliczać. **To wciąż jest
mechanizm, przez który 422 `anchor_not_an_occurrence` staje się nieosiągalny z interfejsu**
dla ścieżki, którą przechodzi każdy zwykły użytkownik (wybierz tryb, ewentualnie przesuń
datę) — bez niego wybór „w każdy wtorek" plus zmiana daty na środę kończyłaby się odmową na
polu **początku**, którego nikt nie kojarzy z kontrolką powtarzania.

**Jeden przypadek z dawnej krawędzi (§24.17 poz. 7) NIE przeżył — AS-BUILT, §24.17 poz. 11.**
Karta „Last day of the month" **nie jest** seedowana z kotwicy — `seedDay('last_day', …)`
zwraca tę samą wartość niezależnie od daty, bo ta karta **nazywa** regułę ("ostatni dzień
miesiąca"), a nie **wyprowadza** ją z konkretnego dnia. Dawny preset "Co miesiąc, ostatniego
dnia" po przesunięciu daty poza koniec miesiąca CICHO zamieniał się w "Co miesiąc, dnia N"
(`remapPreset`'s `fallbackId`); dzisiejsza karta **zostaje wybrana**, a data, która przestała
być ostatnim dniem miesiąca, po prostu przestaje spełniać kotwicę — błąd renderuje się pod
regułą (§24.5.6), zamiast reguły zmienić się same. Sprawdzone testem
(`calendarRecurrence.spec.ts`, opis „the anchor rule" — `last_day` jest jedynym sub-trybem
jawnie wyłączonym z asercji „każdy seed spełnia dzień, z którego powstał", bo **nazywa**
regułę zamiast ją wyprowadzać). Para „N-ty dzień tygodnia" ↔ „ostatni dzień tygodnia" przy
piątym tygodniu — druga połowa poz. 7 — **przeżyła bez zmian**, bo ten sub-tryb JEST
seedowany z kotwicy.

<details>
<summary>Historyczny algorytm presetów (usunięty, dla porównania)</summary>

Wyliczane z **daty początku, jaką trzyma formularz** (`draft.start_date` dla całodniowego,
`draft.starts_day` dla wydarzenia z godziną — czyli dnia w strefie workspace'u, tej samej,
którą serwer ostempluje przy zapisie):

```
d          = fromIsoDate(dzieńPoczątku)     // dateCore — Date LOKALNY, bez strefy, bez toISOString
weekday    = d.getDay()                     // 0..6, konwencja wspólnej warstwy (0 = niedziela)
dayOfMonth = d.getDate()                    // 1..31
month      = d.getMonth() + 1               // 1..12
ordinal    = Math.floor((dayOfMonth - 1) / 7) + 1        // 1..5
lastOfKind = dayOfMonth + 7 > daysInMonth(rok, d.getMonth())
```

Przy każdej zmianie daty preset był przeliczany bezwarunkowo, a bieżący wybór MAPOWANY na
swój odpowiednik dla nowej daty.

</details>

#### 24.5.3 Koniec serii

**Nietknięte tym refaktorem** (commit `62a73e4` zostawił ten kontrolkę i jej testy bez
zmian — "Kontrolka końca serii, reguła kotwicy i dialog zakresu — kalendarzowe, nietknięte",
z opisu commita). Widoczny wtw `Switch` "Powtarza się" jest włączony (dawniej: "wybrano
jakikolwiek preset" — terminologia nieaktualna, mechanizm ten sam). `RadioGroup`
(`orientation="horizontal"`):

| Wybór | Kontrolka | Na drut |
| --- | --- | --- |
| **Nigdy** *(domyślnie)* | — | brak `until` i brak `count` |
| **Do dnia** | `DatePicker` z `min` = dzień początku | `recurrence.until` |
| **Po liczbie powtórzeń** | `NumberInput` 1..366 | `recurrence.count` |

**AS-BUILT — jedno pole, jeden slot błędu, nie trzy. Patrz §24.17 poz. 8 i 9.** `RadioGroup`
+ widżet wartości nie stoją osobno — mieszkają pod **jednym** `FormField label="Koniec
powtarzania"`, którego `error` to pierwszy niepusty z `recurrence.until` ??
`recurrence.count` ?? **`recurrence.exclusions.dates`**. Trzeci wpis w tym łańcuchu jest
powodem scalenia: `series_has_no_occurrences` potrafi wrócić na ścieżce
`recurrence.exclusions.dates` dla serii, która **nie ma pola końca w ogóle** (§24.5.4 —
kontrolka nie oferuje edycji wykluczeń), więc gdyby tryb i wartość końca były dwoma
osobnymi `FormField`ami, ten komunikat nie miałby, pod którym z nich wylądować — a lek,
który nazywa (skróć `until` albo daj serii koniec), dotyczy właśnie tego pola. Jedno pole,
obecne przez cały czas, gdy seria się powtarza, jest jedynym miejscem, które istnieje w
każdym kształcie, jaki ten błąd może opisywać.

Trwała notka pod polem liczby: *„Zapiszemy to jako datę ostatniego wystąpienia — po
ponownym otwarciu zobaczysz datę, nie liczbę."*

**To nie jest wygoda copywritera, tylko fakt kontraktu.** Serwer rozwiązuje `count` na
`until` **raz, przy zapisie** (`endDayForCount`), a zasób zwraca **wyłącznie** `until`.
„N razy" jest więc **sposobem powiedzenia daty**, a nie wartością, która wraca. Interfejs,
który udawałby, że wraca, musiałby trzymać liczbę u siebie i rozjechać się z bazą przy
pierwszej edycji z innego miejsca. Zgłoszone jako **L11** — świadoma właściwość, nie do
„naprawienia" własnym licznikiem.

Drugi realny błąd tej kontrolki: `recurrence.count` → `count_unreachable` („Ta reguła nie ma
tylu wystąpień w rozsądnym horyzoncie…"). Trafia w rzadkie kadencje — np. „5. poniedziałek
lutego". Renderować pod polem liczby, dosłownie: serwer nazywa lek (podaj datę).

#### 24.5.4 Czego kontrolka NIE oferuje — i dlaczego

Wypisane wprost, żeby nikt nie uzupełnił tego jako przeoczenia. Podzbiór jest wąski
**świadomie**; rozszerzenie później jest addytywne i bezpieczne, zwężenie po wydaniu nie.

> **AS-BUILT — trzy wiersze tej tabeli zostały ODWRÓCONE przez commit `62a73e4` (§24.17
> poz. 10) i są tu zachowane, przekreślone w treści, dla ciągłości z resztą dokumentu —
> **nie** jako coś, co dziś obowiązuje.

| Nie ma | Powód |
| --- | --- |
| ~~**Trybu „własne" / budowania reguły z osi**~~ — **AS-BUILT: DZIŚ TO JEST KONTROLKA.** Zakładki Dzień/Miesiąc + karty sub-trybów **SĄ** edytorem reguły z osi, ograniczonym profilem (§24.5.1) | *(historyczne, poz. 10)* Pierwsze cięcie miało dowieźć zakresy operacji, a nie edytor gramatyki; presety miały pokrywać to, co człowiek mówi o spotkaniu |
| **Kadencji „co N dni" / „co N miesięcy"** (`every_n_days`, `every_n_months`) | Backend ich **nie przyjmuje** i to jest decyzja, nie brak — **nadal aktualne**: kompilują się do siatki **resetowanej co miesiąc** (i co rok), więc znaczą co innego, niż użytkownik przeczyta („co 3 dni od dziś"). `CALENDAR_RECURRENCE_PROFILE` po prostu nie wymienia tych sub-trybów w `dayModes`/`monthModes` |
| **Kadencji poddobowych** („co 5 minut", „co 2 godziny") | **Nadal aktualne** — backend ich nie przyjmuje, a profil kalendarza w ogóle nie wymienia zakładki Czas |
| **„Ostatniego dnia roboczego"** (`last_working_day`) | **Nadal aktualne** — poza `CALENDAR_RECURRENCE_PROFILE.dayModes`; trywialnie dodawalne później (dopisanie jednej wartości do listy), jeśli ktoś tego zażąda |
| ~~**Wielu dni tygodnia naraz**~~ — **AS-BUILT: DZIŚ MOŻNA.** Karty „On weekdays" / „On days of the month" to zwykłe chip-grupy wielokrotnego wyboru; żaden chip nie jest zablokowany | *(historyczne, poz. 10)* Obawa o odznaczenie dnia kotwicy była słuszna — rozwiązana **inaczej**, niż ten wiersz proponował: nie blokadą chipa, tylko błędem kotwicy na samej regule (§24.5.6), gdy odznaczenie sprawia, że dzień początku przestaje być wystąpieniem |
| **Pomijania miesięcy / dni tygodnia** (`exclusions.months`, `exclusions.weekdays`) | **Nadal aktualne** — backend odrzuca oba klucze; każde takie pominięcie jest wyrażalne jako zbiór dopełniający na osi dnia albo miesiąca |
| **Ręcznej edycji listy pominiętych dni** | **Nadal aktualne** — rośnie wyłącznie przez „usuń to wystąpienie"; przywracanie — §24.14 |
| ~~**Kreatora harmonogramu z edytora workflow**~~ — **AS-BUILT: DZIŚ TO TEN SAM KOMPONENT.** `ui/recurrence/RecurrenceAxisEditor.vue`, na dwóch różnych `profile` | *(historyczne, poz. 10)* Ten wiersz **był powodem odmowy** reużycia — patrz banner na początku §24.5 i §24.17 poz. 10 dla pełnego uzasadnienia odwrócenia |

#### 24.5.5 Godzina i strefa serii

Pod kontrolką, dla wydarzenia z godziną, trwałe zdanie:
*„Każde wystąpienie zaczyna się o {HH:mm} ({tz})."* — z `draft.starts_time` i
`meta.timezone`. Powód: godzina serii **jest** godziną wydarzenia, ustawia się ją wyżej,
w polu początku, i nie ma jej osobno; bez tego zdania użytkownik szuka w kontrolce
powtarzania pola godziny, którego tam nigdy nie będzie (serwer odrzuca `recurrence.time`).

Dla serii całodniowej — **nic**. Nie ma godziny do pokazania, a dorobienie „00:00" byłoby
tym samym defektem, którego zakazuje §6.2.

#### 24.5.6 Reguła NIETKNIĘTA nie jest ponownie osądzana — inaczej edycja tytułu blokuje się sama

**AS-BUILT, dodane commitem `62a73e4`.** §24.5.2 opisuje, kiedy oś jest RE-SEEDOWANA przy
zmianie daty. Ten podrozdział opisuje coś inne, ale sąsiednie: kiedy zapisana reguła jest w
ogóle **oceniana** wobec kotwicy — a odpowiedź nie może być "zawsze", bo o kotwicę osądza się
też edycję, która reguły w ogóle nie dotyka (np. sama zmiana tytułu wydarzenia).

**Zasada, do zapamiętania przez każdego, kto następnym razem doda pole do tego formularza:
reguła, której formularz nie dotknął w tej sesji, nie jest ponownie osądzana wobec kotwicy —
bo serwer ocenił ją na zegarze SERII (`recurrence_timezone`), a formularz czyta kotwicę na
zegarze BIEŻĄCYM workspace'u (`meta.timezone`).** Te dwa zegary są tym samym zegarem tylko
dopóki nikt nie zmienił strefy workspace'u od czasu ostatniego zapisu reguły — a zmiana strefy
o tyle, by przesunąć instant przez północ, potrafi sprawić, że idealnie poprawna, niedotknięta
seria "co poniedziałek" **wygląda** z dzisiejszego zegara na niedzielę. Osądzenie jej wobec
tego złego odczytu zablokowałoby zapis TYTUŁU nad regułą, z którą serwer nie ma żadnego
problemu.

**Mechanizm (`recurrenceAnchorSatisfied`, `pages/calendar/calendarRecurrence.ts`):** trzy
przypadki odpowiadają „kotwica spełniona" **bez oglądania kadencji w ogóle** — seria się nie
powtarza; reguła jest tylko ECHOWANA (§24.5.1, „Inna reguła" — dziś `state.unsupported`,
patrz §24.8.5); albo bieżąca kadencja formularza jest **strukturalnie równa** tej, jaką GET
właśnie załadował (`state.accepted`). Dopiero gdy żaden z trzech nie zachodzi — czyli
kadencja w TEJ sesji **zmieniła się** względem tego, co przyszło z serwera — reguła jest
faktycznie sprawdzana wobec bieżącej kotwicy, na bieżącym zegarze. Innymi słowy: kotwica
osądza **zmianę**, nigdy stan spoczynku.

**To jest ta sama zasada, którą — po stronie serwera — naprawiał wcześniejszy przegląd tego
rozdziału (C2).** Pierwsza wersja tej kontrolki po stronie klienta odtworzyła dokładnie ten
sam defekt (blokada edycji tytułu serii ze strefą inną niż bieżąca) — złapana istniejącym
testem, zanim trafiła do przeglądu. Serwerowy odpowiednik tego faktu:
`CalendarRecurrenceService`'s „stamped clock" — reguła jest zawsze czytana/oceniana na
zegarze, którym ją ostemplowano przy zapisie, nigdy na zegarze bieżącym, chyba że *to właśnie
ten zapis* stempluje ją na nowo (`docs/backend/calendar-api.md` §„A series occurrence"; kod:
`App\Modules\Calendar\Services\CalendarRecurrenceService`, komentarze przy `anchorDay()` /
`isOccurrenceDay()`). **Reguła dla następnego autora formularza:** jeżeli dodajesz pole do tego
formularza, i to pole NIE jest ani kotwicą, ani samą kadencją — nie każ go osądzać wobec
reguły, którą użytkownik nie dotknął. Jeżeli osądzasz kadencję, osądzaj **zmianę** (bieżąca
wartość ≠ to, co przyszło z GET-a), nigdy stan spoczynku.

---

### 24.6 Szuflada w trzech zakresach

#### 24.6.1 Zasiew formularza zależy od zakresu — i to jest powód, dla którego dialog jest PRZED formularzem

| Zakres | Pole początku zasiane z | Kontrolka powtarzania |
| --- | --- | --- |
| `series` | **kotwicy serii** — `event.start_date` / `event.starts_at` z GET-a | pełna, zasiana **regułą z GET-a** |
| `occurrence` | **klikniętego wystąpienia** — `occurrence.start_date` / `occurrence.starts_at` | **BRAK** — zastąpiona notką (niżej) |
| `following` | **klikniętego wystąpienia** (tam zaczyna się nowa seria) | pełna, zasiana **regułą z GET-a** |

**Dlaczego nie odwrotnie (najpierw formularz, zakres przy zapisie — jak robi Google).**
Zasób `GET` zwraca **kotwicę serii**, a nie kliknięte wystąpienie. Formularz otwarty
z kotwicy i zapisany jako `scope=occurrence` odczepiłby wydarzenie **na dniu kotwicy**,
jednocześnie wykluczając **dzień kliknięty**: użytkownik traci wtorek, który edytował,
i dostaje duplikat na dniu początku serii. Formularz zasiany z wystąpienia i zapisany jako
`scope=series` **przesuwa kotwicę** i ucina serii przeszłość. Każdy z tych zasiewów jest
poprawny dla **jednego** zakresu i katastrofalny dla drugiego — a jedyny moment, w którym
da się wybrać właściwy, jest **przed** wypełnieniem formularza.

To samo rozstrzygnięcie zamyka drugi problem za darmo: pod `scope=occurrence` payload
z regułą to 422 (`occurrence_has_no_rule`). Skoro kontrolki tam **nie ma**, użytkownik nie
może nawet spróbować — cała klasa tego błędu staje się nieosiągalna, zamiast być
przechwytywana po fakcie.

#### 24.6.2 Banner zakresu — stały, przez cały czas edycji

Nad polami, `Alert variant="info" size="sm"`, treść zależna od zakresu:

| Zakres | Treść | Akcja w wierszu |
| --- | --- | --- |
| `occurrence` | „Edytujesz **jedno wystąpienie**: {data}. Po zapisie ten dzień przestanie należeć do serii i stanie się osobnym wydarzeniem." | `Button ghost xs` **„Zmień zakres"** |
| `following` | „Edytujesz **wystąpienia od {data}**. Wcześniejsze zostaną bez zmian — powstanie z nich osobna, zamknięta seria." | jw. |
| `series` | „Edytujesz **całą serię** — razem z wystąpieniami, które już się odbyły." | jw. |

Banner jest w treści szuflady (nie sticky — szuflada jest krótka). „Zmień zakres" otwiera
dialog ponownie **bez utraty wpisanych wartości**, jeżeli nowy zakres zachowuje sens pól;
gdy zmienia zasiew daty (§24.6.1), pyta o potwierdzenie `ConfirmDialog` — porzucenie tego,
co ktoś wpisał, nie może być cichym efektem ubocznym.

#### 24.6.3 Pola pod `scope=occurrence`

Kontrolka powtarzania zastąpiona **statyczną** notką:
*„To wystąpienie przestanie należeć do serii. Reszta serii zachowa swoją regułę."*

Pole początku **bez `min`/`max`** — przeniesienie odczepionego wystąpienia na inny dzień
(„ten jeden wtorek robimy w środę") jest sensem tej operacji: serwer wyklucza pierwotny
dzień i tworzy jednorazowe wydarzenie tam, gdzie wskazał użytkownik.

#### 24.6.4 Pola pod `scope=following`

Pole początku z `min = occurrence_date` — **z jednym wyjątkiem**: gdy
`occurrence_date === dzień kotwicy`, `min` **nie jest** ustawiany, bo serwer traktuje wtedy
operację jak zwykłą edycję całego wydarzenia i przesunięcie serii wstecz jest legalne.
Poza tym przypadkiem obowiązuje `split_starts_before_the_split` (422 na polu początku),
a `min` zamienia tę odmowę w kontrolkę, której po prostu nie da się źle ustawić.

Przepływ, który to musi unieść (i unosi): *„od przyszłego tygodnia spotkanie jest w środy"* —
użytkownik klika wtorkowe wystąpienie, wybiera „to i następne", **zmienia datę na środę**,
preset sam wyprowadza się na „W każdą środę" (§24.5.2), zapis zamyka starą serię
w poniedziałek i otwiera nową w środę. Wtorek, którego dotyczył podział, znika — i o to
w tym zdaniu chodziło.

Gdy zakres wymaga wystąpienia, a URL go nie niesie (§24.3.1), szuflada spada do `series`
i mówi to wprost, `Alert info`: *„Otwarto bez wskazania wystąpienia — edycja obejmie całą
serię. Aby zmienić pojedynczy dzień, kliknij go na siatce."*

#### 24.6.5 Tryb podglądu — co widać o serii

Dodatkowe wiersze `DescriptionList`, wszystkie z pól, które istnieją:

| Wiersz | Źródło | Warunek |
| --- | --- | --- |
| **Wybrane wystąpienie** | `on`/`at` → `fullDateLabel` | jest wskazane wystąpienie |
| **Powtarza się** | `cadence_label` **klikniętego wystąpienia**, gdy mamy wystąpienie z siatki — inaczej `event.recurrence_label` z `GET`-a (patrz niżej) | zawsze dla serii, odkąd L9 jest zamknięta |
| **Początek serii** | `start_date` / `starts_at` w `meta.timezone` | zawsze dla serii |
| **Koniec** | `recurrence.until` → `fullDateLabel`, albo *„bez końca"* | zawsze dla serii |
| **Pominięte dni** | `recurrence.exclusions.dates.length` — sama liczba, lista dat w `title` | gdy > 0 |
| **Strefa reguły** | `recurrence_timezone` | **tylko** gdy `≠ meta.timezone` |

Ostatni wiersz jest warunkowy celowo: w normalnym przypadku obie strefy są tożsame i wiersz
byłby szumem; gdy się różnią, jest jedynym miejscem, w którym widać, że dzień wystąpienia
liczy się na innym zegarze niż siatka (§24.3).

**„Powtarza się" ma dziś dwa źródła, w tej kolejności — luka L9 (§24.15) jest zamknięta,
więc drugie źródło już istnieje.** Gdy mamy kliknięte wystąpienie z siatki, jego własne
`cadence_label` jest zawsze niepuste dla serii wydarzeń (patrz "A series occurrence" w
`docs/backend/calendar-api.md`) i wygrywa — jest świeższe, bo pochodzi z tego samego
odświeżenia okna, które użytkownik właśnie widzi. **Gdy nie mamy klikniętego wystąpienia**
(deep-link bez `on`/`at`, albo seria bez wystąpienia w bieżącym oknie — §24.8.4), wiersz
czyta `event.recurrence_label` z `GET`-a zamiast znikać. Wiersz **„Powtarza się" nie
renderuje się wcale** tylko wtedy, gdy **oba** źródła są `null` — czyli reguła jest jedną
z tych, dla których `CalendarCadenceLabel` sam nie umie ułożyć zdania; wtedy przy tytule
staje `Badge neutral subtle icon="repeat"` z treścią „Seria", tak jak dotąd. Frontendowi
**nie wolno** złożyć zdania o kadencji z `recurrence.day.*` w żadnym przypadku — proza jest
serwera i wraca przetłumaczona albo nie wraca wcale.

#### 24.6.6 Zapis i jego skutki

```
PUT /api/calendar/events/{id}
{
  title, description, all_day,
  start_date | starts_at (+ ends_at),
  subject_type, subject_id,          ← PRZENIESIONE z GET-a (§12.4), nadal obowiązuje
  recurrence: { day, month, exclusions, until } | (klucz nieobecny),
  scope: 'occurrence' | 'following',            ← pomijany, gdy 'series'
  occurrence_date: 'YYYY-MM-DD'                 ← wtw scope ≠ 'series'
}
```

| Reguła | Powód |
| --- | --- |
| `recurrence` **odsyłany w całości z GET-a**, gdy użytkownik nie ruszył kontrolki — z `exclusions` włącznie | `PUT` jest zapisem całego wydarzenia: brak klucza **kasuje regułę**, brak `exclusions.dates` **wskrzesza** wystąpienia usuwane pojedynczo |
| `exclusions` **nigdy nie jest budowany ani czyszczony przez formularz** — tylko przenoszony | Jedyne, co go zmienia, to „usuń to wystąpienie" po stronie serwera. Wyczyszczenie przy zmianie kadencji wyglądałoby na porządki, a przywróciłoby dni, które ktoś świadomie usunął |
| Pod `scope=occurrence` klucz `recurrence` **w ogóle nie jest wysyłany** | Pojedyncze wystąpienie nie ma własnej reguły (422) |
| Po odpowiedzi: `response.id !== id` ⇒ **powstał nowy wiersz** | Odczepienie i podział zwracają 201; identyfikator w URL-u przestał być tym, który edytuje się dalej (§24.2.3) |
| Po zapisie `scope=series` **przeczytać `recurrence_timezone` z odpowiedzi** | Zapis całej serii **stempluje regułę bieżącą strefą workspace'u**; jeżeli strefa zmieniła się od utworzenia, `recurrence_timezone` **właśnie się zmienił**, a stary jest nieaktualny do liczenia `occurrence_date` (**L13**) |
| Po sukcesie: szuflada zamyka się, `?event/edit/scope/on/at` znikają z URL-a, siatka odświeża bieżące okno | Tak jak dziś (`onEventSaved`); nowy identyfikator nie jest wpychany do URL-a, bo użytkownik patrzy z powrotem na siatkę |

Toast sukcesu **nazywa zakres** — inaczej trzy różne operacje meldują się identycznie:

| Operacja | Toast |
| --- | --- |
| zapis `occurrence` | „Zapisano to wystąpienie jako osobne wydarzenie" |
| zapis `following` | „Zapisano wystąpienia od {data}" |
| zapis `series` | „Zapisano całą serię" |
| usunięcie `occurrence` | „Usunięto to wystąpienie" |
| usunięcie `following` | „Usunięto wystąpienia od {data}" |
| usunięcie `series` | „Usunięto wydarzenie" |

---

### 24.7 Dwa różne fakty, które nie mogą wyglądać tak samo

Na siatce istnieją teraz **dwa** powody, dla których kafelek może być oznaczony. Zlanie ich
w jeden znacznik nauczyłoby użytkownika ignorować oba.

| Fakt | Skąd | Co znaczy |
| --- | --- | --- |
| **„To jest wystąpienie serii"** | `recurring === true` | Fakt o **podmiocie**. Trwały. Mówi: klik tutaj otworzy wydarzenie, które ma więcej niż ten jeden dzień. **Nie** `cadence_label !== null` — ta implikacja jest wystarczająca, ale nie konieczna (zamknięta luka L8, §24.15): harmonogram w trybie stałych godzin **też** się powtarza i `recurring` to teraz poprawnie łapie, mimo że jego `cadence_label` zostaje `null`. |
| **„Widzisz próbkę"** | `dense === true` | Fakt o **odpowiedzi**. Przygodny. Mówi: reszty tej pozycji w tym oknie **nie ma** |

**Konsekwencja przejścia z `cadence_label !== null` na `recurring`.** Harmonogram w trybie
stałych godzin (`at`-mode) dostaje dziś glif/plakietkę serii, której wcześniej nie dostawał
— to jest **poprawka pokrycia**, nie regresja: ten podmiot naprawdę się powtarza, tylko nie
miał o tym nic do powiedzenia w prozie. Konsekwencja dla treści plakietki w wariancie
`agenda`/`list`: gdy `recurring === true`, a `cadence_label === null` (dokładnie ten
przypadek), plakietka renderuje się **bez treści** (sam `icon="repeat"`, jak w wariancie
`grid`) zamiast składać zdanie po stronie klienta — spójne z regułą „proza jest serwera
albo jej nie ma" z reszty tego rozdziału.

#### 24.7.1 Znaczniki

| | Wariant `grid` | Wariant `agenda` / `list` |
| --- | --- | --- |
| **Seria** | glif `repeat`, xs, `opacity-60`, **przed** glifem kierunku (reguła kolejności afordancji trailing) | `Badge neutral subtle icon="repeat"`, z treścią **`cadence_label`** gdy niepuste, inaczej bez treści (patrz wyżej) — proza serwera, dosłownie, nigdy składana |
| **Próbka** | `Badge warning subtle icon="layers"`; liczba **tylko** gdy zwinięto > 1 | to samo + `title` z pełnym zdaniem |

**Ton `warning` dla próbki jest powiązaniem, nie ozdobą:** komunikat `item_densified`
u góry ekranu to `Alert variant="warning"` (§13.3), a chip jest jego odbiciem na siatce.
Ten sam ton wiąże jedno z drugim bez ani jednego dodatkowego napisu.

**W wariancie `grid` renderuje się co najwyżej JEDEN znacznik, i wygrywa próbka.** Powód:
komórka ma ~11 rem, a „nie widzisz wszystkiego" jest pilniejsze niż „to się powtarza" —
tym bardziej że dla źródła `event` próbka **implikuje** serię. Zdanie o serii nie ginie:
zostaje w `aria-label` i w `title` kafelka.

#### 24.7.2 Zmiana wobec dzisiejszego kodu

`OccurrenceChip.vue` renderuje dziś **jeden** znacznik — `Badge … icon="repeat"` pod
warunkiem `folded` (czyli gęstości). Po B6:

- `repeat` **przechodzi na fakt serii** (to jest znaczenie tego glifu i tak go czyta również
  kontrolka powtarzania w szufladzie);
- próbka dostaje **nowy** glif `layers` — patrz §24.12.2.

**Nie wolno zostawić `repeat` na obu.** To jest dokładnie ta jedna rzecz, którą ten
podrozdział istnieje, żeby uczynić niemożliwą.

#### 24.7.3 Fakt o gęstości, który zmienia wagę tej sekcji

Seria wydarzeń ma **najwyżej jedno wystąpienie dziennie** (jedna godzina z konstrukcji),
a budżet pozycji to **64** przy oknie **≤ 62 dni** — więc **seria wydarzeń nigdy nie
przekroczy budżetu pozycji**. `dense: true` na wystąpieniu wydarzenia może dziś powstać
**wyłącznie** z sufitu całej odpowiedzi (1000 wystąpień) trafiającego w środek serii.
Skutki dla UI:

- oba znaczniki naraz są **rzadkie**, ale muszą być rozróżnialne, bo gdy wystąpią, znaczą
  co innego;
- `collapseDense` grupuje po `subject.id` w obrębie **jednego dnia**, więc dla serii
  wydarzeń zwija zawsze **jedną** pozycję → `shown === 1`. Dlatego liczba w chipie próbki
  pokazuje się **tylko przy `shown > 1`**: „próbka: 1" to zdanie o niczym;
- komunikat `item_densified` dla źródła `event` opowiada wtedy o **całych brakujących dniach
  serii**, nie o urwisku w jednym dniu — copy z §13.3 pozostaje prawdziwe („widzisz początek
  serii, puste dni po niej nie znaczą, że nic się nie dzieje").

---

### 24.8 Stany

#### 24.8.1 Ładowanie

| Powierzchnia | Stan |
| --- | --- |
| Siatka / agenda | Bez zmian (§15.1) — projekcja serii nie dokłada żądań, seria to te same wystąpienia |
| Szuflada (GET wydarzenia) | Bez zmian (§12.1); szkielet imituje układ, **plus** dwa dodatkowe wiersze meta, bo blok serii ma 3–5 wierszy |
| Dialog usuwania po potwierdzeniu | `Button loading` — spinner **w przycisku**, tekst zostaje, szerokość stała; **opcje zostają aktywne** (ani `readonly`, ani `disabled`) — AS-BUILT, §24.17 poz. 5: jedyny realny błąd tego dialogu wskazuje jako lekarstwo inną opcję tego samego dialogu, więc muszą zostać klikalne przez cały czas |

#### 24.8.2 Pusto

Bez zmian wobec §15.2. Jedyny nowy przypadek pustki to **§24.8.4**.

#### 24.8.3 Odmowa walidacji na polach reguły

| Sytuacja | Zachowanie |
| --- | --- |
| 422 ze ścieżką `recurrence.*` | Komunikat **z serwera**, pod kontrolką powtarzania/końca (mapa: §24.2.4). Focus na pierwszą błędną kontrolkę. **Bez toastu** |
| 422 na `start_date`/`starts_at` z powodu reguły (`anchor_not_an_occurrence`, `split_starts_before_the_split`) | Pod polem **początku** — bo tam jest lek. Banner zakresu zostaje widoczny, żeby zdanie „nie może zaczynać się przed podziałem" miało kontekst |
| 422 na `scope`/`occurrence_date` | `Alert danger` na górze szuflady + `Button ghost xs` **„Wybierz zakres ponownie"**. Formularz **zostaje wypełniony** |
| 422 na kluczu, dla którego nie ma kontrolki (`recurrence.time`, `recurrence.tz`, obcy klucz) | `Alert danger` na górze — to defekt klienta (stary bundle, ręcznie zbudowany payload), nie stan użytkownika |

#### 24.8.4 Seria bez ani jednego wystąpienia w oglądanym oknie

Realne i częstsze, niż wygląda: seria zakończona w zeszłym roku, reguła roczna oglądana
w innym miesiącu, seria zaczynająca się za pół roku.

**Wykrycie** (wyłącznie z danych, które są): szuflada pokazuje serię
(`event.recurrence !== null`) **i** w `store.occurrences` nie ma ani jednego wystąpienia
z `subject.id === event.id`.

**Warunek konieczny, bez którego to zdanie byłoby kłamstwem:** źródło `event` musiało być
**zapytane i odpowiedzieć** — czyli filtr źródeł je obejmuje (albo jest pusty) **i** nie ma
go w `meta.unavailable_sources`. Inaczej „ta seria nie ma tu wystąpień" znaczy „nie
pytaliśmy", a to dwa różne zdania (§14).

**Co pokazujemy** — `Alert variant="info" size="sm"` w szufladzie:
*„Ta seria nie ma wystąpień w oglądanym miesiącu."*
plus **co najwyżej dwa** przyciski `ghost xs`, każdy z etykietą prawdziwą jako fakt
o **dacie**, a nie obietnicą o wystąpieniu:

| Przycisk | Cel | Warunek |
| --- | --- | --- |
| „Pokaż początek serii ({miesiąc})" | `?month = monthOf(start_date \| starts_at→tz)` | zawsze dla serii |
| „Pokaż koniec serii ({miesiąc})" | `?month = monthOf(recurrence.until)` | wtw `until !== null` i inny miesiąc niż początek |

**Czego tu nie ma i nie będzie: „pokaż następne wystąpienie".** Policzenie go to projekcja
kadencji, czyli re-implementacja silnika po stronie klienta — dokładnie to, przed czym
ADR-0052 broni całą wspólną warstwę. Miesiąc **początku** i miesiąc **końca** to daty
odczytane z zasobu i nic więcej.

#### 24.8.5 Reguła, której kontrolka nie umie wyrazić

> **AS-BUILT, §24.17 poz. 10.** Ta sekcja opisywała stan Select-owej kontrolki presetowej;
> mechanizm poniżej jest AKTUALNY (`state.unsupported`, `pages/calendar/calendarRecurrence.ts`
> / `RecurrenceField.vue`), ale **zakres, w jakim się włącza, radykalnie się skurczył** — patrz
> "Wykrycie" niżej — bo dzisiejsza kontrolka jest edytorem osi, nie listą presetów.

Kontrakt przyjmuje szerszy podzbiór niż to, co kontrolka umie edytować (`every_n_days`,
`every_n_months`, `last_working_day` — dokładnie to, czego `CALENDAR_RECURRENCE_PROFILE` nie
wymienia, §24.5.1/§24.5.4). Taka reguła może powstać z ręcznie edytowanego wiersza albo z
backendu, który poszerzy gramatykę wcześniej niż ta kontrolka. **Formularz nie może jej po
cichu przepisać na najbliższą wyrażalną regułę.**

| Wymóg | Zachowanie |
| --- | --- |
| Wykrycie | **AS-BUILT — inny test niż dawniej.** Nie „nie jest równy żadnemu presetowi dla dzisiejszej daty" (presety już nie istnieją), tylko: `dayFromWire`/`monthFromWire` zwraca `null` dla trybu/`special`, którego `CALENDAR_RECURRENCE_PROFILE` nie wymienia. **Skutek:** reguła, która dawniej BYŁA „Inną regułą" — np. `weekdays: [1, 3]` (dwa dni tygodnia) — dziś **nie jest** `unsupported` wcale: `weekdays` z wieloma dniami to zwykła, edytowalna wartość karty „On weekdays" (§24.5.1, §24.5.4). Stan przeżywa **wyłącznie** jako strażnik deskryptora spoza profilu, nieosiągalnego którąkolwiek dzisiejszą ścieżką zapisu (fakt 6, nagłówek `calendarRecurrence.ts`) |
| UI | **AS-BUILT — nie Select.** `Alert variant="info"` (`calendar.recurrence.unsupportedNote`) + `Button` „Replace with a new rule" (`unsupportedReplace`) — **zamiast** `RecurrenceAxisEditor`, nie jako jego dodatkowa, zablokowana pozycja (nie ma już Selecta z listą presetów) |
| Zdanie | `cadence_label` klikniętego wystąpienia, jeśli jest; inaczej `event.recurrence_label` z `GET`-a (odkąd L9 jest zamknięta — §24.15); dopiero gdy **oba** są `null` — `t('calendar.series.unknownRule')`. Sama `unsupported` nie gwarantuje pustego zdania: reguła spoza profilu zwykle ma swoje `recurrence_label` (np. „Weekly on Mon, Wed, Fri"), tylko kontrolka nie umie jej **wyedytować** |
| Zapis | Blok `recurrence` jedzie **dosłownie** — `state.unsupported.day` / `.month`, razem z `exclusions` i `until` (`recurrenceStateToWire`) |
| Reszta formularza | **Działa normalnie** — tytuł, opis, godzinę i koniec serii da się poprawić bez ruszania reguły |
| Wyjście | Przycisk „Replace with a new rule" (`replaceUnsupported()`) **nadpisuje** regułę wartością neutralną (`every_day` / `every_month`) i pokazuje edytor osi; nie da się wrócić do echowanej reguły bez anulowania edycji |

---

### 24.9 Responsywność

Bez zmian w podziale z §17. Trzy dopiski, wszystkie wynikające z nowych powierzchni:

| Breakpoint | Zachowanie |
| --- | --- |
| `< next-md` (agenda zawsze) | Znacznik serii jest **`Badge` z `cadence_label`**, nie glifem — agenda ma szerokość, a to jedyne miejsce, w którym użytkownik telefonu przeczyta kadencję |
| `< next-md`, szuflada `size="full"` | Kontrolka powtarzania i kontrolka końca układają się **w kolumnie**; `RadioGroup` końca przechodzi z `horizontal` na `vertical` |
| Dialog zakresu | `Modal size="sm"`; na wąskim ekranie opcje mają cel dotykowy ≥ 44 px (`Radio` w rozmiarze `md`, nie `sm`), a stopka łamie się na dwa przyciski pełnej szerokości — potwierdzenie **na dole**, żeby nie było pierwszym, w co trafi kciuk |

---

### 24.10 Dostępność

**Reguła nadrzędna: siatka ma działającą obsługę klawiatury i B6 jej nie dotyka.**
Żadnego nowego punktu tabulacji w `role="grid"`, żadnej zmiany mapy klawiszy (§18.1),
żadnych nowych `tabindex` w komórce. Znaczniki serii i próbki są `aria-hidden`, a ich treść
wchodzi do **istniejącego** `aria-label` kafelka.

| Powierzchnia | Wymóg |
| --- | --- |
| Kafelek serii | `aria-label` rozszerzony o `cadence_label` (proza serwera) **i** o to, że klik prowadzi do wyboru zakresu — jedno zdanie, ta sama kolejność co dziś: tytuł · czas · źródło · plakietka · seria · kierunek |
| Kafelek próbki | Zdanie o próbce **przed** zdaniem o kierunku; `calendar.dense.aria` zostaje bez zmian |
| Dialog zakresu | §24.4.6 |
| Banner zakresu | `Alert` → `role="status"` (nie `alert`: to stan trwały, nie zdarzenie) |
| Kontrolka powtarzania | `FormField` daje etykietę i `aria-describedby`; notki warunkowe (pomijane miesiące, godzina serii) są **opisami pola**, nie `title` |
| Błędy reguły | `aria-invalid` na kontrolce + komunikat podpięty przez `FormField` (mechanizm naprawiony app-wide w `e501cf2` — nie obchodzić go własnym `<p>`) |
| Znacznik serii nigdy nie jest samym kolorem | Glif + tekst w `aria-label`; ton `warning` próbki jest **wzmocnieniem**, nie nośnikiem |
| Zmiana presetu przy zmianie daty (§24.5.2) | Select przerysowuje etykietę; **bez** `aria-live` — to bezpośrednia konsekwencja akcji użytkownika w sąsiednim polu, a ogłaszanie jej rozbiłoby wpisywanie daty |

---

### 24.11 Ciemny motyw i tokeny

Wyłącznie tokeny semantyczne `next-*`; dark mode działa przez podmianę tokenów, **bez ani
jednej odwróconej wartości w komponentach** (§16.2). Nowe punkty do sprawdzenia w obu
motywach:

| Element | Ryzyko | Wymóg |
| --- | --- | --- |
| `Badge warning subtle` (próbka) **na** kafelku `primary-subtle` | Dwa subtelne tła jedno na drugim; w dark `warning-subtle` i `primary-subtle` mają bliską jasność | Sprawdzić kontrast krawędzi; jeśli znika — `tone` zostaje `subtle`, ale dochodzi `ring-1 ring-next-warning/40`. **Nie** zmieniać na `solid`: przekrzyczałoby tytuł |
| Glif `repeat` przy `opacity-60` | W dark `muted-foreground` na `primary-subtle` bywa za miękki | Minimalnie `opacity-70` w dark; próg sprawdzić na kafelku `neutral` (najtrudniejszy) |
| Banner zakresu (`Alert info`) w szufladzie | Trzy warstwy: `Drawer` → `Alert` → `FormField` | Sprawdzić, czy `info-subtle` odróżnia się od tła szuflady w dark |
| Dialog zakresu `variant="danger"` | Stos `Modal` nad `Drawer` — dwa scrimy | Sprawdzić, czy panel dialogu **czyta się jako wierzchni**, a nie jako część szuflady |

---

### 24.12 Inwentarz komponentów

#### 24.12.1 Reuse — bez zmian

`Modal`, `RadioGroup`, `Radio` (ma `description` — nośnik zdania o skutku), `Select`
(statyczne `options`), `NumberInput`, `DatePicker` (ma `min`/`max`/`disabledDate`),
`FormField`, `Alert`, `Badge`, `Button`, `Icon`, `DescriptionList`, `ConfirmDialog`
(dla wydarzenia **nie**-cyklicznego), `useToast`, `useConfirm`, `useOverlayStack`.

Z `dateCore`: `fromIsoDate`, `daysInMonth`, `weekdayNames`, `monthNames`, `fullDateLabel`.
Z `calendarZone`: `instantToZonedParts`, `instantToWallClock`, `monthOf`.

#### 24.12.2 Extend

| Plik | Zmiana | Uzasadnienie |
| --- | --- | --- |
| `ui/primitives/icons.ts` | dodać `'layers'` do `IconName` **i** do `ICONS` | Znacznik **próbki**, po tym jak `repeat` przechodzi na fakt serii (§24.7.2). `more-horizontal` czyta się jako menu akcji, `alert-triangle` jest zajęty przez `items_dropped` (§13.2 — inny, cięższy rodzaj straty), `eye-off` znaczy już „anonimowe" i „ukryj hasło". Rejestr jest z założenia rozszerzalny (tak dodano `map-pin`, `package`, `repeat`) |
| `pages/calendar/types.ts` | `CalendarEvent` += `recurrence`, `recurrence_timezone`; `CalendarEventPayload` += `recurrence`, `scope`, `occurrence_date`; nowe typy `CalendarRecurrenceRule`, `CalendarEventScope` | 1:1 z kontraktem §24.2. **Bez wymyślonych pól** |
| `app/stores/calendar.ts` | `saveEvent`/`deleteEvent` przyjmują zakres; `buildEventPayload` przenosi `recurrence` z GET-a i dokłada `scope`/`occurrence_date` | Trzy niewidoczne przy złamaniu inwarianty (całe wydarzenie, jedna grupa czasowa, jawny offset) mają tu **czwarty**: reguła jedzie w całości |
| `pages/calendar/OccurrenceChip.vue` | rozdzielenie dwóch znaczników (§24.7) | — |
| `pages/calendar/EventDrawer.vue` | banner zakresu, zasiew per zakres, kontrolka powtarzania, blok serii w podglądzie, `FORM_FIELDS` po prefiksie `recurrence.` | — |
| `pages/calendar/CalendarView.vue` | `on`/`at`/`scope` w URL-u, przekazanie klikniętego wystąpienia do szuflady | — |

#### 24.12.3 Create

> **AS-BUILT, §24.17 poz. 10 — `recurrencePresets.ts` nie istnieje.** Skasowany
> w `62a73e4` i zastąpiony przez `pages/calendar/calendarRecurrence.ts` (wire mapping +
> stan końca serii + kotwica) opierający się na **wspólnym** `ui/recurrence/` (siedem
> plików: rdzeń osi + trzy panele + karty opcji + pole okna), reużytym z Workflows zamiast
> zbudowanym od nowa dla Kalendarza. Wiersze tej tabeli poniżej zostawione jako historyczny
> plan B6; aktualna lista plików — §24.5 banner + inwentarz w tej samej sekcji, niżej.

| Plik | Rola |
| --- | --- |
| `pages/calendar/SeriesScopeModal.vue` | Dialog zakresu — dwa tryby (edycja / usuwanie), dynamiczna etykieta potwierdzenia, miejsce na błąd serwera |
| ~~`pages/calendar/RecurrenceField.vue` — presety wyprowadzone z daty~~ | **AS-BUILT: plik istnieje, ale mechanizm inny.** Kontrolka powtarzania: `RecurrenceAxisEditor` na `CALENDAR_RECURRENCE_PROFILE` + kontrolka końca (nietknięta) + notki warunkowe (nietknięte) — §24.5.1 |
| ~~`pages/calendar/recurrencePresets.ts`~~ | **AS-BUILT: SKASOWANY.** Zastąpiony `pages/calendar/calendarRecurrence.ts` (`RecurrenceState`, `recurrenceStateFrom`/`recurrenceStateToWire`, `recurrenceAnchorSatisfied` — §24.5.6) — dziś **bez** `presetsFor`/`descriptorOf`/`presetOf`/`remapPreset`, bo nie ma już czego rozpoznawać jako "preset" |
| `pages/calendar/occurrenceDate.ts` | **Zredukowany od L8 (§24.15): backend zwraca `occurrence_date` bezpośrednio, więc nie ma już czego liczyć.** Jeśli powstaje, to jako jeden trywialny getter (`occurrenceDateOf(occurrence) → occurrence.occurrence_date`) dla jednego miejsca importu — nie jako moduł z logiką derywacji z §24.3. Frontend-agent decyduje, czy taki plik w ogóle jest wart tworzenia, czy odczyt idzie inline |
| `ui/recurrence/{recurrenceAxes.ts, RecurrenceAxisEditor.vue, RecurrenceTimePanel.vue, RecurrenceDayPanel.vue, RecurrenceMonthPanel.vue, RecurrenceOptionCards.vue, RecurrenceWindowField.vue}` | **AS-BUILT, nowe od `62a73e4` — WSPÓLNE z Workflows, nie kalendarzowe.** Home + uzasadnienie umiejscowienia: §24.5 banner na początku tego rozdziału; pełny opis — component-state-matrix.md „Tier 4+" |

**Zero nowych zależności npm.**

#### 24.12.4 Testy Vitest — minimum

1. `occurrenceDate` (jeśli plik powstaje) — asercja **przepisania**, nie liczenia:
   `occurrenceDateOf(occurrence) === occurrence.occurrence_date`, dla obu kształtów
   (`all_day` i z godziną). Formuła derywacji z §24.3 zostaje jako test **regresyjny wobec
   backendu** (odtwarza to samo dla pary stref, w której dzień się przesuwa), nie jako
   ścieżka produkcyjna.
2. `recurrencePresets` — dla 25.08.2026 (wtorek, 4. wtorek) lista zawiera „W każdy wtorek",
   „Co miesiąc, dnia 25", „Co miesiąc: 4. wtorek", „Co roku, 25 sierpnia".
3. `recurrencePresets` — dla 31.03.2026 preset ostatniego dnia jest w liście, a preset
   „dnia 31" niesie notkę o pomijanych miesiącach.
4. `remapPreset` — zmiana daty z wtorku na środę zamienia `weekdays:[2]` na `weekdays:[3]`
   (asercja: kotwica **zawsze** spełnia wyprodukowaną regułę).
5. `presetOf` — deskryptor `weekdays:[1,3]` (spoza kontrolki) → `null`, a formularz odsyła go
   **bajt w bajt**.
6. `buildEventPayload` — edycja tytułu serii odsyła `recurrence` z `exclusions.dates`
   nietkniętym; pod `scope=occurrence` klucza `recurrence` **nie ma**.
7. `SeriesScopeModal` — etykieta przycisku zmienia się z wyborem; przy pierwszym wystąpieniu
   opcja `following` niesie zdanie o całej serii.
8. Chip — `recurring === true` daje glif serii (**nie** `cadence_label !== null` — §24.7);
   `dense` daje glif próbki; oba naraz w wariancie `grid` dają **tylko** próbkę. Osobny
   przypadek: `recurring === true` i `cadence_label === null` (harmonogram w trybie stałych
   godzin) wciąż daje glif serii w `grid`, a w `agenda`/`list` plakietkę bez treści.
9. Chip — `dense` przy `shown === 1` renderuje znacznik **bez liczby**.
10. „Seria bez wystąpień w oknie" — komunikat **nie** pojawia się, gdy źródło `event` jest
    odfiltrowane albo jest w `unavailable_sources`.

---

### 24.13 Copy i klucze i18n

Namespace `calendar.*` (`resources/js/next/app/i18n/{en,pl}.ts`, `en.ts` źródłem typu,
`pl.ts` 1:1). **Bez odmiany liczebników** — kształt „Etykieta: {n}".

> **AS-BUILT (commit `62a73e4`) — `calendar.recurrence.*` wyprowadzony na nowo z bloku
> `recurrence: {…}` w obu katalogach, klucz po kluczu (obie wersje wciąż 1:1).** Cały
> słownik presetów (`daily`, `weeklyDays.0..6`, `monthlyDay`, `monthlyNth`,
> `monthlyLastDay`, `monthlyLastWeekdays.0..6`, `ordinals.1..5`, `yearly`, `other`,
> `otherReplaces`) jest **usunięty z katalogu** — zastąpiony przez `unsupportedNote` /
> `unsupportedReplace` / `anchorMismatch`, trzy klucze, których dawny słownik nie miał, bo
> nie miał pojęcia „reguła spoza profilu" (§24.5.1, §24.8.5). Kart/zakładek edytora osi
> (dawnych etykiet presetów) ten namespace **już nie niesie** — patrz akapit o dwóch
> namespace'ach poniżej.

```
calendar.series.badge                  "Seria"
calendar.series.marker                 "Wystąpienie serii"          (aria/title glifu)
calendar.series.repeats                "Powtarza się"               (etykieta wiersza)
calendar.series.start                  "Początek serii"
calendar.series.end                    "Koniec"
calendar.series.endNever               "bez końca"
calendar.series.skipped                "Pominięte dni: {n}"
calendar.series.ruleTimezone           "Strefa reguły"
calendar.series.selectedOccurrence     "Wybrane wystąpienie"
calendar.series.unknownRule            "Ta seria ma regułę, której ten formularz nie edytuje."
calendar.series.noneInWindow           "Ta seria nie ma wystąpień w oglądanym miesiącu."
calendar.series.goToStart              "Pokaż początek serii ({month})"
calendar.series.goToEnd                "Pokaż koniec serii ({month})"

calendar.sample.marker                 "Widzisz próbkę tej pozycji"

calendar.scope.edit.title              "Co chcesz edytować?"
calendar.scope.delete.title            "Co usunąć?"
calendar.scope.context                 "Wybrane wystąpienie: {date}"
calendar.scope.occurrence.label        "Tylko to wystąpienie"
calendar.scope.occurrence.editHint     "Ten dzień wyjdzie z serii i stanie się osobnym wydarzeniem. Reszta serii zostaje bez zmian."
calendar.scope.occurrence.deleteHint   "{date} zniknie z serii. Pozostałe wystąpienia zostają."
calendar.scope.following.label         "To i wszystkie następne"
calendar.scope.following.editHint      "Wcześniejsze wystąpienia zostaną takie, jakie były. Od tego dnia powstanie nowa seria."
calendar.scope.following.deleteHint    "Seria skończy się dzień wcześniej. Wcześniejsze wystąpienia zostają."
calendar.scope.following.firstHint     "To pierwsze wystąpienie serii, więc ta opcja obejmie całą serię."
calendar.scope.series.label            "Całą serię"
calendar.scope.series.editHint         "Wszystkie wystąpienia — także te, które już się odbyły. Zmiana reguły przepisze historię: przeniesienie spotkania na środy zamieni w środy również zeszłoroczne poniedziałki."
calendar.scope.series.deleteHint       "Wydarzenie zniknie z kalendarza razem z całą historią. W interfejsie nie da się tego cofnąć."
calendar.scope.confirm.editOccurrence   | .editFollowing   | .editSeries
calendar.scope.confirm.deleteOccurrence | .deleteFollowing | .deleteSeries
calendar.scope.change                  "Zmień zakres"
calendar.scope.retry                   "Wybierz zakres ponownie"
calendar.scope.banner.occurrence | .following | .series
calendar.scope.fallbackNotice          "Otwarto bez wskazania wystąpienia — edycja obejmie całą serię. Aby zmienić pojedynczy dzień, kliknij go na siatce."

calendar.recurrence.label              "Powtarzanie"
calendar.recurrence.none               "Nie powtarza się"
calendar.recurrence.unsupportedNote    "Tej reguły nie da się tu edytować. Reszta wydarzenia zapisuje się normalnie, a reguła zostaje bez zmian."
calendar.recurrence.unsupportedReplace "Zastąp nową regułą"
calendar.recurrence.anchorMismatch     "Dzień początkowy nie jest wystąpieniem tej reguły. Dostosuj regułę albo przesuń początek."
calendar.recurrence.shortMonthsNote    "Miesiące bez dnia {day} zostaną pominięte."
calendar.recurrence.hourNote           "Każde wystąpienie zaczyna się o {time} ({tz})."
calendar.recurrence.detachNote         "To wystąpienie przestanie należeć do serii. Reszta serii zachowa swoją regułę."

calendar.recurrence.end.label          "Koniec powtarzania"
calendar.recurrence.end.never          "Nigdy"
calendar.recurrence.end.until          "Do dnia"
calendar.recurrence.end.count          "Po liczbie powtórzeń"
calendar.recurrence.end.countNote      "Zapiszemy to jako datę ostatniego wystąpienia — po ponownym otwarciu zobaczysz datę, nie liczbę."

calendar.event.savedOccurrence         "Zapisano to wystąpienie jako osobne wydarzenie"
calendar.event.savedFollowing          "Zapisano wystąpienia od {date}"
calendar.event.savedSeries             "Zapisano całą serię"
calendar.event.deletedOccurrence       "Usunięto to wystąpienie"
calendar.event.deletedFollowing        "Usunięto wystąpienia od {date}"
```

**Dwa istniejące klucze wymagają przeredagowania, bo po rozdzieleniu faktów (§24.7) mówią
nie o tym, przy czym stoją:**

| Klucz | Dziś | Po B6 |
| --- | --- | --- |
| `calendar.dense.chip` | „Seria — pokazano {shown}" | **„Pokazano {shown} z tej pozycji"** — słowo „Seria" przechodzi na znacznik serii i nie może stać przy znaczniku próbki |
| `calendar.dense.aria` | „Ta pozycja powtarza się częściej, niż widać na siatce" | **bez zmian** — mówi o tym, czego siatka nie pokazuje, czyli o próbce |

**Nadal nie ma kluczy na:** zdanie o kadencji **zapisanej** reguły (`cadence_label` na
wystąpieniu, `recurrence_label` na zasobie wydarzenia — oba proza serwera), nazwy źródeł,
treści plakietek, komunikaty walidacji. Trwałe, nie tymczasowe: żadne z tych pól nigdy nie
potrzebuje klucza i18n, bo klient go nie tłumaczy — tylko renderuje.

**Dwa namespace'y niosą dziś kontrolkę powtarzania — AS-BUILT, commit `62a73e4`.**
`calendar.*` (ten, katalogowany powyżej) niesie tylko to, co jest **specyficznie
kalendarzowe**: przełącznik „Powtarza się", notki (godzina serii, krótkie miesiące,
strażnik reguły spoza profilu) i kontrolkę końca serii. Etykiety **kart i zakładek** samego
edytora osi (dawne etykiety presetów — „Every day", „On weekdays", „A specific weekday"…)
żyją pod **wspólnym**, nie-kalendarzowym namespace'em `recurrenceEditor.*`
(`resources/js/next/app/i18n/{en,pl}.ts`, sekcja `recurrenceEditor: {…}`) — bo ten sam
komponent, z tymi samymi kartami, renderuje się też w edytorze harmonogramu przepływów
(§24.5, banner na początku; `docs/next/component-state-matrix.md`, „Tier 4+"). Ta
dokumentacja nie kataloguje `recurrenceEditor.*` tutaj drugi raz — pełny katalog kluczy tego
namespace'u (i tego, co z niego zostało w `workflows.schedule.*`) jest w
`resources/js/next/docs/pages/WorkflowsPage.vue`, blok „Schedule i18n".

**Rozróżnienie, które oba namespace'y razem wciąż utrzymują: żaden klucz wyboru nie opisuje
reguły zapisanej.** Kontrolka (oba namespace'y razem) opisuje **zamiar** (serwer nie ma o nim
zdania, bo reguła jeszcze nie istnieje), a kafelek i podgląd opisują **stan** (i biorą zdanie
z serwera, `cadence_label`/`recurrence_label`). Etykiety kart **nie wolno** użyć do opisania
reguły, która już jest zapisana — fakt 4 nagłówka `RecurrenceField.vue`.

---

### 24.14 Czego NIE ma w B6

| Nie ma | Dlaczego |
| --- | --- |
| **Przywracania pominiętego dnia** | Wyrażalne w kontrakcie (`PUT` z `exclusions.dates` bez jednej daty), więc to **świadome odroczenie**, nie luka. Wymaga listy dat z akcją per wiersz i zapisu całej serii — osobna, mała iteracja. Dopóki go nie ma, dialog usuwania nie obiecuje odwracalności |
| **Podglądu „kiedy wypadną najbliższe wystąpienia"** | Projekcja kadencji po stronie klienta = drugi silnik. Siatka **jest** podglądem: zapisz i zobacz |
| **Wielu dni tygodnia / wielu miesięcy w kontrolce** | §24.5.4 — API to unosi, kontrolka nie; wymaga zablokowanego chipa kotwicy |
| **Trybu „własne"** | §24.5.4 |
| **Przeciągania wystąpienia na inny dzień** | D3 zostaje w mocy: 3 z 4 źródeł są nieprzesuwalne, a mieszana afordancja uczy nieufności do całego ekranu. „Ten wtorek robimy w środę" ma pełną ścieżkę: zakres `occurrence` + zmiana daty |
| **Serii rysującej rozpiętość przez dni** | Model wystąpienia to jedna kratka (§22), a seria nie zmienia modelu |
| **Wyjątku „to wystąpienie odbyło się o innej godzinie" bez odczepiania** | Backend nie ma tabeli nadpisań i świadomie jej nie chce: odczepienie daje zwykły wiersz, o którym nic nie musi wiedzieć, że jest wyjątkiem |

---

### 24.15 Luki kontraktu (B6)

Zgłoszone, **nie** dopisane po cichu do specyfikacji.

---

#### L8 — Wystąpienie nie niesie swojej daty ani flagi „to jest seria" — **ZAMKNIĘTA**

**Fakt (stan w chwili zgłoszenia).** `CalendarOccurrenceResource` zwracał `id`, `source`,
`editable`, `all_day`, `start_date`, `starts_at`, `ends_at`, `title`, `color`, `badge`,
`dense`, `cadence_label`, `subject`. Dnia wystąpienia (`occurrence_date`), którym nazywa je
**powierzchnia zapisu**, na drucie nie było — jedynie wewnątrz `id`
(`event:{uuid}:{Y-m-d}`), a dokumentacja backendu wprost zaleca, żeby klient nie parsował
`id`.

**Skutek (wtedy).** Frontend musiałby wyprowadzać datę sam (§24.3 — dawna, wymagana ścieżka)
za cenę dwóch zależności niewidocznych w typie: `recurrence_timezone` (czyli `GET`
wydarzenia przed policzeniem czegokolwiek) i znajomości reguły „dzień na zegarze serii".
Kafelek nie miał też jak wiedzieć, że jest wystąpieniem serii, inaczej niż przez
`cadence_label !== null` — warunek **wystarczający, ale nie konieczny**: wystąpienie
harmonogramu w trybie stałych godzin powtarza się i niesie `null`.

**Co faktycznie weszło — dokładnie pod rekomendowanymi nazwami.**
`CalendarOccurrenceResource` niesie dziś **`recurring: bool`** i
**`occurrence_date: string|null`**, zawsze obecne na każdym wystąpieniu z każdego źródła
(`recurring: false`/`occurrence_date: null` domyślnie dla źródeł, które nic o tym nie
wiedzą — żadne inne źródło nie wymagało zmiany). Pełny kontrakt:
`docs/backend/calendar-api.md` → „Resource shapes" → sekcja o `recurring`/`occurrence_date`.

**Co to zmienia w tej specyfikacji.** §24.3's derywacja **nie jest już wymaganym krokiem** —
`occurrence_date` przychodzi gotowe na siatce, bez dodatkowego `GET`-a. §24.7's warunek
„to jest wystąpienie serii" czyta się dziś z `recurring`, nie z obecności `cadence_label`
(patrz §24.7 poniżej — to jest bezpośrednia konsekwencja zamknięcia tej luki: `recurring`
łapie też harmonogram w trybie stałych godzin, którego `cadence_label !== null` nigdy nie
łapał). §24.12.3's planowany plik `occurrenceDate.ts` i test 1/8 z §24.12.4 są zaktualizowane
w tych sekcjach, żeby nie kazać budować silnika, który backend już policzył.

---

#### L9 — Zasób wydarzenia nie niesie zdania o kadencji

**Status: ZAMKNIĘTA — pod INNĄ nazwą niż rekomendacja niżej, celowo.**

**Fakt (stan w chwili zgłoszenia).** `CalendarEventResource` zwracał `recurrence` (surowy
deskryptor) i `recurrence_timezone`, ale nie zwracał prozy. Zdanie („Co tydzień: wt.")
produkuje `CalendarCadenceLabel` i trafiał wyłącznie na wystąpienie, jako `cadence_label`.

**Skutek (wtedy).** Szuflada potrafiła powiedzieć, jak często powtarza się seria, tylko
wtedy, gdy otwarto ją klikiem w kafelek. Przy deep-linku (`?event=…`), po odświeżeniu
strony i w każdym przypadku z §24.8.4 (seria bez wystąpienia w oknie) nie było z czego
tego zdania wziąć.

**Rekomendacja (jak zgłoszona).** `CalendarEventResource` += `cadence_label: string|null`.

**Co faktycznie weszło: `recurrence_label`, nie `cadence_label`.** Ten dokument
rekomendował `cadence_label` na zasobie wydarzenia; backend wysłał **`recurrence_label`**.
**To nie jest niedopatrzenie — to jest lepsza nazwa, i trzeba ją tu zapisać, żeby nikt nie
zaimplementował klucza, którego na drucie nie ma:**

- `cadence_label` już **nazywa pole wystąpienia** (`CalendarOccurrenceResource`). Użycie tej
  samej nazwy na zasobie wydarzenia (`CalendarEventResource`) — innym kształcie, o innym
  źródle prawdy (jedno na kafelku z siatki, drugie w szufladzie z `GET`-a) — byłoby kolizją
  nazw między dwoma zasobami, które klient i tak trzyma w dwóch różnych typach. `recurrence_label`
  nie koliduje z niczym.
- `recurrence_label` stoi **przy `recurrence_timezone`**, z tego samego powodu, dla którego
  `recurrence_timezone` tam jest: oba opisują tę samą, już wczytaną regułę (`recurrence`) —
  jedno jej strefę, drugie jej zdanie. Sąsiedztwo w kształcie odzwierciedla sąsiedztwo
  znaczenia.

Pełny kontrakt (oba klucze `null` razem dla wydarzenia jednorazowego, oba populowane razem
dla powtarzającego się): `docs/backend/calendar-api.md` → „Resource shapes" →
`CalendarEventResource`.

**Co to zmienia w tej specyfikacji.** Każde miejsce niżej, które mówiło o „zdaniu kadencji
zapisanej reguły" jako o `cadence_label` na zasobie wydarzenia, czyta dziś
`event.recurrence_label` — poprawione w §24.6.5, §24.8.5 i §24.13 (dwa miejsca). `cadence_label`
zostaje wyłącznie polem **wystąpienia**; nigdzie indziej.

---

#### L10 — Reguła roczna renderuje się jako miesięczna — **ZAMKNIĘTA, SZERZEJ NIŻ REKOMENDACJA**

**Fakt (stan w chwili zgłoszenia).** Preset „co roku" to `day.month_days:[25]` +
`month.months:[8]`. `CalendarCadenceLabel` składał z tego `monthly_days` + `in_months`,
czyli **„Co miesiąc, dnia 25 (sierpień)"**. Zdanie było prawdziwe (kadencja miesięczna
zawężona do jednego miesiąca = raz w roku), ale czytało się jak „co miesiąc".

**Skutek (wtedy).** Najbardziej „ludzki" preset — urodziny, rocznice — dostawał na kafelku
najbardziej mylące zdanie w całym module.

**Rekomendacja (jak zgłoszona).** Jedna gałąź w `CalendarCadenceLabel`: **tylko** gdy oś
dnia to `month_days` z dokładnie jedną pozycją i oś miesiąca to `months` z dokładnie jedną
pozycją.

**Co faktycznie weszło — szerzej niż ta rekomendacja.** Kolaps do zdania rocznego obejmuje
**cztery** kształty osi dnia skrzyżowane z jednomiesięczną osią miesiąca, nie tylko
`month_days`: `month_days` (rekomendowane), oraz — dodatkowo — wszystkie trzy formy
`special` zakotwiczone w miesiącu: `last_day` („Every year on the last day of :month"),
`nth_weekday` („Every year on the :ordinal :weekday of :month") i `last_weekday` („Every
year on :weekday of :month"). Powód rozszerzenia: preset „co miesiąc: ostatni wtorek" albo
„co miesiąc: 4. wtorek", zawężony do jednego miesiąca, jest tym samym „znowu za miesiąc"
błędem co `month_days` — i żaden z dwóch nowych presetów §24.5.1 (ostatni {dzień tygodnia},
N-ty dzień tygodnia) nie miałby poprawnej rocznej formy, gdyby kolaps został wąski jak
w rekomendacji. Renderer próbuje tej gałęzi **pierwszy**, przed zwykłym dziennym/miesięcznym
renderowaniem, i tylko dla kształtów, które inaczej myliłyby — oś „codziennie" i lista dni
tygodnia nie kolapsują, bo ich słowo kadencji jest prawdziwe nawet zawężone do jednego
miesiąca („Daily, in August" nie kłamie). Pełny opis:
`docs/backend/calendar-api.md` → „The cadence sentence's yearly form."

**Konsekwencja dla presetów §24.5.1.** Oba nowe presety zaproponowane w tej specyfikacji
(„ostatni {dzień tygodnia}", „N-ty dzień tygodnia") mają teraz **też** poprawną roczną formę,
nie tylko preset „co roku" zbudowany z `month_days` — kontrolka może bezpiecznie oferować
„co roku" nad każdym z nich, nie tylko nad prostym „dnia N".

**Priorytet: niski dla poprawności, wysoki dla odbioru — i teraz zamknięty.** Wpis w
`lang/{pl,en}/calendar.php` (`yearly_days`, `yearly_last_day`, `yearly_nth_weekday`,
`yearly_last_weekday`, plus katalog `months_in_date` w dopełniaczu dla polskiego) już
istnieje.

---

#### L11 — „N razy" nie wraca jako „N razy"

**Fakt.** Zapis przyjmuje `recurrence.count` (1..366) **albo** `recurrence.until`, nigdy
oba; serwer rozwiązuje `count` na datę **raz, przy zapisie**, a zasób zwraca wyłącznie
`until`.

**Ocena.** To jest **poprawne i zamierzone** — trzymanie licznika oznaczałoby liczenie
kadencji przy każdym odczycie i mnożenie tego przez każde okno, przez które użytkownik
przewinie. Zgłaszam jako lukę **projektową, nie defekt**: kontrolka końca musi to powiedzieć
**z góry** (§24.5.3), bo inaczej użytkownik zapisze „10 razy", otworzy ponownie, zobaczy datę
i uzna, że coś się nie zapisało.

**Nic do zrobienia po stronie backendu.** Zapisane, żeby nikt nie „naprawił" tego lokalnym
licznikiem w draftcie formularza — rozjechałby się z bazą przy pierwszej edycji z innego
miejsca.

---

#### L12 — Kontrolka jest węższa niż API i musi to udźwignąć

**Fakt.** `CalendarRecurrence` przyjmuje m.in. wiele dni tygodnia, wiele dni miesiąca, listę
miesięcy i `last_weekday` bez warunku. Presety z §24.5 produkują **podzbiór** tego zbioru.
Reguła spoza presetów może powstać z API, z konsoli albo z przyszłej, szerszej kontrolki.

**Ocena.** To nie jest luka backendu — to konsekwencja świadomego zwężenia. Zgłaszam ją, bo
**wymusza stan interfejsu**, o którym łatwo zapomnieć: „Inna reguła" (§24.8.5), z zapisem
przenoszącym deskryptor bajt w bajt. Bez tego stanu pierwsza edycja tytułu takiej serii
**po cichu przepisałaby regułę** na najbliższy preset — czyli dokładnie ten rodzaj milczącej
straty, przeciw któremu ustawiony jest cały ten moduł.

**Nic do zrobienia po stronie backendu.**

---

#### L13 — Zapis całej serii przestemplowuje strefę reguły

**Fakt.** `CalendarRecurrenceService::descriptor()` stempluje `tz` **bieżącą** strefą
workspace'u przy **każdym** zapisie. Zapis całego wydarzenia (`scope=series`) buduje
deskryptor od nowa, więc seria utworzona w `Europe/Warsaw` i zapisana po zmianie strefy
workspace'u na `America/New_York` **zmienia zegar swojej reguły** — a razem z nim dzień,
którym nazywa się jej wystąpienia.

**Ocena.** Zachowanie jest **spójne z doktryną modułu** (pojedyncze wydarzenie z godziną też
przesuwa się na siatce po zmianie strefy, bo trzyma absolutny instant) i nie jest defektem.
Ale jest **niewidoczne**, a ma skutek po stronie klienta: `recurrence_timezone`, którym
frontend liczy `occurrence_date`, potrafi zmienić się **w wyniku zapisu, którego użytkownik
nie łączy ze strefą**.

**Rekomendacja:** żadna zmiana w kodzie — **zrealizowana**. Wymóg dla frontendu jest
w §24.6.6 (po zapisie `scope=series` czytać `recurrence_timezone` **z odpowiedzi**, nigdy
z pamięci) i to wystarcza. To zdanie trafiło do `docs/backend/calendar-api.md`, sekcja
„A whole-series write re-stamps the clock" pod „Concepts" — z rozbiciem na skutek dla
serii z godziną (przesuwa się) i całodniowej (nie przesuwa się, bo projekcja dnia nigdy nie
przechodzi przez strefę).

---

#### Uwaga (NIE luka kontraktu): dokumentacja backendu nie opisuje powierzchni zapisu B4 — **NIEAKTUALNA, ZAMKNIĘTA**

Stan w chwili zgłoszenia: `docs/backend/calendar-api.md` sam o tym mówił w nagłówku:
*„(`recurrence`, `recurrence_until`, `scope`/`occurrence_date` na `PUT`/`DELETE` — not yet
documented on this page)"*. Sekcje `POST`/`PUT`/`DELETE` nie wymieniały ani `recurrence`,
ani `scope`, ani `occurrence_date`, a przykład `CalendarEventResource` nie zawierał
`recurrence` ani `recurrence_timezone`.

**Domknięte.** `docs/backend/calendar-api.md` opisuje dziś pełną powierzchnię zapisu: blok
`recurrence` (dozwolony podzbiór, każdy odrzucany klucz, reguła kotwicy, reguła
niepustej serii) w sekcji „Recurrence on write", trzy wartości `scope` z tym, co każda
zwraca i jakim kodem, w sekcji „Scope", oraz w pełni rozpisane `POST`/`PUT`/`DELETE`
z przykładem na każdy kształt żądania. Nie zajrzy już tam frontend i nie zobaczy
kontraktu sprzed serii wyglądającego na kompletny.

---

### 24.16 Handoff do frontend-agent

#### UX Goal

Zamienić serię z rzeczy, którą da się **tylko** zepsuć w całości, w rzecz, którą da się
edytować **na trzy sposoby, każdy nazwany, zanim się go wybierze**. Użytkownik ma wiedzieć,
w co klika, przed kliknięciem — a w chwili wyboru rozumieć, **co się stanie z przeszłością**.

#### User Flow

1. Siatka → kafelek z glifem serii → szuflada w trybie podglądu (`?event=` + `on`/`at`).
2. Podgląd pokazuje: wybrane wystąpienie, kadencję (proza serwera), początek, koniec,
   pominięte dni, ewentualnie strefę reguły.
3. **Edytuj** → dialog zakresu (3 opcje, każda ze zdaniem o skutku, przycisk nazywa wybór).
4. Formularz zasiany **zgodnie z zakresem** + stały banner zakresu z „Zmień zakres".
5. Zapis → toast nazywający zakres → szuflada zamyka się → siatka odświeża okno.
6. **Usuń** → ten sam dialog w trybie `danger` → `DELETE` z zakresem → toast → refetch.
7. Tworzenie: formularz + kontrolka powtarzania z presetami wyprowadzonymi z wybranej daty.

#### Screen Structure

Bez nowych ekranów i bez nowych tras. Trzy nowe powierzchnie: **dialog zakresu** (`Modal`
nad `Drawer`), **kontrolka powtarzania** (w formularzu), **blok serii** (w podglądzie).
Siatka zyskuje **jeden glif** na kafelku.

#### Components Needed

Reuse: §24.12.1. Extend: `icons.ts` (+`layers`), `types.ts`, store, chip, szuflada, widok
(§24.12.2). Create: `SeriesScopeModal.vue`, `RecurrenceField.vue`, `recurrencePresets.ts`,
opcjonalnie `occurrenceDate.ts` jako trywialny getter (§24.12.3 — L8 zamknięta, backend
liczy `occurrence_date` sam). **Zero nowych zależności npm.**

#### States

Ładowanie (dialog usuwania: `Button loading`, **opcje pozostają aktywne — NIE `readonly`,
AS-BUILT §24.17 poz. 5, zgodnie z §24.4.5**: jedyny realny błąd tego dialogu,
`exclusions_full`, wskazuje jako lekarstwo inną opcję tego samego dialogu, więc blokowanie
opcji zablokowałoby lekarstwo — podwójne kliknięcie blokuje guard wewnątrz `onConfirm`, nie
stan `disabled` na `RadioGroup`), odmowa walidacji na polach reguły (§24.8.3, mapa ścieżek
422 w §24.2.4),
**seria bez wystąpienia w oknie** (§24.8.4 — z warunkiem „źródło było zapytane"), **reguła
spoza kontrolki** (§24.8.5), błąd w dialogu usuwania (`exclusions_full` — §24.4.5); pusto
i błąd całości bez zmian (§15).

#### Responsive Rules

§24.9: poniżej `next-md` znacznik serii jest plakietką z kadencją (agenda ma miejsce),
kontrolki układają się w kolumnie, dialog ma cele dotykowe ≥ 44 px i potwierdzenie na dole.

#### Accessibility

§24.10. **Nadrzędne: nie dotykać klawiatury siatki** — żadnego nowego punktu tabulacji
w `role="grid"`, żadnej zmiany mapy klawiszy. Dialog: focus na zaznaczonej opcji, powrót na
wyzwalacz, `Esc` tylko na wierzchnim (`useOverlayStack`). Etykieta przycisku zawiera nazwę
wybranej opcji. Błędy reguły przez `FormField`/`aria-invalid`, nie własnym `<p>`.

#### Copy / Microcopy

§24.13. Twarde: **zdanie o kadencji zapisanej reguły pochodzi z serwera** — `cadence_label`
na wystąpieniu, `recurrence_label` na zasobie wydarzenia (**dwa różne pola, nie jedno** — L9,
§24.15) — i nie składa się go z części; etykiety presetów opisują **zamiar**, nigdy stan zapisany;
każda opcja zakresu mówi, **co się stanie z przeszłością**; „N razy" z góry uprzedza, że
wróci jako data; nic nie obiecuje przywracania.

#### Tailwind / Design Tokens

Wyłącznie `next-*`. Nowe: `Badge warning subtle` dla próbki (ton wiąże ją z `Alert warning`
komunikatu `item_densified`), glif `repeat` `opacity-60`/`70` dla serii, `Alert info` dla
bannera zakresu. Zero `bg-[#…]`, zero klas budowanych dynamicznie. Punkty do sprawdzenia
w dark mode: §24.11.

#### Frontend Handoff

Kolejność: **(1)** `recurrencePresets.ts` (czysty, testowalny, niesie wszystkie pułapki —
`occurrenceDate.ts` odpadł z tego punktu: L8 zamknięta, backend liczy `occurrence_date` sam)
→ **(2)** `types.ts` + store (`scope`, `occurrence_date`, przeniesienie `recurrence`) →
**(3)** `SeriesScopeModal.vue` → **(4)** szuflada: banner + zasiew per zakres + blok serii →
**(5)** `RecurrenceField.vue` → **(6)** chip: rozdzielenie dwóch znaczników.
Punkty 3 i 4 **domykają defekt z §24.0**; 5 dokłada tworzenie serii z UI.

#### Consistency Risks

1. **Zapis bez `scope`.** Serwer czyta to jako **całą serię**. Domyślna wartość zakresu
   w store'ie musi być jawna, nie wywnioskowana z `undefined`.
2. **`PUT` bez `recurrence`.** Kasuje regułę. Bez `exclusions.dates` — wskrzesza usunięte dni.
3. **`occurrence_date` liczone w `meta.timezone`.** Ma być w `recurrence_timezone` (§24.3).
4. **Parsowanie `Y-m-d` z `id` wystąpienia.** Backend wprost odradza; do zamknięcia L8
   obowiązuje wyprowadzenie z pól.
5. **Zasiew formularza niezgodny z zakresem.** Kotwica pod `occurrence` = zgubiony dzień
   i duplikat; wystąpienie pod `series` = ucięta przeszłość (§24.6.1).
6. **Jeden glif na dwa fakty.** `repeat` = seria, `layers` = próbka. Nigdy odwrotnie, nigdy
   oba naraz w siatce (§24.7).
7. **Złożenie zdania o kadencji po stronie klienta.** Zakaz; proza jest serwera (L9).
8. **Ciche przepisanie reguły spoza kontrolki.** Musi być „Inna reguła" (§24.8.5).
9. **Trzymanie licznika „N razy" w draftcie.** Wraca data, nie liczba (L11).
10. **Rozszerzanie `app/lib/api.ts` o dostęp do statusu.** Niepotrzebne: `201` ⇔ inne `id`
    w ciele (§24.2.3).
11. **Nowy punkt tabulacji w siatce.** Klawiatura siatki jest sprawna i nie wolno jej
    zepsuć (§24.10).
12. **Komunikat „ta seria nie ma tu wystąpień" bez sprawdzenia filtra i `unavailable_sources`.**
    „Nie pytaliśmy" i „nie ma" to dwa różne zdania (§14, §24.8.4).

---

### 24.17 Rozjazdy specyfikacji z implementacją

> Zgłoszone przez frontend **po** zbudowaniu B6 (frontend zielony: **3277 testów / 274
> pliki**, zero nowych zależności; backend 3273/0/13) — dziewięć miejsc, w których to, co
> napisano wyżej, i to, co powstało, się rozminęły. **W każdym wygrał kod**, z uzasadnieniem
> zapisanym tu i skrzyżowanym z sekcją, którą dotyczy. Odwrotny kierunek niż §24.15: tam
> kontrakt backendu nie miał czegoś, czego specyfikacja chciała; tu specyfikacja **miała**
> zdanie, a zbudowany kod — słusznie — powiedział co innego. Poprawki **w miejscu** noszą
> znacznik „AS-BUILT — patrz §24.17 poz. N"; ten rejestr jest drugą, zebraną kopią tego
> samego faktu, nie jedynym miejscem, w którym żyje.

**1 — Identyfikator wystąpienia w adresie to data z serwera, nie chwila startu.**
*Specyfikacja (przed tą poprawką, §24.3.1) mówiła:* dwa wykluczające się klucze URL, `on`
dla serii całodniowej i `at` dla serii z godziną, z regułą rozstrzygania konfliktu, gdy oba
przyjdą naraz. *Co faktycznie powstało:* `on` jest **jedynym** identyfikatorem, zawsze
`occurrence.occurrence_date`, niezależnie od `all_day`; `at` to pole **pomocnicze** —
`occurrence.starts_at`, obecne DODATKOWO dla wystąpienia z godziną, i nie identyfikuje
niczego samo. *Dlaczego kod wygrał.* Skoro `occurrence_date` przychodzi z serwera gotowe
(L8, §24.15 — zamknięta, zanim ta sekcja powstała), nie ma już dwóch sposobów nazwania
jednego dnia do rozstrzygania — `on` nazywa go zawsze tym samym kluczem. Reguła konfliktu
„oba klucze naraz → oba ignorowane" opisywała stan, który w zbudowanym kodzie **nie
istnieje**: `on` i `at` normalnie współistnieją na URL-u wystąpienia z godziną, bo to
zwykła para identyfikator+chwila, nie dwie konkurujące etykiety tego samego dnia. Planowany
moduł wyprowadzający datę z instantu i strefy **nie powstał i nie powinien** — L8 już to
rozstrzygnęła, ta poprawka tylko domyka wniosek w warstwie URL-a, gdzie wcześniej został
przeoczony. Poprawione w miejscu: §24.3.1.

**2 — Preset ostatniego dnia miesiąca oferowany tylko wtedy, gdy kotwica jest ostatnim
dniem swojego miesiąca.** *Specyfikacja* wymieniała „Co miesiąc, ostatniego dnia" jako
zwykły wiersz stałej tabeli presetów, obecny zawsze. *Co faktycznie powstało:*
`presetsFor()` dopisuje ten preset warunkiem `dayOfMonth === lengthOfMonth` — dla każdej
innej daty preset **nie pojawia się w liście wcale**. *Dlaczego kod wygrał.* Fakt 1 tego
modułu (§24.5.2): kotwica musi być pierwszym wystąpieniem reguły, bo inaczej serwer odrzuca
zapis na polu początku (`anchor_not_an_occurrence`) — polu, którego użytkownik nie kojarzy
z wyborem w kontrolce powtarzania. Zaoferowanie tego presetu szerzej niż dla dni, które go
faktycznie spełniają, oznaczałoby **gwarantowaną odmowę serwera przy pierwszym zapisie**
dla każdej innej daty — dokładnie tej klasy błąd, który cały mechanizm re-derywacji presetu
(§24.5.2) istnieje, żeby uczynić nieosiągalnym. Poprawione w miejscu: §24.5.1 (trzecie
zachowanie warunkowe), §24.5.2 (mapowanie na `monthlyDay` przy zmianie daty — patrz poz. 7).

**3 — Osobne katalogi tłumaczeń dla każdego dnia tygodnia, nie szablon z podstawieniem.**
*Specyfikacja* (katalog i18n, §24.13) proponowała `calendar.recurrence.weekly = "W każdy
{weekday}"` i `calendar.recurrence.monthlyLastWeekday = "Co miesiąc: ostatni {weekday}"` —
po jednym kluczu z podstawianą nazwą dnia. *Co faktycznie powstało:* siedem osobnych
wpisów katalogowych na preset — `calendar.recurrence.weeklyDays.0..6` i
`calendar.recurrence.monthlyLastWeekdays.0..6` — każdy pełną, odmienioną frazą. *Dlaczego
kod wygrał.* Polski odmienia przez rodzaj: „W każdy wtorek", ale „W każdą środę" — jeden
szablon z podstawieniem nazwy dnia nie da poprawnej frazy dla wszystkich siedmiu dni
naraz, bo przedimek/zaimek przed nazwą zależy od rodzaju TEJ nazwy. To jest **ten sam
argument**, którym serwerowy `lang/{pl,en}/calendar.php` broni własnego katalogu
(ADR-0051 D12) — zapisany tu raz, w miejscu, gdzie ktoś przy kolejnej edycji mógłby się
skusić na „uproszczenie" z powrotem do jednego klucza z placeholderem. Kontrastowo,
`calendar.recurrence.monthlyNth` ("Co miesiąc: {ordinal} {weekday}") **zostaje**
szablonem — liczebnik przed nazwą dnia omija problem odmiany („4. wtorek", „4. środa" —
obie formy nominalne poprawne), więc nazwa dnia w tej jednej pozycji może bezpiecznie
pochodzić z `Intl.DateTimeFormat(locale, { weekday: 'long' })` zamiast z katalogu.
Poprawione w miejscu: §24.5.1, §24.13.

**4 — Data roczna formatowana lokalnie przez `Intl`, nie składana z serwerowego katalogu
miesięcy powielonego u klienta.** *Specyfikacja* niosła
`calendar.recurrence.yearly = "Co roku, {day} {month}"` — dwa osobne tokeny, co zakłada
klienta znającego nazwę miesiąca w dopełniaczu („25 **sierpnia**", nie „25 **sierpień**"),
czyli własny katalog miesięcy odmienionych. *Co faktycznie powstało:* jeden token,
`calendar.recurrence.yearly = "Co roku, {date}"`, gdzie `{date}` to gotowy napis z
`dayMonthLabel()` (`Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long' })`).
*Dlaczego kod wygrał.* Backend już ma dokładnie ten katalog dla WŁASNEJ prozy
(`cadence.months_in_date`, ADR-0051 D12, w dopełniaczu, bo polska data tego wymaga) —
powielenie go po stronie klienta, żeby złożyć etykietę PRESETU (nie zdanie zapisanej
reguły — te dwa nigdy się nie mieszają, §24.13), byłoby drugą definicją tego samego
faktu językowego, z gwarancją rozjechania się przy dodaniu kolejnego języka. `Intl` już
zna gramatykę każdego locale, jakie ta aplikacja kiedykolwiek włączy, za darmo. Poprawione
w miejscu: §24.5.1, §24.13.

**5 — Opcje w dialogu usuwania zostają aktywne, mimo że specyfikacja (w dwóch miejscach)
kazała je zablokować.** *Specyfikacja* była **wewnętrznie sprzeczna**: §24.4.5 (analiza
błędu `exclusions_full`, czyli faktyczna decyzja) już mówiła „pozostałe dwie opcje zostają
aktywne", ale dwa inne miejsca tego samego dokumentu — tabela stanów w §24.8.1 i streszczenie
w §24.16 Handoff — powtarzały `opcje readonly` podczas ładowania. *Co faktycznie
powstało:* `SeriesScopeModal.vue` nie wiąże żadnego `disabled`/`readonly` na `RadioGroup`
— tylko przycisk „Anuluj" i przycisk potwierdzenia reagują na `busy`; podwójne kliknięcie
jest zablokowane wewnątrz `onConfirm` (`if (props.busy) return`), nie przez usunięcie
kontrolek z drzewa. *Dlaczego kod wygrał — i dlaczego to nie był przypadek.* Jedyny realny,
częsty błąd tego dialogu (`exclusions_full` — seria ma już 50 pominiętych dni) **wskazuje
jako lekarstwo inną opcję tego samego dialogu**: podziel serię (`following`/`series`)
zamiast pomijać kolejne wystąpienia. Zablokowanie opcji zablokowałoby jedyną drogę do
lekarstwa, które serwer właśnie nazwał — użytkownik zobaczyłby błąd i kontrolki, które nie
pozwalają nic z nim zrobić poza zamknięciem dialogu i zaczęciem od nowa. Kod podążył za
głębszym, poprawnym uzasadnieniem (§24.4.5) i zignorował dwa płytsze, sprzeczne
streszczenia napisane gdzie indziej w tym samym dokumencie — dowód, że jedna poprawna
sekcja nie chroni przed dwiema złymi kopiami tego samego faktu w innych rejestrach stanów.
Poprawione w miejscu: §24.8.1, §24.16 (Handoff → States).

**Pozostałe cztery — mniejsze, ale każdy odtworzyłby się inaczej, gdyby ten dokument o nim
milczał:**

| # | Rozjazd | Co faktycznie powstało | Gdzie poprawione |
| --- | --- | --- | --- |
| 6 | Kształt pola liczby pominiętych dni | **Sama liczba** (`skippedDays.length`), z pełną listą dat w atrybucie `title` (natywny tooltip) — nie lista rozwijana ani chipy do usuwania. Spójne z faktem 4 kontrolki powtarzania („`exclusions` jest NIESIONE, nigdy AUTOROWANE" — rośnie wyłącznie przez „usuń to wystąpienie" po stronie serwera): interaktywna lista sugerowałaby mutację, której ten formularz nie wykonuje | §24.6.5 (już zgodne — `EventDrawer.vue`, wiersz `skipped`/`skippedDays`) |
| 7 | Mapowanie presetu ostatniego dnia na zwykły dzień | `remapPreset()`'s `fallbackId` mapuje `monthlyLastDay → monthlyDay` (na nowym dniu miesiąca), gdy data przesuwa się poza koniec miesiąca — to samo traktowanie co para `monthlyNth ↔ monthlyLastWeekday` przy piątym tygodniu, dotąd jedyną udokumentowaną krawędzią tej funkcji | §24.5.2 (poz. 7 wyżej) |
| 8 | Scalenie bloku końca serii w jedno pole z jednym slotem błędu | `RadioGroup` + widżet wartości pod **jednym** `FormField`, `error` = `until ?? count ?? exclusions.dates` (pierwszy niepusty) | §24.5.3 |
| 9 | Blok wykluczeń nie miał się gdzie renderować przy braku daty końca | Powód poz. 8: `series_has_no_occurrences` na `recurrence.exclusions.dates` potrafi wrócić dla serii BEZ końca, a kontrolka nie ma osobnego pola na wykluczenia — bez scalenia z poz. 8 ten komunikat nie miałby pod czym wylądować | §24.5.3 |

**Odpowiedź na pytanie, które to rundy pilnują: czy zostaje jakakolwiek instrukcja, którą
agent wykonałby błędnie?** Jedna klasa, i ta runda ją zamyka — w **dwóch** miejscach naraz:
§24.8.1 (tabela stanów) i §24.16 Handoff → States nadal kazały blokować opcje dialogu
usuwania (poz. 5), wprost sprzecznie z analizą tego samego błędu w §24.4.5. Agent budujący
od zera z samej tabeli stanów albo samego Handoffu, bez uważnego czytania §24.4.5, zbudowałby
kontrolę, która blokuje własne lekarstwo — i zrobiłby to niezależnie od tego, które z dwóch
złych miejsc przeczytał pierwsze, co samo w sobie było ostrzeżeniem, że to nie literówka w
jednym zdaniu, tylko powielony błąd. Poza tą jedną klasą żadna z pozostałych ośmiu
rozbieżności nie była **instrukcją prowadzącą na manowce** — §24.3.1's stara reguła
konfliktu (poz. 1), brak warunku na presecie ostatniego dnia (poz. 2) i brakujące notatki
o katalogach/`Intl` (poz. 3, 4) były **niedopowiedzeniami** (kod, który by z nich wprost
wynikał, byłby gorszy lub niemożliwy do zbudować zgodnie z resztą dokumentu —
anchor_not_an_occurrence, odmiana przez rodzaj — więc agent uważnie
czytający CAŁĄ specyfikację, nie tylko jedną tabelę, trafiłby na sprzeczność i zatrzymał się
pytaniem), nie cichą pułapką jak poz. 5.

---

**Druga runda, po B6 — nie rozjazd specyfikacji z kodem zbudowanym z niej, tylko OWNER
odwracający wcześniej zapisaną, świadomą decyzję po obejrzeniu modułu na żywo.** Inny rodzaj
zdarzenia niż poz. 1-9 (tam kod, budowany z tej specyfikacji, powiedział co innego niż
zapisane zdanie; tu zapisane zdanie było zbudowane dokładnie tak, jak specyfikacja kazała —
i słowo właściciela je unieważniło), ale ten sam mechanizm rejestru, z tego samego powodu:
żeby następny czytelnik nie natrafił na dwie sprzeczne wersje faktu bez wskazówki, która
wygrywa.

**10 — Kontrolka powtarzalności wydarzenia i edytor harmonogramu wyzwalacza przepływu są
dziś TYM SAMYM komponentem; §24.5.4 świadomie odmówiło tego reużycia.** *Specyfikacja
(§24.5.4, ostatni wiersz) mówiła:* "Kreatora harmonogramu z edytora workflow" **nie ma** w
kontrolce Kalendarza — "technicznie dałoby się, kontrakt jest wspólny", ale reużycie
"wystawiłoby w oknie 'powtórz to spotkanie' tryby 'co 5 minut', 'ostatni dzień roboczy', okna
godzinowe i asystenta AI — słownictwo automatyzacji w oknie o spotkaniu"; "osobne, wąskie
presety to nie duplikat: to inny profil tej samej gramatyki". *Co faktycznie powstało
(commit `62a73e4`, 2026-08-26):* owner obejrzał moduł na żywo i **zdecydował inaczej** —
wybór powtarzania w szufladzie wydarzenia ma być tym samym komponentem, co w edytorze
przepływu. `RecurrenceAxisEditor.vue` (+ sześć towarzyszących plików) przeniesiony do
`ui/recurrence/` — wspólnego domu obu stron, żyjącego pod `ui/**`, które (test
`uiLayerImportBoundary.spec.ts`) **nie może** importować z `pages/**` w żadną stronę; obie
strony (`pages/calendar/RecurrenceField.vue`, `pages/workflows/WorkflowScheduleBuilder.vue`)
importują W DÓŁ do niego, co jest jedynym dozwolonym kierunkiem. *Dlaczego kod wygrał — i co
z uzasadnienia odmowy PRZETRWAŁO.* Obawa zapisana w §24.5.4 była **słuszna w chwili
napisania i pozostaje słuszna dziś** — nie została odrzucona, tylko rozwiązana **inaczej**,
niż odmowa reużycia sugerowała jako jedyną opcję. Odpowiedzią okazał się **PROFIL**, dokładnie
ten sam mechanizm, którym `CalendarRecurrence` jest podzbiorem `App\Support\Recurrence` po
stronie serwera (ADR-0052) — teraz odtworzony w UI: `RecurrenceAxisEditor` przyjmuje
`profile: RecurrenceProfile` (`ui/recurrence/recurrenceAxes.ts`), a `CALENDAR_RECURRENCE_PROFILE`
**ukrywa całą zakładkę Czas** (serwer sam autoryzuje godzinę wystąpienia — `recurrence.time`
jest `prohibited`), tryby modulo (`every_n_days`/`every_n_months` — siatka resetowana co
miesiąc i co rok, więc "co 5 minut"/"co N dni" nigdy nie trafiają do okna o spotkaniu) i
`last_working_day`. Asystent AI i pasek podglądu odpaleń **nie przeniosły się w ogóle** — to
były zawsze sprzężenia ze store'em Workflows (endpoint asystenta, endpoint podglądu), więc
zostały w `WorkflowScheduleBuilder.vue`, który OTACZA wspólny edytor, dokładnie tak jak
`RecurrenceField.vue` otacza go po stronie Kalendarza. Profil jest **czytany z kontraktu
backendu** (`CalendarRecurrence::dayModes()`/`daySpecials()`/`monthModes()`), nie przepisany z
pamięci — łącznie z tym, że parametry przypadków specjalnych są per rodzaj (`last_day` nie
bierze żadnych; spory parametr to `field_not_allowed_for_mode`, fakt 2 nagłówka
`calendarRecurrence.ts`). **Konsekwencje dla trzech innych wierszy §24.5.4**, odwrócone tym
samym commitem: "Trybu 'własne'" i "Wielu dni tygodnia naraz" — poprawione w miejscu, §24.5.4.
**Konsekwencja dla "Innej reguły"** (dawne §24.8.5): ciasnota słownika, która czyniła
`weekdays: [1, 3]` nieedytowalną regułą, zniknęła — edytor wyraża każdą regułę, którą
endpoint przyjmuje (round-trip po dziewięciu kształtach deskryptora, `calendarRecurrence.spec.ts`).
Stan `unsupported` przeżywa **wyłącznie** jako strażnik deskryptora spoza profilu —
nieosiągalnego którąkolwiek dzisiejszą ścieżką zapisu, zachowany na wypadek ręcznie
edytowanego wiersza albo backendu, który poszerzy gramatykę wcześniej niż ten frontend.
Poprawione w miejscu: banner na początku §24.5, §24.5.1, §24.5.4, §24.8.5, §24.12.3.

**11 — "Ostatniego dnia miesiąca" przestało cicho zamieniać się w "dnia N", gdy data
przesuwa się poza koniec miesiąca; dziś blokuje zapis błędem kotwicy.** *Poz. 7 wyżej
opisywała `remapPreset()`'s `fallbackId`:* `monthlyLastDay` mapowało się na `monthlyDay` (na
nowym dniu miesiąca) automatycznie, tym samym traktowaniem co para `nth_weekday`↔`last_weekday`
przy piątym tygodniu. *Co faktycznie powstało:* tylko POŁOWA tego zachowania przeżyła.
`seedDay('weekday_in_month', anchorDay)` wciąż przełącza się między `nth_weekday` i
`last_weekday` przy zmianie kotwicy — ta część poz. 7 jest nadal prawdziwa, bez zmian.
Ale `seedDay('last_day', anchorDay)` **ignoruje** `anchorDay` całkowicie i zawsze zwraca tę
samą wartość: karta „Last day of the month" **nazywa** regułę, zamiast ją **wyprowadzać**
z konkretnego dnia. *Dlaczego kod wygrał.* Dopóki jedynym sposobem uzyskania reguły był
preset, "ostatni dzień miesiąca" i "dnia 31" były różnymi PRESETAMI z różnymi warunkami
pojawienia się na liście (§24.5.1) — więc "ten preset przestał pasować, podstaw najbliższy"
miało sens jako pojedyncza, spójna operacja. Dziś "Last day of the month" jest KARTĄ, którą
użytkownik wybiera świadomie i która ma jedno, ustalone znaczenie niezależnie od kotwicy —
podmiana jej w locie na inny sub-tryb, gdy data przesunie się o jeden dzień, byłaby dokładnie
tym rodzajem cichej rewriteʼy reguły, którego ta kontrolka (fakt z nagłówka
`RecurrenceField.vue`) unika wszędzie indziej: "reguła, którą ktoś świadomie ułożył, nigdy nie
jest po cichu przepisywana". Sprawdzone testem: `calendarRecurrence.spec.ts`, opis „the anchor
rule" wyklucza jawnie `last_day` z pętli „każdy seed spełnia dzień, z którego powstał", bo
`last_day` **nazywa** regułę, a nie ją wyprowadza. Poprawione w miejscu: §24.5.2.

**Odpowiedź na pytanie tej rundy: czy po niej w specyfikacji zostaje jakakolwiek instrukcja,
którą agent wykonałby błędnie?** Największe ryzyko było nie w treści, tylko w KOLEJNOŚCI: bez
banneru na początku §24.5 i bez przekreśleń w §24.5.4, agent czytający ten rozdział od góry —
zwłaszcza tylko §24.5.4, tak jak poz. 5 ostrzega, że się zdarza — powtórzyłby dokładnie tę
samą odmowę reużycia, którą owner właśnie unieważnił, i zbudowałby DRUGI, wąski komponent
zamiast rozszerzyć profil na wspólnym. Oba mechanizmy (banner + przekreślenia z odsyłaczem do
poz. 10) są teraz w miejscu, gdzie agent na nie trafi, zanim dotrze do starej treści.

**Domknięcie (ten sam commit).** Jedyna rzecz zostawiona wtedy świadomie otwarta — §24.13
(katalog kluczy i18n) nieprzeliczony po przenosinach `workflows.schedule.* →
recurrenceEditor.*` — jest teraz domknięta: `calendar.recurrence.*` wyprowadzony na nowo
klucz po kluczu z obu katalogów (§24.13, banner + kod), słownik presetów usunięty z
dokumentu tak jak z kodu, i dopisany akapit tłumaczący, że karty/zakładki edytora osi żyją
pod OSOBNYM, współdzielonym namespace'em (`recurrenceEditor.*`), katalogowanym w
`WorkflowsPage.vue`, nie tutaj — więc nie ma już nazwy w tym pliku, którą ktoś skopiowałby
i trafił na klucz, którego katalog nie zna.
