# Moduł Publikacje (Publishing, R4) — specyfikacja UX/UI

> **Batch B7** rozdziału R4. Dokument jest kontraktem dla `frontend-agent` (**B8**).
>
> Powstał **po** zacommitowanym backendzie B1–B3 i **przeciwko** jego rzeczywistemu
> kontraktowi — nie obok niego. Każde pole opisane niżej ma pokrycie w kodzie
> `app/modules/Publishing/`, w `docs/backend/publishing-api.md`, w ADR-0054 i ADR-0055.
> **Nic tutaj nie jest zmyślonym kluczem payloadu** (znany błąd Etapu 5: agenci frontendu
> wymyślali pola, których API nie miało).
>
> **Rzeczy, których kontrakt nie ma, a UI ich potrzebuje, są zgłoszone jako luki** — i **nie
> zostały po cichu dopisane do specyfikacji**. Luk jest jedenaście; trzy z nich (**L2**,
> **L3**, **L5**) zmieniają to, co ekran w ogóle może obiecać użytkownikowi, i są opisane
> w miejscu, w którym uderzają. Zbiorcza sekcja „18. Luki kontraktu" **przepadła wraz
> z końcówką dokumentu** — patrz [Aneks powykonawczy (B8)](#aneks-powykonawczy-b8).
>
> **Frontendu tego modułu nie ma dziś w ogóle.** Wszystko poniżej jest nowe, poza tym,
> co jawnie wskazano jako reuse w [§16](#16-inwentarz-komponentów-reuse--extend--create).
>
> **Nic w tym dokumencie nie jest logiką biznesową ani kontraktem backendu.**

---

## Spis treści

1. [Fundamenty](#1-fundamenty)
2. [Kontrakt backendu — pełny inwentarz](#2-kontrakt-backendu--pełny-inwentarz)
3. [Architektura informacji, trasy, stan w URL](#3-architektura-informacji-trasy-stan-w-url)
4. [Ekran A — lista publikacji](#4-ekran-a--lista-publikacji)
5. [Karta publikacji](#5-karta-publikacji)
6. [Ekran B — kompozytor (szuflada)](#6-ekran-b--kompozytor-szuflada)
7. [Ekran C — szczegół publikacji, siedem wariantów](#7-ekran-c--szczegół-publikacji-siedem-wariantów)
8. [`needs_reconcile` — najważniejszy ekran modułu](#8-needs_reconcile--najważniejszy-ekran-modułu)
9. [Ekran D — Połączenia](#9-ekran-d--połączenia)
10. [Powrót z OAuth — czternaście kodów](#10-powrót-z-oauth--czternaście-kodów)
11. [Akceptacje (ZALEŻNE od B6)](#11-akceptacje-zależne-od-b6)
12. [Stany: ładowanie, pusto, błąd, sukces](#12-stany-ładowanie-pusto-błąd-sukces)
13. [Kolory, tokeny, dark mode](#13-kolory-tokeny-dark-mode)
14. [Responsywność](#14-responsywność)
15. [Dostępność](#15-dostępność)
16. [Inwentarz komponentów](#16-inwentarz-komponentów-reuse--extend--create)

*(Spis odpowiada temu, co w dokumencie **jest**. Sekcje 17–21 zapowiadane w pierwotnym planie
nie istnieją — patrz [Aneks powykonawczy (B8)](#aneks-powykonawczy-b8).)*

---

## 1. Fundamenty

### 1.1 Słownik (PL ↔ dane)

| PL (UI) | EN (kod / pole) | Co to jest |
| --- | --- | --- |
| Publikacja | publication | Zamiar wysłania treści w świat. `PublicationResource`. |
| Miejsce docelowe | platform | `youtube`, `instagram`, `facebook`, `dry_run`. **Nie „platforma"** w UI — patrz D2. |
| Próba | `dry_run` | Miejsce docelowe, które **nic nie publikuje**. Etykieta serwera mówi to wprost. |
| Konto | platform connection | Połączone konto, na które idzie publikacja. `PlatformConnectionResource`. |
| Zaplanowanie / uzbrojenie | schedule / arm | `draft \| failed \| blocked → scheduled`. **Jedyne** przejście, które człowiek robi po HTTP (obok sprawdzenia). |
| Termin | `scheduled_at` | **CHWILA** (UTC na drucie), nigdy dzień. Bezstrefowy string jest czytany na zegarze **workspace'u**. |
| Do sprawdzenia | `needs_reconcile` | **Nie wiemy, czy post istnieje.** Nie „nieudana", nie „ponawianie". §8. |
| Wstrzymana | `blocked` | Kolejka trzymana przez zepsute konto. Z samą publikacją wszystko jest w porządku. |
| Sprawdzenie na platformie | reconcile | Pytanie do platformy „czy to istnieje". **Czyta, nie pisze w świat.** |
| Flaga możliwości | capability flag | `can_be_edited / _deleted / _scheduled / _reconciled`. **Jedyna** bramka afordancji. |
| Ton | `status_tone` | Znaczenie koloru wybrane przez serwer. Frontend mapuje je na wariant `Badge`, nigdy nie wybiera własnego. |

### 1.2 Doktryna, która musi być słyszalna w interfejsie

Cały moduł stoi na jednym zdaniu z ADR-0055: **podwójnie opublikowany post to publiczny
artefakt, którego nic napisane w tej aplikacji nie cofnie.** Z tego wynika pięć rzeczy,
które interfejs ma mówić sam z siebie, a nie dopiero po kliknięciu:

1. **Nieodwracalność rośnie wzdłuż cyklu życia i musi być widać, gdzie się jest.**
   Szkic wolno wszystko. `scheduled` ma bieg zegara. `publishing` nie należy już do nikogo
   na tym ekranie. `published` jest faktem w świecie. Ekran szczegółu jest zaprojektowany
   jako **siedem różnych ekranów**, nie jako jeden z siedmioma plakietkami (§7).
2. **„Nie wiemy" to osobny, pełnoprawny stan — i najważniejszy ekran modułu.**
   `needs_reconcile` nie dostaje przycisku „Ponów". Dostaje jeden przycisk: *„Sprawdź na
   platformie"*, i zdanie mówiące, czego ten przycisk **nie** robi (§8).
3. **UI nie oferuje przycisku, którego zapis odmówi.** Flagi `can_be_*` są w kontrakcie
   właśnie po to. Nigdzie nie wolno bramkować na `is_owner` ani na własnym odczytaniu
   `status`. Wyjątki — miejsca, w których flaga **nie wystarcza** — są nazwane w D5.
4. **Wstrzymanie to nie awaria.** `blocked` jest `warning`, nie `danger`, bo z publikacją
   nie jest nic nie tak; zepsute jest konto. Czerwień należy do `failed` i
   `needs_reconcile` — do rzeczy, które wymagają **decyzji**, nie naprawy gdzie indziej.
5. **Cisza jest defektem.** Zero to nie „nie wiemy". Brak połączeń to nie „brak wyników".
   Trzeci wynik sprawdzenia („nadal nie wiadomo") to **nie błąd** i nie wolno go pokazać
   jako błędu (§8.3).

### 1.3 Twarde reguły projektu, których ta specyfikacja przestrzega

| Reguła | Jak zastosowana |
| --- | --- |
| Ikona modułu na jego stronie: biało-na-primary | `PageHeader icon="send"` / `ModuleAside moduleIcon="send"` renderują bąbel same. **Bez `iconClass`, bez wyciszania.** |
| Każdy ekran **listy**: `FilterBar` + `FilterTabBar` w `#top` | §4.3. Obowiązkowe. **Jeden jawny wyjątek** — Połączenia, uzasadniony w D8 i zgłoszony właścicielowi. |
| Szkielety imitują realny element, kilka sztuk | §12.1 — szkielet listy **jest kartą publikacji**, szkielet Połączeń **jest kartą platformy**. Zakaz `Spinner` + „Ładowanie…" jako stanu głównego. |
| Akcje przez `Button` (nigdy `<button>`) | Wszędzie, w tym zamykanie szuflady i modali. Kompaktowe: `icon-sm` / `icon-xs`. |
| Kolejność afordancji trailing | Warunkowy `✕` / plakietka **przed** stałym kebabem. `EntityCard` robi to sam. |
| Wszystko i18n, PL + EN | Katalogi `app/i18n/{en,pl}.ts`, przestrzeń `publishing.*`. `en.ts` jest źródłem typu (`MessageSchema`), `pl.ts` 1:1. |
| Kolor nigdy jedynym sygnałem | Każdy status ma **ikonę + prozę** (§13.2). Każdy baner OAuth ma ikonę i zdanie. |
| Importy **względne** | `import PageHeader from '../../ui/patterns/PageHeader.vue';` — `@next/*` nie ma aliasu w Vite. |
| Zero nowych pakietów npm | §16. |
| Dark mode przez podmianę tokenów | Zero odwracania kolorów w komponentach (§13.3). |

### 1.4 Decyzje projektowe

Każda: **co**, **dlaczego**, **co odrzucono**.

---

**D1 — Moduł ma własny shell (`ModuleAside` + `ModuleTabs`), nie jest częścią ustawień workspace'u.**
Publikacje to **dwa** trwałe ekrany o różnym cyklu życia: strumień publikacji (rośnie
codziennie, filtrowany, stronicowany) i Połączenia (garść kont, bez paginacji, naprawiane
raz na kwartał). To jest dokładnie kształt, dla którego ten dom ma `ModuleAside` —
precedens: Boty, Workflowy, Formularze, Wiedza.
*Odrzucone:* „powłoka ustawień workspace'u" — **nie istnieje**, a zbudowanie jej pod jeden
moduł oznaczałoby wymyślenie drugiej nawigacji dla czegoś, co ma dwa wpisy.
*Odrzucone:* jeden ekran z zakładką „Połączenia" — po nieudanym OAuth człowiek wraca pod
**przypięty w konfiguracji** adres `/next/publishing/connections`; to musi być trwała
trasa, a nie stan zakładki.

**D2 — W UI mówimy „miejsce docelowe", nie „platforma".**
Bo `dry_run` **nie jest platformą** — jest próbą, która nic nie publikuje, i katalog
serwera nazywa ją wprost („Próba (nic nie zostanie opublikowane)"). Jedno słowo dla obu
rzeczy kazałoby użytkownikowi w połowie ekranów rozumieć „platformę" jako „kanał w
świecie", a w połowie jako „tryb testowy". Filtr, Select w kompozytorze i etykieta na
karcie mówią **„Miejsce docelowe"**; słowo „platforma" zostaje tam, gdzie naprawdę chodzi
o serwis zewnętrzny („Sprawdź na platformie", „Otwórz na platformie").
*Odrzucone:* ukrycie `dry_run` przed użytkownikiem — to jedyne miejsce docelowe, które
dziś w ogóle publikuje end-to-end, i ukrycie go zostawiłoby moduł bez działającej ścieżki.

**D3 — Lista to KARTY (`EntityCard`), nie `Table`.**
Dwa pola, po których człowiek skanuje tę listę, to **proza** (tytuł + początek treści) i
**chwila**. Tabela z uciętym do 40 znaków podpisem nie odpowiada na pytanie „czy to ten
post", a `Table responsive="stack"` poniżej `next-md` rozwinąłby każdy wiersz w listę
etykieta/wartość — czyli dokładnie ten układ, który dla obiektu treściowego czyta się
najgorzej. `EntityCard` ma gotowe dokładnie te regiony, których ten obiekt potrzebuje:
leading + tytuł + przycięty podtytuł + status + **stopka metadanych** + kebab.
*Odrzucone:* `Table` z kolumnami — patrz wyżej; wróci sensownie dopiero przy B9
(metrykach), gdzie danymi są liczby.
*Odrzucone:* siatka 2–3 kolumn — podtytuł potrzebuje szerokości; lista zostaje
jednokolumnowa na każdym breakpoincie.

**D4 — Szczegół to TRASA, kompozytor to SZUFLADA.**
Szczegół musi mieć stabilny URL: z kalendarza (piąte źródło, `subject.type: 'publication'`),
z powiadomienia, z zgłoszenia w supporcie. Szuflada zamykana klawiszem Wstecz nie jest
miejscem, w którym czyta się, czy post poszedł w świat. Kompozytor odwrotnie — to praca
nad **jednym** rekordem, otwierana i zamykana wielokrotnie z listy **i** ze szczegółu; to
jest kształt szuflady (precedens: `WorkflowEditorDrawer`, `EventDrawer`).
*Odrzucone:* szuflada szczegółu jak w Zadaniach (`?task=`) — Zadania nie mają artefaktu w
świecie, do którego trzeba umieć wrócić linkiem.

**D5 — Bramkujemy na `can_be_*`, a tam gdzie flaga nie wystarcza, dokładamy warunek — i mówimy o tym głośno.**
Flagi są kontraktem i są pierwszą bramką **zawsze**. Są jednak **trzy** miejsca, w których
sama flaga wystawiłaby przycisk, którego zapis odmówi — i UI musi je domknąć, **nie**
zastępując flagi:

| Miejsce | Flaga mówi | Zapis odmówi | Dodatkowy warunek UI |
| --- | --- | --- | --- |
| „Zaplanuj" na publikacji już `scheduled` | `can_be_scheduled: true` (bo `scheduled` jest `isEditable()`) | `422 publication_transition_not_allowed` — ruch w ten sam status nie jest krawędzią | Przycisk **„Zaplanuj"** tylko dla `status ∈ {draft, failed, blocked}`. Dla `scheduled` afordancja nazywa się **„Zmień termin"** i idzie przez `PUT` (§7.4). |
| „Zaplanuj" na publikacji `blocked`, gdy konto **nadal** nie działa | `can_be_scheduled: true`, przejście `blocked → scheduled` legalne | nic nie odmówi — i to jest problem: uzbrojona publikacja pójdzie w ścianę przy najbliższym zamiataniu | Przycisk **disabled z widocznym powodem** („Najpierw napraw połączenie") + link do Połączeń, dopóki złączone konto nie jest `active` (§7.2). Wymaga dociągnięcia listy połączeń — **L5**. |
| „Zaplanuj" na publikacji publicznej **bez konta** | `can_be_scheduled: true` | nic nie odmówi (`SchedulePublicationRequest` waliduje tylko chwilę) | Przy `publishes_publicly === true && platform_connection_id === null` przycisk **disabled z powodem** („Wybierz konto, na które to ma pójść") — **L3**. |

*Odrzucone:* własna, klienteczna kopia tabeli przejść — drugi odczyt tej samej tabeli jest
tym, który się rozjeżdża. Trzy warunki wyżej to **nie** tabela przejść, tylko trzy
nazwane, uzasadnione domknięcia, każde ze zgłoszoną luką po stronie backendu.

**D6 — Status NIE jest częścią zapisanego widoku.**
Zapisany widok trzyma `platform[]`, `search`, `scheduled_from`, `scheduled_to`. Nie trzyma
`status`. Powód jest dokładnie ten, dla którego Zadania nie zapisują kubełka
Active/Archive/Trash: pasek tabów i pigułka widoku roszczą sobie wtedy prawo do tego
samego stanu, a każde kliknięcie taba natychmiast oznacza aktywny widok jako
„zmodyfikowany". Pasek pełen fałszywych plakietek `modified` nauczyłby ignorowania tej
plakietki wszędzie indziej w aplikacji.
*Odrzucone:* zapisywanie `status` (kuszące — „Do sprawdzenia na IG" to sensowny widok).
Koszt przewyższa zysk, a ten sam wynik daje deep-link `?status=needs_reconcile&platform=instagram`.

**D7 — Baner „wymaga uwagi" zamiast wymyślonego taba „wymaga uwagi".**
Serwer liczy `needs_attention` (= `failed` + `needs_reconcile` + `blocked`) i **nie ma**
filtru wielostatusowego. Tab, który miałby te trzy statusy naraz, nie da się pobrać jednym
żądaniem — więc nie powstaje. Zamiast niego, gdy `needs_attention > 0`, nad listą siedzi
`Alert variant="warning"` z liczbą **z serwera** i trzema linkami do trzech tabów.
*Odrzucone:* sumowanie trzech `counts` po stronie klienta jako „liczba wymagająca uwagi" —
w dniu dodania ósmego statusu klient byłby cicho w błędzie; `needs_attention` istnieje
dokładnie po to, żeby tego nie robić.

**D8 — Połączenia są WYJĄTKIEM od `FilterBar` + zapisanych widoków, i jest to wyjątek nazwany.**
Reguła domu mówi „każdy ekran listy". Połączenia nie są ekranem listy w tym znaczeniu:
odpowiedź jest **nieszkicowana** (bez paginacji, garść wierszy), zbiór miejsc docelowych
jest **zamknięty i znany z góry**, a jedyny filtr, jaki API oferuje (`platform[]`), jest
zastąpiony przez sam układ ekranu — **siatkę kart per miejsce docelowe**. Zapisany widok
nad trzema kartami byłby pigułką bez treści. Precedens dla nazwanego wyjątku istnieje:
`docs/next/workflows-uxui-spec.md` §5.1 („Per-workflow section — Saved-Views exemption").
**Do zatwierdzenia przez właściciela** — patrz raport B7.
*Odrzucone:* `FilterBar` z jednym Selectem i `FilterTabBar` nad trzema kartami — spełnia
literę reguły i łamie jej powód.

**D9 — Połączenie startuje NAWIGACJĄ CAŁEGO OKNA, nigdy popupem i nigdy `fetch`em.**
`POST …/authorize` odpowiada `{authorize_url}` **i** ustawia ciasteczko `HttpOnly`,
`SameSite=Lax`, **host-only** (`taskio_publishing_handshake`), które musi wrócić przy
przekierowaniu z platformy. Popup (`window.open`) dostaje to ciasteczko, ale: bywa blokowany,
gubi się przy logowaniu Google w innym profilu, a `return_path` z konfiguracji ląduje w
**tej samej** karcie, w której zaczęto — czyli w popupie, którego nikt nie ogląda. `fetch`
podążający za `authorize_url` gubi nagłówki prawdziwego handshake'u. Zostaje
`window.location.assign(authorize_url)`.
*Odrzucone:* popup + `postMessage` — druga, własna maszyneria komunikacji dla przepływu,
który ma już przypięty adres powrotu.

**D10 — Sukces połączenia to toast, porażka to BANER, który zostaje.**
Człowiek wraca z ekranu zgody Google/Meta z niczym. Zdanie, które ma przeczytać, ma
**pięć do dwudziestu pięciu słów** i mówi, co zrobić dalej (katalog `oauth_failures` jest
tak napisany). Toast znika po pięciu sekundach i nie da się go przeczytać dwa razy. Porażka
idzie więc w `Alert variant="danger|warning"` przypięty nad siatką kart, `dismissible`, z
akcją „Połącz ponownie" tam, gdzie lekarstwo należy do użytkownika (§10.3).
Sukces zostaje toastem — jest jednozdaniowy, a dowód i tak renderuje się na karcie.

**D11 — Treść publikacji to `Textarea`, nie `MarkdownEditor`.**
YouTube, Instagram i Facebook nie renderują markdownu. Edytor z przyciskiem **B** obiecałby
pogrubienie, które w świecie stanie się dosłownymi gwiazdkami w podpisie. `Textarea`
`autoGrow` + licznik do 5000 (limit `body` z `StorePublicationRequest`).
*Odrzucone:* `MarkdownEditor` z wyłączonym paskiem — nadal serializuje markdown i nadal
kusi wklejeniem.

**D12 — Kolejność mediów jest DANYMI i zmienia się PRZYCISKAMI, nie tylko przeciąganiem.**
`media` to uporządkowana lista uuidów Dysku w kolejności, w jakiej platforma je dostaje.
Każdy kafelek ma `▲`/`▼` (`Button size="icon-xs"`) z `aria-label` i anonsem pozycji w
live-region. Przeciąganie jest **opcjonalne** i odłożone: bez klawiatury nie jest
afordancją, tylko ozdobą.
*Odrzucone:* wyłącznie drag&drop — kolejność, której nie da się zmienić z klawiatury, jest
danymi, do których część ludzi nie ma dostępu.

**D13 — `options` nie dostaje edytora, ale MUSI przetrwać zapis.**
`options` jest schema-less i należy do adaptera, którego jeszcze nie ma (B4). Generyczny
edytor JSON w formularzu publikacji byłby powierzchnią, na której da się wpisać wszystko i
nie da się niczego sprawdzić. Pole **nie istnieje** w formularzu, a wartość z `GET`
wraca na drucie **dosłownie** przy każdym `PUT` (§6.6). Edycja podpisu nie może po cichu
skasować `{"privacy":"unlisted"}`.

**D14 — Ikony miejsc docelowych to glify rodzajowe z fallbackiem, nigdy logotypy marek.**
Mapa `platform → IconName` żyje w **jednym** module (`publishingMeta.ts`) z obowiązkowym
fallbackiem `circle` dla nieznanej wartości: `youtube → film`, `instagram → image`,
`facebook → users`, `dry_run → eye-off`. Glif **nigdy nie jest jedynym sygnałem** — obok
zawsze stoi `platform_label` (proza serwera).
*Odrzucone:* logotypy YouTube/Instagram/Facebook — drugi system ikon (kolorowe SVG marek
obok jednobarwnego rejestru), własne zasady użycia znaku towarowego i własny dark mode.
*Odrzucone:* jeden wspólny glif dla wszystkich — leading karty niósłby wtedy zero informacji.

---

## 2. Kontrakt backendu — pełny inwentarz

Wszystko poniżej odczytane z kodu i z `docs/backend/publishing-api.md`. Klient HTTP: `api`
z `app/lib/api.ts` (`baseURL: '/api'`), tablice jako **powtarzane** parametry `key[]=…`
(konwencja `serializeFilters` w `app/stores/tasks.ts`).

### 2.1 Endpointy

Wszystkie pod `/api/publishing`, za `auth:sanctum` + `RequireWorkspace` (brak aktywnego
workspace'u → **400**, obcy → **403**, obcy `{id}` → **404** przy wiązaniu trasy).

| Metoda | Ścieżka | Zwrot | Uwagi |
| --- | --- | --- | --- |
| `GET` | `/publishing/counts` | `{data:{counts:{<status>:int×7}, total:int, needs_attention:int}}` | Odpowiada dla **każdego** statusu naraz; ignoruje filtr `status`, honoruje pozostałe. `counts` **zawsze** ma wszystkie 7 kluczy (0, nie brak). |
| `GET` | `/publishing/publications` | kursor, `PublicationResource[]` | **Najnowsze wg `created_at` malejąco** — świadomie NIE wg `scheduled_at` (nullable). |
| `POST` | `/publishing/publications` | `201` `PublicationResource` | Tworzy **szkic**. `scheduled_at` w payloadzie **NIE uzbraja**. |
| `GET` | `/publishing/publications/{uuid}` | `PublicationResource` | |
| `PUT` | `/publishing/publications/{uuid}` | `PublicationResource` | **Zapis całego wiersza**, nie łatka (§6.6). `403` gdy `can_be_edited: false`. |
| `POST` | `/publishing/publications/{uuid}/schedule` | `PublicationResource` | `{scheduled_at}` **wymagane**. Jedyne przejście po HTTP poza sprawdzeniem. |
| `POST` | `/publishing/publications/{uuid}/reconcile` | `PublicationResource` | **Bez ciała.** `throttle:6,1,publishing-reconcile`. |
| `DELETE` | `/publishing/publications/{uuid}` | `204` | Soft delete. `403` dla `publishing` i `needs_reconcile`. |
| `GET` | `/publishing/connections` | `PlatformConnectionResource[]` | **Bez paginacji**, najnowsze pierwsze. `platform[]` filtruje. |
| `POST` | `/publishing/connections/{platform}/authorize` | `{data:{authorize_url, expires_in}}` + `Set-Cookie` | `{platform}` ograniczone do enuma na trasie (inne → **404**). |
| `DELETE` | `/publishing/connections/{uuid}` | `204` | Rozłączenie: soft delete **+ wstrzymanie kolejki** (§9.5). |

Parametry listy: `status` (jeden), `platform[]`, `search` (literał, nie wzorzec),
`scheduled_from`, `scheduled_to`, `per_page` (domyślnie 25), `cursor`.

**Czego NIE MA i nie wolno wymyślić:** endpointu publikowania, endpointu ponawiania,
endpointu **rozbrajania** (`scheduled → draft` — **L2**), katalogu miejsc docelowych
(**L1**), dziennika prób (`publication_attempts` — **L7**), strefy czasowej (**L4**).

### 2.2 `PublicationResource` — każde pole i co z nim robi UI

| Pole | Typ | Znaczenie w UI |
| --- | --- | --- |
| `id` | `uuid` | Klucz `:key`, segment trasy szczegółu. |
| `title` | `string` | Tytuł karty i nagłówek szczegółu. ≤ 255. |
| `body` | `string\|null` | Treść. ≤ 5000. Na karcie przycięta do 2 linii; na szczegółe `whitespace-pre-wrap`. **Nie markdown** (D11). |
| `platform` | `enum` | **Rozgałęziaj na tym.** Mapa ikon: D14. |
| `platform_label` | `string` | **Gotowa proza serwera.** Renderować dosłownie, nie tłumaczyć po stronie klienta. |
| `publishes_publicly` | `bool` | `false` **tylko** dla `dry_run`. Steruje: wymogiem konta (D5), ostrzeżeniami, plakietką „Próba". **Nie porównywać `platform !== 'dry_run'`.** |
| `platform_connection_id` | `uuid\|null` | Do złączenia z listą połączeń po stronie klienta (**L5**). |
| `status` | `enum` | **Rozgałęziaj na tym.** Siedem wartości. |
| `status_label` | `string` | Proza serwera w języku czytającego. Treść plakietki. |
| `status_tone` | `'info'\|'warning'\|'success'\|'danger'\|null` | **`null` dla `draft`** — patrz §13.1. Mapowane na `variant` `Badge`. |
| `needs_attention` | `bool` | Czy wiersz woła o decyzję. Steruje akcentem karty (§5.3). |
| `scheduled_at` | ISO UTC `\|null` | Chwila. Render w strefie workspace'u (**L4**). |
| `published_at` | ISO UTC `\|null` | **Nie kopia `scheduled_at`** — po pojednaniu potrafi się różnić o godziny. Pokazywać obok terminu, nie zamiast. |
| `media` | `string[]` | Uporządkowane uuidy plików **Dysku**. **Nie rozwinięte w obiekty** — po miniatury pyta się Dysku (§6.3). Maks. 10, `distinct`. |
| `options` | `object` | Schema-less. D13 — bez edytora, z round-tripem. |
| `remote_id` | `string\|null` | Dowód istnienia. Render `font-next-mono`, z kopiowaniem. |
| `remote_url` | `string\|null` | **Jedyna droga „idź i zobacz".** Link zewnętrzny. |
| `attempts` | `int` | Ile razy wzięto do publikacji. |
| `last_attempt_at` | ISO `\|null` | Od kiedy trwa `publishing`; wiek zablokowanego wiersza. |
| `failure_code` | `string\|null` | **Kod stabilny, tłumaczy go KLIENT** (§2.6). |
| `failure_context` | `object\|null` | Drobiazgi. **Nie renderować surowo** — patrz §2.6. |
| `creator` | `CreatorResource` | `CreatorBadge`. Twórcą bywa `workflow_run` / `bot`. |
| `is_owner` | `bool` | **Nigdy bramką akcji.** Tylko informacja „to moje". |
| `can_be_edited` / `_deleted` / `_scheduled` / `_reconciled` | `bool` | **Bramki afordancji** (D5). |
| `created_at` / `updated_at` | ISO | Stopka metadanych. |

**`remote_draft_id` nie istnieje w odpowiedzi** — i nie wolno go szukać ani odsyłać.

### 2.3 `PlatformConnectionResource`

| Pole | Znaczenie w UI |
| --- | --- |
| `id`, `platform`, `platform_label` | Karta platformy + Select konta w kompozytorze. |
| `external_account_id` | Jedyne, co odróżnia dwa konta o tej samej nazwie. Render `font-next-mono text-next-xs`, wyciszone. |
| `account_name` | Nazwa konta — główny tekst karty. |
| `status` / `status_label` / `status_tone` | `active` → `success`, `needs_reauth` → **`danger`**, `revoked` → **`'muted'`** ⚠ patrz **L8**. |
| `needs_attention` | `true` tylko dla `needs_reauth`. |
| `can_publish` | **Bramka** dla opcji w Selectcie konta. Nie porównywać ze `'active'`. |
| `scopes` | Przyznane uprawnienia — jedyne wyjaśnienie późniejszej odmowy na uprawnieniach. §9.3. |
| `expires_at` | `null` = **„platforma nie podała"**, nigdy „wygasło". |
| `last_refreshed_at` | „Odnowiono {data}". |
| `failure_code` | Kod z `connection_failures` — tłumaczy klient (§2.6). |
| `credentials_readable` | `false` = incydent rotacji `APP_KEY`. Ekran **musi** wyglądać inaczej (§9.6). |
| `can_be_disconnected` | Bramka przycisku „Rozłącz". |

### 2.4 Kody odmów przejść — `422 {code, message, context:{from,to}}`

**`message` przychodzi PRZETŁUMACZONY przez serwer** (katalog `publishing.transitions`, w
języku czytającego). UI renderuje `message` **dosłownie** i rozgałęzia się na `code` tylko
po to, żeby wybrać **naczynie** (toast / alert w miejscu / alert + akcja „Odśwież").

| `code` | Naczynie | Akcja obok |
| --- | --- | --- |
| `publication_reconcile_before_retry` | `Alert danger` w miejscu | „Sprawdź na platformie" (§8) |
| `publication_blocked_holds` | `Alert warning` w miejscu | „Otwórz połączenia" |
| `publication_terminal` | `toast.warning` + refetch | — |
| `publication_transition_not_allowed` | `toast.danger` + refetch | — |
| `publication_transition_lost_race` | `Alert info` w miejscu | **„Odśwież"** → `GET /publications/{id}` (§8.4) |

### 2.5 `failure_code` — osiem kodów, które tłumaczy KLIENT

Zasób nie niesie `failure_label`. Zdania istnieją w katalogu **serwerowym**
(`lang/{pl,en}/publishing.php`), którego frontend nie czyta — więc katalog frontu musi
mieć ich **wierne kopie** (**L9**).

| `failure_code` | Skąd | Katalog serwera | Status, w którym się pojawia |
| --- | --- | --- | --- |
| `title_missing` | adapter | `failures.*` | `failed` |
| `publish_outcome_unknown` | `PublicationPublisher` | `failures.*` | `needs_reconcile` |
| `reconciled_absent` | pojednanie | `failures.*` | `failed` (po sprawdzeniu — **to dobra wiadomość**) |
| `dispatch_failed` | kolejka | `failures.*` | `failed` |
| `publish_worker_failed` | hak `failed()` joba | `failures.*` | `needs_reconcile` |
| `reaper_stale` | żniwiarz | `failures.*` | `needs_reconcile` |
| `connection_needs_reauth` | `PlatformConnectionManager` | `holds.*` | `blocked` |
| `connection_disconnected` | `PlatformConnectionManager` | `holds.*` | `blocked` |

Połączenia mają własne cztery: `refresh_failed`, `refresh_unsupported`,
`credentials_unreadable`, `disconnected_by_user` (`connection_failures.*`).

**Nieznany kod** (B4 dołoży swoje): fallback `publishing.failure.unknown` — *„Publikacja
zatrzymała się z powodem, którego ta wersja aplikacji jeszcze nie opisuje ({code})."*
Nigdy pusty, nigdy sam surowy klucz.

**`failure_context` nie idzie na ekran surowo.** Dziś bywa `{stale_after_seconds: 900}` i
`{exception: 'Illuminate\\...'}`. Nazwa klasy wyjątku **nie jest** komunikatem dla
użytkownika. Reguła: renderujemy **wyłącznie** klucze, które specyfikacja zna z nazwy
(dziś: `stale_after_seconds` → „czekało ponad {n} min"); reszta nie renderuje się wcale.

### 2.6 Kto tłumaczy co — jedna tabela, do której wraca się przy każdym napisie

| Napis | Źródło | Reguła |
| --- | --- | --- |
| `status_label`, `platform_label` | **serwer** | Renderować dosłownie. Zakaz własnego słownika statusów. |
| `message` z 422 (przejścia i walidacja) | **serwer** | Renderować dosłownie; `code`/klucz pola tylko do wyboru naczynia. |
| `failure_code` (publikacji i połączeń) | **katalog frontu** | Wierne kopie zdań serwera + fallback dla nieznanego. **L9.** |
| `reason` z powrotu OAuth | **katalog frontu** | 14 kodów + fallback dla przepuszczonego kodu platformy. **L9.** |
| Etykiety pól, przyciski, stany puste, a11y | **katalog frontu** | `app/i18n/{en,pl}.ts` → `publishing.*`. |

---

## 3. Architektura informacji, trasy, stan w URL

### 3.1 Nawigacja globalna i plakietka

`resources/js/next/pages/AppLayout.vue`, `primaryNav` — **nowa pozycja zaraz po `calendar`**:

```ts
{ key: 'publishing', labelKey: 'nav.publishing', icon: 'send', to: '/publishing' },
```

Uzasadnienie miejsca: definiującą cechą publikacji w tym produkcie jest **chwila na
zegarze**, a Kalendarz jest miejscem, w którym publikacje właśnie zaczęły się pojawiać jako
piąte źródło. To ten sam argument, którym R3 wstawiło Kalendarz zaraz po Zadaniach.

**Plakietka nawigacji** — dokładnie mechanizmem Akceptacji (`SidebarItem #badge` +
rozgrzanie licznika przy montowaniu powłoki):

- źródło: `needs_attention` z `GET /publishing/counts`, **nigdy** suma po stronie klienta (D7);
- `Badge variant="danger" tone="solid" size="sm"` + zdanie `sr-only`
  (`nav.publishingBadge` → *„Publikacje wymagające decyzji: {n}"*);
- ukryta, gdy `0` lub `null`;
- `danger`, nie `primary` jak Akceptacje — Akceptacje mówią „czeka na ciebie praca",
  Publikacje „coś mogło pójść w świat i nikt nie wie". **Do zatwierdzenia przez właściciela**
  (dwa różne kolory plakietek w jednym pasku bocznym to decyzja widoczna).

### 3.2 Trasy

```
/publishing                          → redirect → next.publishing.publications
/publishing/publications             name: next.publishing.publications
                                     component: pages/publishing/PublicationsView.vue
/publishing/publications/:id         name: next.publishing.publication
                                     component: pages/publishing/PublicationDetailView.vue
  ├─ /overview  (domyślna)           name: next.publishing.publication.overview
  └─ /approval                       name: next.publishing.publication.approval   ← B6
/publishing/connections              name: next.publishing.connections
                                     component: pages/publishing/ConnectionsView.vue
```

Sekcje szczegółu przez `sectionRedirect()` (`app/router/sectionRedirect.ts`) — tak jak
`next.workflows.detail.*` i `next.bots.detail.*`; bez sekcji deep-link do zakładki
akceptacji nie istnieje, a to jest dokładnie link, który B6 będzie chciało wysyłać.

> ### ⚠ `/next/publishing/connections` JEST PRZYPIĘTE W KONFIGURACJI
>
> `config/publishing.php` → `oauth.return_path` domyślnie `/next/publishing/connections`.
> Callback OAuth przekierowuje **dokładnie tam**, doklejając `?connection=…`. Zmiana tej
> ścieżki we frontendzie bez zmiany konfiguracji oznacza, że **udane** połączenie kończy
> się na ekranie „nie znaleziono" — dokładnie ten stan opisuje dziś komentarz w konfiguracji
> („a real connect currently ends on the SPA's not-found screen having SUCCEEDED").

Wszystkie trasy: `meta: { requiresAuth: true, titleKey: … }`.

### 3.3 Shell modułu

`pages/publishing/PublishingModuleLayout.vue` — `ModuleAside` (≥ `next-lg`) + `ModuleTabs`
(poniżej), dokładnie jak `BotsModuleLayout` / `WorkflowsModuleLayout`:

| Pole | Wartość |
| --- | --- |
| `moduleIcon` | `send` — **bąbel biało-na-primary**, bez wyciszania |
| `moduleTitle` | `publishing.title` → „Publikacje" |
| `moduleHint` | `publishing.subtitle` → „Co, gdzie i kiedy idzie w świat." |
| `moduleItems` | `[{ icon:'send', label:'publishing.nav.publications', to:'/publishing/publications' }, { icon:'link-2', label:'publishing.nav.connections', to:'/publishing/connections', badge: needsReauthCount }]` |
| `activeMatch` | `isPathActive(route.path, item.to)` |

Pozycja „Połączenia" niesie własną, małą plakietkę `danger`, gdy którekolwiek połączenie ma
`needs_attention` **albo** `credentials_readable === false` — to jedyny sposób, żeby zepsute
konto było widać z ekranu publikacji.

### 3.4 Stan w URL

| Ekran | Param | Wartość | Domyślnie |
| --- | --- | --- | --- |
| Lista | `status` | jedna z 7 wartości | brak = wszystkie |
| Lista | `platform` | powtarzalny `platform=youtube&platform=dry_run` | brak = wszystkie |
| Lista | `q` | tekst → param API `search` | brak |
| Lista | `from` / `to` | ISO dzień → `scheduled_from` / `scheduled_to` | brak |
| Lista | `new` | `1` — szuflada tworzenia | — |
| Lista, Szczegół | `edit` | `1` — szuflada edycji | — |
| Połączenia | `connection` | `connected` \| `failed` (**z callbacku**) | — |
| Połączenia | `platform` | wartość enuma (**z callbacku**, może być NIEOBECNY przy `unknown_platform`) | — |
| Połączenia | `reason` | kod powodu (**z callbacku**, tylko przy `failed`) | — |

Synchronizacja ręczna (`hydrateFromQuery()` / `syncQuery()` + flaga `hydrating`), wzorzec
1:1 z `TasksView.vue` / `WorkflowsView.vue`. **`useRouteQueryHydration` nie istnieje w `next`.**
Zmiana filtrów → `router.replace`; otwarcie szuflady → `router.push` (Wstecz zamyka).

**Parametry callbacku są zdejmowane z URL natychmiast po odczytaniu** (`router.replace` bez
nich), zanim cokolwiek się przerenderuje. Powód: `?reason=oauth_state_bad_signature` w
historii przeglądarki wraca przy każdym „wstecz" i pokazuje baner o zdarzeniu sprzed
kwadransa jak o świeżym.

---

## 4. Ekran A — lista publikacji

### 4.1 Struktura strony

```
PageHeader  (icon="send", title="Publikacje", description="Co, gdzie i kiedy idzie w świat.")
  #actions: [ Button primary leading-icon="plus" „Nowa publikacja" ]

FilterBar (sticky)
  #top:      FilterTabBar            ← zapisane widoki, OBOWIĄZKOWE
  default:   [ Select multiple: Miejsce docelowe ] [ DateRangeFilter: Termin ]
  search:    q
  #results:  „Publikacje: N" | „0 publikacji" (+ „Wyczyść filtry")

[ Alert warning ]  ← „N publikacji wymaga decyzji"   (D7, tylko gdy needs_attention > 0)

Tabs variant="pills"   ← Wszystkie · Szkice · Zaplanowane · Publikowanie ·
                          Opublikowane · Nieudane · Do sprawdzenia · Wstrzymane

⟨ lista kart publikacji, jedna kolumna ⟩
⟨ sentinel infinite-scroll + szkielety doładowania ⟩
```

### 4.2 Taby statusów

Komponent: `ui/navigation/Tabs.vue`, `variant="pills"`, `size="sm"`. Osiem pozycji w
kolejności **cyklu życia**, nie alfabetycznej — pasek ma się czytać jak oś, po której
rzecz wędruje:

| `value` | Etykieta (PL) | `badge` | Ikona |
| --- | --- | --- | --- |
| `all` | Wszystkie | `counts.total` | — |
| `draft` | Szkice | `counts.draft` | `file-text` |
| `scheduled` | Zaplanowane | `counts.scheduled` | `clock` |
| `publishing` | Publikowanie | `counts.publishing` | `loader` |
| `published` | Opublikowane | `counts.published` | `check-circle` |
| `failed` | Nieudane | `counts.failed` | `x-circle` |
| `needs_reconcile` | Do sprawdzenia | `counts.needs_reconcile` | `help-circle` |
| `blocked` | Wstrzymane | `counts.blocked` | `lock` |

- Liczby **zawsze** z `GET /publishing/counts`, nigdy z długości załadowanej strony.
  Serwer wysyła wszystkie siedem kluczy — **`0` renderuje się jako `0`**, nigdy jako `—`.
- `counts` honoruje **pozostałe** filtry (miejsce docelowe, szukanie, zakres terminu), więc
  po zawężeniu filtra liczby na tabach też się zmieniają. Refetch `counts` **razem z**
  refetchem listy, przy każdej zmianie filtra — inaczej pasek kłamie w sposób,
  którego nie widać.
- `all` **nie wysyła** parametru `status` (a nie `status=all`).
- Trzy taby „wymagające uwagi" (`failed`, `needs_reconcile`, `blocked`) dostają etykietę
  tekstową + ikonę; kolor plakietki licznika: `danger` dla pierwszych dwóch, `warning` dla
  `blocked` — **ta sama mapa co plakietki statusu** (§13.1), żeby tab i karta nie mówiły
  dwóch różnych rzeczy o tym samym wierszu.
- Pasek **nie zawija** — przewija się poziomo z gradientami krawędzi (reguła `Tabs`).

### 4.3 FilterBar + zapisane widoki

`FilterBar` `sticky`, `controlSize="md"` (rozmiar ambientowy dla wszystkich kontrolek w
środku — reguła domu).

| Slot | Zawartość |
| --- | --- |
| `#top` | **`FilterTabBar`** — zapisane widoki, kontekst `publishing`. **Obowiązkowe, nie opcjonalne.** |
| default | `Select multiple display="chips" leadingIcon="send"` — Miejsce docelowe (opcje: **L1**) · `DateRangeFilter leadingIcon="clock"` — Termin (**jedna** kontrolka zakresu, reguła domu) |
| search | `v-model:search` → param `search`, debounce 300 ms |
| `#results` | „Publikacje: {n}" / „0 publikacji"; przy aktywnych filtrach `Button ghost xs` „Wyczyść filtry" |

`activeFilters` (chipy): **wielowartościowe chipy z wartościami**, nigdy „wybrano 2" —
`{ key:'platform', label:'Miejsce docelowe', values:[{label:'YouTube'},{label:'Próba'}] }`.
Etykiety wartości biorą się z `platform_label`, gdy w załadowanych danych jest choć jeden
taki wiersz; w przeciwnym razie z katalogu frontu (**L1**).

Zapisany widok (`useFilterTabs('publishing', {serialize, apply, normalize, restoreValue})`)
trzyma: `platform[]`, `search`, `from`, `to`. **Nie trzyma `status`** (D6) i nie trzyma
pozycji kursora.

### 4.4 Baner „wymaga uwagi" (D7)

Widoczny **wyłącznie** gdy `counts.needs_attention > 0`:

```
┌ ⚠ ─────────────────────────────────────────────────────────────────────┐
│ 3 publikacje wymagają decyzji.                                          │
│ [Nieudane (1)] [Do sprawdzenia (1)] [Wstrzymane (1)]                    │
└─────────────────────────────────────────────────────────────────────────┘
```

`Alert variant="warning"` (`role="alert"`), w `#actions` trzy `Button variant="ghost" size="sm"`
przełączające tab. Przycisk dla statusu o zerowym liczniku **nie renderuje się** —
nie ma sensu prowadzić do pustego taba. Liczba w zdaniu to **`needs_attention` z serwera**,
liczby na przyciskach to `counts.<status>`; nigdzie nie sumujemy sami.

Kolejność: `needs_reconcile` **pierwsze** (najpilniejsze — może już być w świecie),
potem `failed`, potem `blocked`.

### 4.5 Paginacja

Kursorowa: `useInfiniteScroll({ onLoadMore, canLoadMore })`, sentinel po ostatniej karcie,
`hasMore = meta.next_cursor !== null`. Doładowanie renderuje **2 szkieletowe karty**
(kształt realnej karty), nigdy `Spinner`. Błąd doładowania: `Alert danger` **pod** listą,
z „Spróbuj ponownie", **wczytane karty zostają na ekranie** (wzorzec `WorkflowsView`).
`ui/navigation/Pagination.vue` (numerowana) **nie jest tu używana** — to komponent dla
paginacji offsetowej.

---

## 5. Karta publikacji

Komponent `pages/publishing/PublicationCard.vue`, zbudowany **na `EntityCard`**.

### 5.1 Anatomia

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ ┌────┐  Jesienna zapowiedź                        [🕐 Zaplanowana]      [⋯]  │
│ │ 🎬 │  Coś nowego nadchodzi. Zaczynamy od krótkiego                          │
│ └────┘  teasera, który pokaże tylko tyle, żeby…                              │
│                                                                              │
│  🕐 10 wrz, 09:00 · 📎 Media: 2 · 👤 Hubert · ⚠ Konto wymaga ponownego…      │
└──────────────────────────────────────────────────────────────────────────────┘
```

| Region `EntityCard` | Treść | Pole |
| --- | --- | --- |
| `#leading` | Bąbel z glifem miejsca docelowego (D14), `bg-next-muted` | `platform` |
| tytuł | `title`, jedna linia, `truncate`. **Rozciągnięty link** na całą kartę → szczegół | `title`, `id` |
| podtytuł | `body`, `subtitleLines={2}` | `body` |
| status | `Badge` z `variant` = mapa `status_tone` (§13.1), `tone="subtle"`, ikona statusu, tekst = `status_label` | `status_tone`, `status_label` |
| `#meta` | patrz niżej | |
| `#actions` | kebab `DropdownMenu` (§5.2) | flagi |

**Stopka metadanych** — stały porządek, zawsze te same sloty, pominięte gdy puste
(wzorzec „card metadata footer"):

| Kolejność | Ikona | Treść | Warunek |
| --- | --- | --- | --- |
| 1 | `send` | `platform_label` + (przy `publishes_publicly === false`) `Badge neutral subtle` „Próba" | zawsze |
| 2 | `clock` | **Jedna** chwila, wybrana przez status: `published` → „Opublikowano {published_at}"; `scheduled`/`blocked` → „Termin {scheduled_at}"; `publishing` → „Od {last_attempt_at}"; `draft` z terminem → „Termin (nieuzbrojony) {scheduled_at}"; `draft` bez → „Bez terminu" | zawsze |
| 3 | `image` | „Media: {n}" | `media.length > 0` |
| 4 | `user` | `CreatorBadge` | zawsze |
| 5 | `alert-circle` | **przetłumaczony `failure_code`, przycięty do jednej linii** | `needs_attention === true` |

> **Slot 2 mówi JEDNĄ chwilę, nie dwie.** Karta z „Termin 09:00 · Opublikowano 11:24"
> zmusza do porównywania dwóch liczb w miejscu przeznaczonym do skanowania. Obie chwile
> stoją obok siebie **dopiero na szczegółe** (§7.3) — tam różnica między nimi jest
> informacją (pojednanie potrafi je rozjechać o godziny), a nie szumem.

> **„Termin (nieuzbrojony)" to nie ozdobnik.** Szkic z ustawionym `scheduled_at` **nie
> pójdzie nigdzie** — ustawienie chwili nie jest uzbrojeniem. Karta, która pokazuje „Termin
> 10 wrz 09:00" bez tego dopisku, obiecuje publikację, której nikt nie zlecił.

### 5.2 Menu akcji — bramkowane wyłącznie flagami (+ trzy domknięcia z D5)

`DropdownMenu` w `#actions`, `Button size="icon-sm" variant="ghost" ariaLabel="Akcje publikacji: {title}"`.

| Pozycja | Warunek | Skutek |
| --- | --- | --- |
| Otwórz | zawsze | trasa szczegółu |
| **Otwórz na platformie** | `remote_url !== null` | link zewnętrzny, `rel="noopener noreferrer"`, ikona `external-link` |
| Edytuj | `can_be_edited` | szuflada `?edit=1` |
| **Zaplanuj** / **Zaplanuj ponownie** | `can_be_scheduled && status ∈ {draft, failed, blocked}` (D5) | modal planowania (§7.4) |
| **Zmień termin** | `status === 'scheduled' && can_be_edited` (D5) | modal planowania w trybie `PUT` |
| **Sprawdź na platformie** | `can_be_reconciled` | §8 — z listy prowadzi **na szczegół**, nie wykonuje akcji w locie |
| Usuń | `can_be_deleted` | `ConfirmDialog`, `destructive: true` — **dwa różne zdania**, §7.5 |

Gdy dla danego wiersza nie zostaje **żadna** pozycja poza „Otwórz" (czyli `publishing`),
kebab **nie renderuje się wcale** — puste menu z jedną pozycją, która duplikuje kliknięcie
w kartę, uczy, że kebab bywa pusty.

> **„Sprawdź na platformie" z listy tylko nawiguje.** Sprawdzenie kosztuje limit platformy
> **wspólny dla całej instalacji** i ma trzy różne wyniki, z których jeden trzeba
> przeczytać. Przycisk wykonujący je z listy, na której nie widać ani powodu, ani wyniku,
> byłby zaproszeniem do klikania w pętli. Sprawdza się **na szczegółe**, tam gdzie stoi
> zdanie o tym, co ten przycisk robi.

### 5.3 Stany karty

| Stan | Wygląd |
| --- | --- |
| default | `EntityCard` default; `bg-next-card`, hairline |
| hover / focus-visible | wg `EntityCard` (ring z tokenu `ring`) |
| `needs_attention === true` | **lewa krawędź** `border-l-2` w tonie statusu (`danger` / `warning`) + stopka niesie zdanie awarii. Nie całe tło — czerwona karta w liście dwudziestu czerwonych kart przestaje znaczyć cokolwiek |
| `status === 'publishing'` | plakietka `warning` + ikona `loader`; karta **nieklikalna w kebab** (brak akcji), ale tytuł nadal prowadzi na szczegół |
| `status === 'published'` | stopka niesie „Opublikowano"; **brak akcentu krawędzi** — sukces nie krzyczy |
| ładowanie | `EntityCard loading` (szkielet: bąbel + linia tytułu + dwie linie podtytułu + linia metadanych), **6 sztuk** |

---

## 6. Ekran B — kompozytor (szuflada)

`pages/publishing/PublicationEditorDrawer.vue`, `Drawer side="right" size="lg"`
(`size="full"` poniżej `next-md`). Otwierana z listy (`?new=1`, `?edit=1`) **i** ze
szczegółu (`?edit=1`) — jeden komponent, dwa wejścia.

### 6.1 Układ

```
Drawer #title:  „Nowa publikacja" | „Edycja publikacji"
Drawer #description: „Zapisanie nie publikuje niczego. Publikacja rusza dopiero po zaplanowaniu."

┌ Pasmo: gdzie to idzie ───────────────────────────────────────────┐
│ [ Miejsce docelowe ▾ ]      [ Konto ▾ ]                          │   ← pola złączone (jedna ramka)
│ ⚠ brak kont dla tego miejsca → [Połącz konto]                    │
└──────────────────────────────────────────────────────────────────┘

┌ Treść ───────────────────────────────────────────────────────────┐
│ Tytuł            [_________________________________]  12/255      │
│ Treść            [ Textarea autoGrow                ]  87/5000    │
└──────────────────────────────────────────────────────────────────┘

┌ Media (2/10) ────────────────────────────────────────────────────┐
│ [1 ▲▼ ✕ miniatura nazwa] [2 ▲▼ ✕ miniatura nazwa]  [+ Dodaj]     │
└──────────────────────────────────────────────────────────────────┘

┌ Termin ──────────────────────────────────────────────────────────┐
│ [ DateTimePicker ]     Godzina w strefie zespołu: Europe/Warsaw   │
│ ℹ Ustawienie terminu nie uzbraja publikacji.                      │
└──────────────────────────────────────────────────────────────────┘

Drawer #footer:  [Anuluj]   [Zapisz szkic]   [Zapisz i zaplanuj →]
```

**Pasmo „gdzie to idzie" używa pola złączonego** (`FieldShell segmented`): dwa Selecty w
jednej ramce, jedna linia stanu, jedna etykieta grupy. To jest wzorzec „jointed fields"
zastosowany dokładnie tam, gdzie ma sens — te dwa pola nie są niezależne (zmiana pierwszego
unieważnia drugie), więc dwa osobne pudełka obok siebie sugerowałyby niezależność, której
nie ma.

### 6.2 Pola

| Pole | Kontrolka | Reguły | Uwagi |
| --- | --- | --- | --- |
| Miejsce docelowe | `Select` (single) | `required` | Opcje: **L1**. Zmiana wartości **czyści `platform_connection_id`** i mówi to (`Alert info sm` przy pierwszej zmianie na wypełnionym formularzu): „Konto zostało wyczyszczone — inne miejsce docelowe, inne konta." |
| Konto | `Select` (single) | opcjonalne w API, **wymagane przez UI przy `publishes_publicly`** (D5/**L3**) | Opcje = `connections` filtrowane po `platform` **i** `can_publish === true`. Każda opcja: `account_name` + `external_account_id` (mono, xs, wyciszone) |
| Tytuł | `TextInput` | `required`, `max:255` | Licznik w `#trailing` |
| Treść | `Textarea autoGrow counter` | `max:5000` | D11 — **nie** `MarkdownEditor` |
| Media | §6.3 | `max:10`, `distinct` | Kolejność = dane (D12) |
| Termin | `DateTimePicker` | opcjonalny na tworzeniu | §6.4 |

**Miejsce docelowe `dry_run`:** pole „Konto" **znika całkowicie** (nie disabled), a na jego
miejscu staje `Alert variant="info" size="sm"` z **prozą serwera z walidacji**
(`platform_not_connectable`): *„To miejsce docelowe nie ma konta do połączenia. To próba:
nic, co tam trafi, nie opuszcza aplikacji."* Kontrolka wyłączona sugerowałaby, że konto
gdzieś jest i tylko jest niedostępne.

**Pusta ścieżka „brak kont":** gdy dla wybranego, publicznego miejsca docelowego lista
`connections` filtrowana po `can_publish` jest pusta:

```
┌ ⚠ ──────────────────────────────────────────────────────────────┐
│ Nie ma jeszcze konta, na które to mogłoby pójść.                 │
│ Szkic zapiszesz bez konta; zaplanować go nie będzie można,       │
│ dopóki konto nie zostanie połączone.                             │
│                                    [ Połącz konto YouTube → ]    │
└──────────────────────────────────────────────────────────────────┘
```

Przycisk prowadzi na `/publishing/connections`. **Przed nawigacją szuflada zapisuje szkic**
(gdy formularz jest poprawny) i mówi to w toaście — inaczej droga „nie mam konta →
połączmy → wracam" kosztuje utratę napisanej treści. Gdy formularz **nie** jest jeszcze
poprawny (brak tytułu), przycisk otwiera Połączenia w **nowej karcie** (`target="_blank"`),
a szuflada zostaje otwarta. Oba zachowania są jawne w copy przycisku.

### 6.3 Media — wybór z Dysku, kolejność jako dane

- Dodawanie: `Button variant="outline" leading-icon="plus"` „Dodaj z Dysku" →
  **`pages/disk/DiskFilePickerModal.vue`** (istniejący komponent, pojedynczy wybór, emituje
  **pełny obiekt `DiskFile`**). Import międzymodułowy `publishing → disk` jest dozwolony —
  precedens `pages/forms/FormFileInput.vue` i `tasks → approvals`.
- **Nie wywołujemy `POST /disk/{id}/copy-to-temp`.** To jest wzorzec pól formularza, które
  potrzebują **własnej kopii**. `publications.media` przechowuje **wskaźniki na pliki
  Dysku** i moduł nigdy ich nie dereferencjonuje; skopiowanie do tempa zrobiłoby drugi plik
  i zerwało związek z Dyskiem.
- Przycisk „Dodaj" znika przy `media.length === 10`, a nagłówek sekcji mówi `Media (10/10)`.
- Plik już obecny na liście jest w pickerze **wyłączony** z podpisem „już dodany"
  (`distinct` w API).
- Kafelek: numer pozycji · miniatura (lub glif typu) · nazwa (`truncate`) · `▲` `▼` (`icon-xs`) · `✕` (`icon-xs`).
  Kolejność trailing: warunkowe `▲▼` **przed** stałym `✕`.
- **Edycja istniejącej publikacji ma tylko uuidy.** Po otwarciu szuflady lecą równoległe
  `GET /disk/{id}` (≤ 10, **L10**) po nazwę i miniaturę. Dla każdego:

| Wynik | Kafelek |
| --- | --- |
| `200` | normalny |
| `404` / `403` | **Kafelek ostrzegawczy**: `bg-next-warning-subtle`, ikona `alert-triangle`, tekst „Pliku już nie ma na Dysku", `✕` aktywne, `▲▼` aktywne. Nad sekcją `Alert warning`: „Jednego z plików już nie ma na Dysku. Publikacja z takim plikiem **nie zostanie opublikowana** — usuń go albo wskaż inny." |
| błąd sieci | szkielet kafelka + `Alert info` „Nie udało się wczytać podglądów mediów. Same pliki są nietknięte." — **nie** sugerować, że plik zniknął |

  To jest dokładnie ten stan, dla którego backend świadomie **nie** ma klucza obcego:
  „wiszące id zawodzi głośno w chwili publikacji, z powodem do pokazania". UI pokazuje ten
  powód **wcześniej**, bo tu jeszcze da się coś z tym zrobić.
- A11y: `role="list"` / `role="listitem"`; `aria-label` przycisków „Przesuń w górę: {nazwa}";
  po każdym ruchu `aria-live="polite"`: „{nazwa}: pozycja {i} z {n}".

### 6.4 Termin

`DateTimePicker` — model to **lokalny ISO bez strefy** (`yyyy-mm-ddTHH:mm`), czyli dokładnie
kształt, który `CalendarInstantResolver` czyta na zegarze **workspace'u**.

- Pod polem trwały tekst pomocniczy: „Godzina w strefie zespołu: {tz}" — `{tz}` z
  `workspaces.timezone` aktywnego workspace'u. **Gdy `timezone === null`** (znaczy „dziedzicz
  zegar aplikacji", którego klient nie zna — **L4**): zdanie bez nazwy strefy — *„Godzina
  liczona na zegarze zespołu, tym samym co w Kalendarzu."* **Nigdy** nie wolno wstawić tu
  strefy przeglądarki.
- Gdy strefa workspace'u jest znana **i różna** od `Intl.DateTimeFormat().resolvedOptions().timeZone`:
  `Badge variant="warning" icon="alert-triangle"` obok pola + `Tooltip`: „Twoja przeglądarka
  jest w {localTz}. Ta godzina zostanie odczytana jako {tz}."
- **`min` NIE jest ustawiany.** Powód: „teraz" zależy od zegara workspace'u, a klient go nie
  zna na pewno; policzone lokalnie `min` albo zablokowałoby legalny termin, albo przepuściło
  nielegalny. Przeszłość odmawia **serwer**, przy uzbrajaniu, zdaniem, które już istnieje
  (`scheduled_in_the_past`: *„Ta chwila już minęła. Wybierz termin w przyszłości albo
  opublikuj od razu."*) — i to zdanie renderuje się przy polu.
- Trwałe zdanie pod sekcją: *„Ustawienie terminu nie uzbraja publikacji."* (`text-next-xs
  text-next-muted-foreground`, z ikoną `info`). To jest najczęstsze możliwe nieporozumienie
  w tym module i kosztuje niewysłany post.

### 6.5 Stopka i dwa przyciski zapisu

| Przycisk | Wariant | Co robi |
| --- | --- | --- |
| Anuluj | `ghost` | zamyka; przy brudnym formularzu `useConfirm()` „Porzucić zmiany?" |
| **Zapisz szkic** | `secondary` | `POST` / `PUT` → toast „Szkic zapisany", szuflada zamknięta |
| **Zapisz i zaplanuj →** | `primary` | `POST`/`PUT`, a po sukcesie **od razu** modal planowania (§7.4) na tym samym rekordzie |

`Zapisz i zaplanuj` jest **disabled z widocznym powodem**, gdy D5 mówi, że uzbrojenia nie
będzie (brak konta przy `publishes_publicly`): `Tooltip` + `aria-disabled` + zdanie pod
stopką — nigdy sam wygaszony przycisk bez wyjaśnienia.

Oba przyciski w stanie `loading` (`Button loading` — spinner zastępuje ikonę wiodącą,
etykieta zostaje, szerokość stabilna, `aria-busy`), oba nieaktywne w trakcie zapisu.

### 6.6 `PUT` to zapis całego wiersza, nie łatka

> ### ⚠ FORMULARZ, KTÓRY CZEGOŚ NIE ODEŚLE, WYZERUJE TO W BAZIE
>
> `PublicationService::attributesFrom()` zapisuje **wszystkie** kolumny przy każdym `PUT`:
> `title`, `body`, `platform`, `platform_connection_id`, `scheduled_at`, `media`, `options`.
> Konsekwencje, każda realna:
>
> - `PUT` bez `media` → **publikacja traci wszystkie media**;
> - `PUT` bez `options` → **ginie `{"privacy":"unlisted"}`**, którego formularz w ogóle nie pokazuje (D13);
> - `PUT` bez `scheduled_at` na wierszu **`scheduled`** → `scheduled_at` staje się `null`,
>   status **zostaje `scheduled`**, a zamiatanie (`scheduled_at <= now()`) **nigdy jej nie
>   wybierze**. Publikacja jest uzbrojona na zawsze i nie pójdzie nigdy — bez jednego
>   komunikatu nigdzie. To najcichszy możliwy defekt w tym module.
>
> **Wymóg:** szuflada trzyma pełny obiekt z `GET` i odsyła **komplet** pól, łącznie z
> `options`, którego nie renderuje. Test jednostkowy na to jest obowiązkowy (§16.4).

### 6.7 Walidacja i 422

- Pola: `title`, `body`, `platform`, `platform_connection_id`, `scheduled_at`, `media.*`.
  Komunikaty przychodzą **przetłumaczone przez serwer** — renderujemy `errors.<pole>[0]`
  dosłownie pod polem, przez `FormField :error`.
- `platform_connection_id` → `connection_unusable`: **jedno** zdanie dla trzech różnych
  przyczyn, świadomie. UI go nie rozwija ani nie zgaduje, która zaszła; dokłada tylko akcję
  „Odśwież listę kont" (konto mogło zmienić status od czasu wczytania Selecta).
- `status` / `remote_id` / `remote_draft_id` / `published_at` / `attempts` są `prohibited`.
  Jeśli 422 przyjdzie na którymkolwiek z nich, to znaczy, że **klient wysłał pole, którego
  nie wolno** — czyli defekt frontendu. Renderować `toast.danger` z treścią serwera **i**
  zalogować do konsoli; nie pokazywać tego jako błędu użytkownika przy polu, którego na
  formularzu nie ma.
- `403` na `PUT` (ktoś inny uzbroił rekord w międzyczasie, `can_be_edited` się zmieniło):
  `Alert danger` w szufladzie + „Odśwież" → `GET /publications/{id}` i przerysowanie
  formularza w trybie zgodnym z nowym stanem.

---

## 7. Ekran C — szczegół publikacji, siedem wariantów

`pages/publishing/PublicationDetailView.vue`. **Projektowany jako siedem ekranów, nie jeden
z siedmioma plakietkami** (§1.2 pkt 1). Wspólny jest szkielet; zmienne są: pasmo stanu,
zestaw akcji i zdanie o tym, czego zrobić **nie można i dlaczego**.

### 7.1 Szkielet

```
PageHeader level=1
  breadcrumbs: [Publikacje → {tytuł}]
  #leading:  bąbel glifu miejsca docelowego
  title:     {title}
  #meta:     Badge statusu (wariant z tonu, ikona, proza)
  #actions:  akcje zależne od statusu (§7.2)
  #tabs:     Tabs [ Przegląd | Akceptacje ]       ← druga zakładka: B6 (§11)

⟨ PASMO STANU ⟩                                  ← pełna szerokość, per status

┌ lewa kolumna (2/3) ─────────────┐  ┌ prawa (1/3) ──────────────┐
│ Karta „Treść"                   │  │ Karta „Wysyłka"            │
│   tytuł, treść, media           │  │   DescriptionList (§7.3)   │
└─────────────────────────────────┘  └────────────────────────────┘
```

Poniżej `next-lg` kolumny stają jedna pod drugą, **„Wysyłka" nad „Treścią"** — na wąskim
ekranie pierwsze pytanie brzmi „co się z tym dzieje", nie „co tam jest napisane".

### 7.2 Pasmo stanu — siedem wariantów

Komponent `pages/publishing/PublicationStatusBand.vue`. Zawsze: ikona statusu · nagłówek ·
zdanie · (opcjonalnie) akcje. `role="status"` dla stanów informacyjnych, **`role="alert"`
dla `failed`, `needs_reconcile`, `blocked`**.

| Status | Wariant powierzchni | Nagłówek | Zdanie | Akcje w pasmie | Czego NIE MA i czy to widać |
| --- | --- | --- | --- | --- | --- |
| `draft` | `neutral` (`bg-next-muted`) | „Szkic" | „Nic nie jest zaplanowane. Ta publikacja nigdzie nie pójdzie, dopóki jej nie zaplanujesz." | **Zaplanuj** (primary), Edytuj | — |
| `scheduled` | `info` | „Zaplanowana na {termin}" | „Pójdzie w świat automatycznie. Godzina jest na zegarze zespołu{, tz}." | **Zmień termin**, Edytuj | „Cofnij do szkicu" **nie istnieje** — patrz ramka pod tabelą (**L2**) |
| `publishing` | `warning` | „Wysyłanie w toku" | „Zaczęło się {last_attempt_at}. Ta publikacja należy teraz do procesu, który ją wysyła — nie da się jej stąd zmienić ani usunąć." | **brak** | Brak akcji jest **wyjaśniony zdaniem**, nie samą pustką |
| `published` | `success` | „Opublikowana {published_at}" | „To jest w świecie. Nic, co zrobisz tutaj, tego nie cofnie." | **Otwórz na platformie** (primary, `external-link`), Usuń nasz zapis | Brak „Edytuj" wyjaśniony: „Zapis nie jest przepisywany — inaczej mówiłby o poście coś, czego post nie mówi." |
| `failed` | `danger` | „Nieudana" | **przetłumaczony `failure_code`** (§2.5) | **Zaplanuj ponownie** (primary), Edytuj, Usuń | — |
| `needs_reconcile` | `danger` | „Do sprawdzenia" | §8 — **osobny, większy panel zamiast pasma** | — | §8.5 nazywa każdą brakującą akcję |
| `blocked` | `warning` | „Wstrzymana" | **przetłumaczony kod wstrzymania** (`connection_needs_reauth` / `connection_disconnected`) | **Napraw połączenie** (primary → Połączenia), Zaplanuj ponownie *(warunkowo, D5)*, Edytuj | „Zaplanuj ponownie" bywa disabled z powodem |

> ### ⚠ NIE MA SPOSOBU NA ROZBROJENIE ZAPLANOWANEJ PUBLIKACJI (L2)
>
> Tabela przejść ma krawędzie `scheduled → draft`, `failed → draft` i `blocked → draft`,
> ale **żaden endpoint HTTP ich nie wystawia** — po HTTP człowiek może tylko **uzbroić**
> (`POST …/schedule`) i **sprawdzić** (`POST …/reconcile`).
>
> Skutek dla ekranu: **jedyny sposób zatrzymania zaplanowanej publikacji to jej usunięcie**
> (`DELETE` jest dla `scheduled` dozwolone). B8 **nie wymyśla** przycisku „Cofnij do
> szkicu". Zamiast tego pasmo `scheduled` niesie zdanie pomocnicze:
> *„Żeby to zatrzymać, trzeba tę publikację usunąć — wróci do kosza, nie do szkiców."*
> a potwierdzenie usunięcia mówi to samo (§7.5).
>
> **Rekomendacja do backendu:** `DELETE /publishing/publications/{id}/schedule` (rozbrojenie
> → `draft`, zachowując `scheduled_at` jako propozycję). Bez tego „zaplanowałem o dzień za
> wcześnie" kosztuje skasowanie rekordu i napisanie go od nowa.

### 7.3 Karta „Wysyłka"

`DescriptionList layout="horizontal" size="sm"`, sloty `value-<key>` na plakietki i linki:

| Klucz | Etykieta | Wartość | Uwagi |
| --- | --- | --- | --- |
| `platform` | Miejsce docelowe | glif + `platform_label` + `Badge neutral` „Próba" gdy `!publishes_publicly` | |
| `connection` | Konto | `account_name` + `Badge` statusu połączenia + link do Połączeń | Złączone po `platform_connection_id` z listy połączeń (**L5**). Gdy `null`: „—" + (przy `publishes_publicly`) `Badge warning` „Nie wybrano" |
| `scheduled_at` | Termin | chwila w strefie zespołu | Gdy `draft`: dopisek „(nieuzbrojony)" |
| `published_at` | Opublikowano | chwila | **Pokazywane tylko, gdy niepuste**; obok terminu, nie zamiast (§5.1) |
| `attempts` | Prób | `attempts`, `tabular-nums` | Gdy `0`: „—" |
| `last_attempt_at` | Ostatnia próba | chwila | |
| `remote_id` | Identyfikator na platformie | `font-next-mono text-next-xs` + `Button icon-xs` „Kopiuj" | Tylko gdy niepuste |
| `creator` | Utworzył | `CreatorBadge` | Bywa `workflow_run` / `bot` |
| `created_at` / `updated_at` | Utworzono / Zmieniono | chwile | |

**`role="status"` + odświeżanie przy `publishing`:** dopóki `status === 'publishing'`,
widok odpytuje `GET /publications/{id}` co **15 s**, maksymalnie **20 razy** (5 min), i
przestaje. Zdanie pod pasmem mówi o tym wprost: *„Odświeżamy ten ekran co kilkanaście
sekund."* Po wyczerpaniu prób: *„Przestaliśmy odświeżać. Odśwież stronę, żeby sprawdzić."*
Zakaz odpytywania w nieskończoność — `publishing` potrafi trwać do 15 minut
(`stale_after`), a strona zostawiona otwarta na noc nie może odpytywać 5 760 razy.

### 7.4 Modal planowania

`Modal size="sm"`, tytuł: „Zaplanuj publikację" / „Zaplanuj ponownie" / „Zmień termin".

```
┌ Zaplanuj publikację ──────────────────────────────┐
│                                                   │
│  Termin  [ DateTimePicker ]                       │
│          Godzina w strefie zespołu: Europe/Warsaw │
│                                                   │
│  Po zaplanowaniu ta publikacja pójdzie w świat    │
│  automatycznie. Opublikowanego posta nie da się   │
│  stąd wycofać.                                    │
│                                                   │
│         [Anuluj]  [Opublikuj teraz]  [Zaplanuj]   │
└───────────────────────────────────────────────────┘
```

| Tryb | Wywołanie | Kiedy |
| --- | --- | --- |
| „Zaplanuj" / „Zaplanuj ponownie" | `POST …/schedule {scheduled_at}` | `status ∈ {draft, failed, blocked}` |
| „Zmień termin" | `PUT …` z **kompletem pól** i nowym `scheduled_at` (§6.6) | `status === 'scheduled'` — bo ruch w ten sam status **nie jest krawędzią** (D5) |

- **„Opublikuj teraz"** (`variant="secondary"`) ustawia chwilę na teraz i wysyła `POST
  …/schedule`. Tolerancja 60 s po stronie serwera jest tym, co czyni to legalnym. **Zawsze
  z `useConfirm()`**: „Opublikować teraz? Publikacja ruszy przy najbliższym przebiegu, w
  ciągu minuty. Opublikowanego posta nie da się stąd wycofać." Widoczne **tylko** dla
  `publishes_publicly === false` **albo** po potwierdzeniu — patrz niżej.
- Przy `publishes_publicly === true` **oba** przyciski w tym modalu są nieodwracalne i
  zdanie nad nimi mówi to wprost. Przy `dry_run` zdanie brzmi inaczej: *„To próba —
  nic nie opuści aplikacji."* (jedno, świadome rozgałęzienie copy na `publishes_publicly`).
- 422 `scheduled_in_the_past` → komunikat serwera **przy polu**, modal zostaje otwarty.
- 422 `publication_*` → `Alert` w modalu z prozą serwera (§2.4); dla `lost_race` dodatkowo
  „Odśwież".
- Przycisk zatwierdzający w stanie `loading`, modal `closeOnEsc=false` i `closeOnScrim=false`
  **w trakcie żądania** — żeby Escape nie zostawił użytkownika bez odpowiedzi na pytanie,
  czy coś właśnie wystartowało.

### 7.5 Usuwanie — dwa różne zdania, bo to dwie różne rzeczy

`useConfirm()` + `ConfirmDialog variant="danger"`.

| Kiedy | Tytuł | Treść |
| --- | --- | --- |
| `status ∈ {draft, failed}` | „Usunąć publikację?" | „Trafi do kosza. Nic nie zostało nigdzie wysłane." |
| `status === 'scheduled'` | „Usunąć zaplanowaną publikację?" | „**To jedyny sposób, żeby ją zatrzymać.** Trafi do kosza i nie pójdzie w świat. Wróci jako element kosza, nie jako szkic." (**L2**) |
| `status === 'blocked'` | „Usunąć wstrzymaną publikację?" | „Trafi do kosza. Jeśli naprawisz połączenie **po** usunięciu, ta publikacja już nie wróci." |
| `status === 'published'` | **„Usunąć nasz zapis o tej publikacji?"** | „**Post zostaje na platformie.** Usuwasz tylko nasz zapis o nim — po tym nikt tutaj nie odpowie, skąd ten post się wziął ani kto go zlecił." |

Ostatni wiersz jest najważniejszy: `published` **jest** usuwalny, co obok nieusuwalności
`needs_reconcile` wygląda niespójnie do chwili, gdy się to powie wprost — usunięcie chowa
**nasz zapis** i nie zmienia niczego w świecie, podczas gdy edycja zostawiłaby zapis, który
aktywnie kłamie. Potwierdzenie ma powiedzieć dokładnie to.

`publishing` i `needs_reconcile`: **przycisk usuwania nie istnieje** (`can_be_deleted:
false`), a zdanie w pasmie / panelu mówi dlaczego (§8.5).

---

## 8. `needs_reconcile` — najważniejszy ekran modułu

### 8.1 Dlaczego to osobny projekt, a nie kolejna plakietka

Każdy inny status odpowiada na pytanie „co się stało". Ten odpowiada: **nie wiemy, i nikt
tutaj nie wie.** Publikacja mogła już pójść w świat. Odruch produktowy — duży przycisk
„Ponów" — jest tu dokładnie tą akcją, której cały moduł istnieje, żeby zabronić: drugi post,
którego nic napisane w tej aplikacji nie cofnie.

Dlatego zamiast pasma (§7.2) ten status dostaje **panel** na pełną szerokość, nad obiema
kolumnami, z jedną akcją i z jawnym wykazem tego, czego tu nie ma.

### 8.2 Panel

`pages/publishing/ReconcilePanel.vue`. Powierzchnia: `bg-next-danger-subtle`,
`border border-next-danger/30`, `rounded-next-xl`, `p-next-6`, `role="alert"`.

```
┌ ❓ ───────────────────────────────────────────────────────────────────────┐
│                                                                          │
│  Nie wiemy, czy ta publikacja poszła w świat                             │
│                                                                          │
│  Ta publikacja została wzięta do publikacji i nic nie wróciło. Sprawdź    │
│  platformę, zanim zaplanujesz ją ponownie — może już być opublikowana.    │
│                                                                          │
│  Wzięta do publikacji: 10 wrz, 09:00 · czekała ponad 15 min · prób: 1     │
│                                                                          │
│  ┌──────────────────────────────────────────────────────────────────┐    │
│  │ [ 🔍 Sprawdź na platformie ]                                     │    │
│  │ Zapytamy platformę, czy ten post istnieje. Nic nie zostanie      │    │
│  │ opublikowane ani zmienione na platformie.                        │    │
│  └──────────────────────────────────────────────────────────────────┘    │
│                                                                          │
│  Sprawdzamy to też automatycznie, mniej więcej raz na godzinę.            │
│                                                                          │
└──────────────────────────────────────────────────────────────────────────┘
```

| Element | Źródło | Reguła |
| --- | --- | --- |
| Ikona | `help-circle` | **Nie `alert-triangle`.** Znaczenie tego stanu to pytanie, nie ostrzeżenie. Kolor mówi „pilne", glif mówi „nie wiadomo". |
| Nagłówek | katalog frontu | `<h2>`, `text-next-xl`. **Nie** `status_label` („Do sprawdzenia") — plakietka w nagłówku strony już to mówi; panel ma powiedzieć, co to znaczy. |
| Zdanie | **przetłumaczony `failure_code`** | Trzy możliwe: `reaper_stale`, `publish_worker_failed`, `publish_outcome_unknown`. Każde ma własną, gotową sentencję (§2.5). Nieznany kod → fallback. |
| Linia faktów | `last_attempt_at`, `failure_context.stale_after_seconds`, `attempts` | Klucze `failure_context` **spoza białej listy nie renderują się** (§2.5). Brak `last_attempt_at` → człon pomijany, nie „—". |
| Akcja | `Button variant="primary" leading-icon="search"` | `v-if="publication.can_be_reconciled"`. **Jedyna** akcja w panelu. |
| Zdanie pod akcją | katalog frontu | Mówi, czego przycisk **nie** robi. To jest połowa jego wartości. |
| Stopka | katalog frontu | O automatycznym sprawdzaniu — żeby „nic nie klikam" też było strategią, a nie zaniechaniem. |

Gdy `can_be_reconciled === false` (nie twórca i nie właściciel workspace'u): przycisk
**znika**, a na jego miejsce wchodzi zdanie: *„Sprawdzić może autor tej publikacji albo
właściciel przestrzeni."* Nigdy wygaszony przycisk bez powodu.

### 8.3 Trzy wyniki, z których jeden NIE jest błędem

Wszystkie trzy to `200` z `PublicationResource`; rozróżnia je `status` **w odpowiedzi**.

| Wynik | Rozpoznanie | Reakcja UI | Ton |
| --- | --- | --- | --- |
| **Znaleziony** | `res.status === 'published'` | Panel znika, ekran przerysowuje się jako `published` z `remote_url`. `toast.success`: „Post istnieje na platformie. Publikacja oznaczona jako opublikowana." Pasmo sukcesu dostaje dodatkowe zdanie: *„Czas publikacji ustalono przy sprawdzaniu — może różnić się od zaplanowanego."* | `success` |
| **Potwierdzony brak** | `res.status === 'failed'` (zwykle `failure_code: 'reconciled_absent'`) | Panel znika, ekran przerysowuje się jako `failed`; pasmo niesie sentencję `reconciled_absent` (*„Sprawdziliśmy platformę: nic nie zostało opublikowane, więc można bezpiecznie spróbować ponownie."*) i **dopiero teraz** pojawia się „Zaplanuj ponownie". `toast.info` — **nie `danger`**: to jest dobra wiadomość. | `info` |
| **Nadal nie wiadomo** | `res.status === 'needs_reconcile'` (wiersz wrócił **bez zmian**) | Panel **zostaje**. Pod przyciskiem dochodzi `Alert variant="info"` (`role="status"`): *„Tym razem nie udało się tego ustalić — platforma nie odpowiedziała na pytanie. **Nic się nie zmieniło i nic nie zostało opublikowane.** Spróbuj za chwilę albo poczekaj na automatyczne sprawdzenie."* | `info` |

> **Trzeci wynik to nie awaria i nie wolno go pokazać jako awarii.** Żądanie się udało,
> serwer odpowiedział `200`, stan jest poprawny — po prostu nikt się niczego nie dowiedział.
> `toast.danger` albo czerwony `Alert` nauczyłyby, że sprawdzanie „psuje się", a to jedyna
> akcja, którą w tym stanie warto powtarzać. **Zakaz:** liczników prób w rodzaju „próba 3 z
> 5", pasków postępu i jakiejkolwiek eskalacji — kontrakt mówi wprost, że wiersz może tu
> zostać na zawsze i że to jest **uczciwy** stan dla czegoś, na co nikt nie umie odpowiedzieć.

Po każdym wyniku: `GET` już nie jest potrzebny — odpowiedź `POST …/reconcile` **jest**
pełnym zasobem; store podmienia wiersz w miejscu (`upsertIntoList`), żeby lista pod spodem
też była aktualna.

### 8.4 `429`, `422 lost_race`, `403`

**`429` (throttle `6,1,publishing-reconcile`)** — obsłużyć **uczciwie**, nie jako „coś
poszło nie tak":

- odczyt nagłówka `Retry-After` (sekundy); gdy go brak → 60 s;
- przycisk przechodzi w `disabled` z **odliczaniem w etykiecie**: „Sprawdź za {n} s"
  (`tabular-nums`), `aria-live="polite"` co 10 s, nie co sekundę;
- pod przyciskiem `Alert variant="warning"`: *„Za dużo sprawdzeń pod rząd. Każde pytanie
  zużywa limit platformy wspólny dla całej aplikacji, więc odliczamy chwilę."* — to jest
  prawdziwy powód i jedyny, który tłumaczy, dlaczego limit jest tak niski;
- po odliczeniu przycisk wraca sam, bez przeładowania.

**`422 publication_transition_lost_race`** — ekran jest nieaktualny, nie zepsuty. Renderować
`message` z serwera (*„Coś zajęło się już tą publikacją — jest teraz w stanie „X". Nic nie
zostało zmienione. Odśwież, żeby zobaczyć, na czym stoi."*) jako `Alert variant="info"` z
**jedną** akcją: `Button` „Odśwież" → `GET /publications/{id}` → przerysowanie ekranu wg
nowego statusu. Zakaz automatycznego przeładowania bez kliknięcia: człowiek ma przeczytać,
że jego kliknięcie nic nie zmieniło.

**`403`** — nie powinno się zdarzyć (bramkujemy na `can_be_reconciled`). Gdy się zdarzy:
cichy refetch + przerysowanie panelu; jeśli po refetchu flaga nadal `true`, `toast.danger`
z komunikatem serwera. To jest sygnał rozjazdu flagi z polityką i ma zostać widoczny.

### 8.5 Czego w tym panelu NIE MA — i dlaczego to widać

Brak akcji, o który ktoś zapyta, jest gorszy niż brak akcji, który jest wyjaśniony. Pod
panelem, w `Accordion` zwiniętym domyślnie, nagłówek *„Dlaczego nie mogę tego po prostu
ponowić?"*:

> Ta publikacja mogła już zostać opublikowana — straciliśmy kontakt, zanim udało się to
> potwierdzić. Ponowna publikacja mogłaby wystawić ją **drugi raz**, a opublikowanego posta
> nie da się stąd wycofać. Dlatego z tego stanu prowadzi tylko jedna droga: zapytać
> platformę. Jeśli okaże się, że nic nie powstało, planowanie znów będzie możliwe.
>
> Z tego samego powodu tej publikacji **nie da się teraz edytować** (zapis przestałby
> odpowiadać postowi, który być może istnieje) ani **usunąć** (usunięcie zostawiłoby post,
> którego nikt nie umiałby przypisać).

Trzy zdania, trzy brakujące przyciski, każdy nazwany. Treść jest **wierną parafrazą**
sentencji `transitions.reconcile_before_retry` z katalogu serwera — ta sama myśl, w miejscu,
w którym człowiek jej potrzebuje, zanim naciśnie cokolwiek.

---

## 9. Ekran D — Połączenia

`pages/publishing/ConnectionsView.vue`, trasa **przypięta w konfiguracji** (§3.2).

### 9.1 Wyjątek od `FilterBar` + zapisanych widoków

Patrz **D8**. Ten ekran **nie ma** `FilterBar` ani `FilterTabBar` — ma zamiast tego
**siatkę kart per miejsce docelowe**, która pełni funkcję nawigacji („bento"): zbiór jest
zamknięty, znany z góry i mieści się na jednym ekranie. **Wymaga zatwierdzenia właściciela.**

### 9.2 Struktura

```
PageHeader  (icon="link-2", title="Połączenia",
             description="Konta, na które ta przestrzeń może publikować.")
  #actions: [ Button ghost leading-icon="rotate-ccw" „Odśwież" ]

[ BANER powrotu z OAuth ]        ← §10, tylko po powrocie z platformy
[ Alert danger: nieczytelne poświadczenia ]  ← §9.6, gdy dotyczy

┌ YouTube ─────────┐ ┌ Instagram ───────┐ ┌ Facebook ────────┐
│ 🎬 YouTube       │ │ 🖼 Instagram     │ │ 👥 Facebook      │
│                  │ │                  │ │                  │
│ ● Taskio Demo    │ │  Brak konta      │ │ ● Strona Firmy   │
│   [Połączone]    │ │                  │ │   [Wymaga pon…]  │
│   UCxxxxxxxx     │ │  Nic tu jeszcze  │ │   1029384…       │
│   Odnowiono 2 dni│ │  nie trafia.     │ │   ⚠ Platforma    │
│   Uprawnienia (2)│ │                  │ │     odmówiła…    │
│   [⋯ Rozłącz]    │ │ [Połącz konto]   │ │ [Połącz ponownie]│
│                  │ │                  │ │ [⋯ Rozłącz]      │
│ [+ Kolejne konto]│ │                  │ │                  │
└──────────────────┘ └──────────────────┘ └──────────────────┘
```

Jedno pobranie: `GET /publishing/connections` (bez paginacji), grupowanie po `platform`
**po stronie klienta** — parametr `platform[]` nie jest używany, bo karta i tak potrzebuje
wszystkich grup naraz.

**Karta platformy** (`Card variant="default"`) istnieje dla **każdego** publicznego miejsca
docelowego (`youtube`, `instagram`, `facebook`) — również dla tych bez połączeń.
**`dry_run` nie dostaje karty** — nie ma konta do połączenia, a karta z wyjaśnieniem
„tego nie da się połączyć" byłaby trzema zdaniami o czymś, czego nikt nie próbował zrobić.
Lista trzech platform pochodzi z jednego miejsca (`publishingMeta.ts`, **L1**).

### 9.3 Karta konta (wiersz wewnątrz karty platformy)

| Element | Treść | Reguła |
| --- | --- | --- |
| Status | `Badge` z `status_tone` (**L8**: `'muted'` → wariant `neutral`), ikona: `active`→`check-circle`, `needs_reauth`→`alert-circle`, `revoked`→`x-circle`; tekst = `status_label` | kolor nigdy sam |
| Nazwa | `account_name`, `font-next-medium` | |
| Identyfikator | `external_account_id`, `font-next-mono text-next-2xs text-next-muted-foreground` | Jedyne, co odróżnia dwa konta o tej samej nazwie — **nie ukrywać** |
| Ważność | `expires_at` → „Wygasa {data}". **`null` → „Platforma nie podała terminu ważności."** | **Nigdy „wygasło"** dla `null` |
| Odnowienie | `last_refreshed_at` → „Odnowiono {data}" | pomijane, gdy `null` |
| Powód awarii | przetłumaczony `failure_code` (`connection_failures.*`) | tylko gdy niepuste **i** status ≠ `active` |
| Uprawnienia | `Accordion` „Uprawnienia ({n})" → lista `scopes` jako `font-next-mono text-next-xs` | Zwinięte domyślnie. To jedyne wyjaśnienie późniejszej odmowy „na uprawnieniach" — dlatego są, mimo że są techniczne |
| Twórca | `CreatorBadge` | bywa `workflow_run` |
| Akcje | `Połącz ponownie` (gdy `needs_attention` lub `!credentials_readable`) · kebab z `Rozłącz` (`v-if="can_be_disconnected"`) | Kolejność trailing: warunkowy przycisk **przed** stałym kebabem |

**`revoked`**: rozłączone konta **nie znikają** z ekranu, ale lądują w zwiniętej sekcji na
dole karty platformy — „Rozłączone konta (N)". Powód: wiersz przeżywa rozłączenie właśnie
po to, żeby opublikowane publikacje nadal wskazywały konto, na którym poszły; ekran, na
którym po rozłączeniu wszystko znika, każe myśleć, że historia też.

### 9.4 Łączenie

1. `Button variant="primary" leading-icon="plus"` — „Połącz konto" (pusta karta) /
   „Połącz kolejne konto" (karta z kontami) / „Połącz ponownie" (`needs_reauth`).
2. `POST /publishing/connections/{platform}/authorize`. Przycisk w stanie **`loading`**
   (spinner zamiast ikony wiodącej, etykieta zostaje, szerokość stabilna, `aria-busy`) —
   i **zostaje w tym stanie** aż do opuszczenia strony, bo następnym krokiem jest nawigacja.
3. Sukces → **`window.location.assign(res.data.authorize_url)`** (D9). Żadnego
   `window.open`, żadnego podążania `fetch`em.
4. Odmowy — renderowane **na karcie tej platformy**, nie jako toast (to stan trwały,
   nie zdarzenie):

| Kod z 422 | Zachowanie |
| --- | --- |
| `platform_not_configured` | `Alert variant="warning" size="sm"` z **prozą serwera** („Ta aplikacja nie jest jeszcze zarejestrowana na tej platformie…"). Przycisk „Połącz" **zostaje widoczny, ale `disabled`**, a powód stoi obok — nie znika, bo to stan instalacji, nie brak uprawnień użytkownika. |
| `platform_not_connectable` | Nieosiągalne z tego ekranu (`dry_run` nie ma karty). Gdyby przyszło — ten sam `Alert`, proza serwera. |
| `403` / inne | `toast.danger` z `message` serwera. |

> **Sprawdzenie „czy ta platforma jest w ogóle skonfigurowana" nie istnieje przed
> kliknięciem (L6).** Zasób połączeń nie mówi, dla których miejsc docelowych ta instalacja
> ma `client_id`/`client_secret`, więc UI **nie może** z góry wygasić przycisku — łamie to
> regułę „nie oferuj przycisku, którego zapis odmówi" w jedynym miejscu tej specyfikacji, w
> którym nie da się jej dotrzymać. Zachowanie interim: kliknięcie → 422 → zdanie serwera,
> które mówi, co zrobić, **i przycisk zostaje disabled do końca sesji ekranu**, żeby drugie
> kliknięcie nie powtórzyło tej samej lekcji. Rekomendacja do backendu: **L6**.

### 9.5 Rozłączanie

`useConfirm()` + `ConfirmDialog variant="danger"`, tytuł: „Rozłączyć konto {account_name}?"

> **Zaplanowane publikacje na to konto zostaną wstrzymane** i nie pójdą w świat.
> Opublikowane wpisy zostają — i na platformie, i u nas.
>
> Jeśli połączysz to samo konto ponownie, wstrzymane publikacje **wrócą na swoje terminy
> automatycznie**. Te, których termin minął w międzyczasie, pójdą w świat **od razu** po
> ponownym połączeniu.

Trzy rzeczy w tym tekście są celowe:

1. **Nie ma liczby wstrzymanych publikacji**, bo jej nie mamy (**L11**). „Wszystkie
   zaplanowane" jest prawdziwe; „0 publikacji" albo zgadnięta liczba byłyby kłamstwem
   w miejscu, w którym kłamstwo kosztuje niewysłane posty.
2. **Obietnica powrotu jest prawdziwa** — `PlatformConnectionManager::connect()` wywołuje
   `releaseQueue()`, który zdejmuje **tylko własne** kody wstrzymania i przywraca zapamiętane
   `scheduled_at`.
3. **Ostrzeżenie o przeterminowanych jest prawdziwe i nieoczywiste.** Przywrócenie uzbraja
   na **oryginalną** chwilę; jeśli ta już minęła, najbliższe zamiatanie (co minutę) weźmie
   taki wiersz natychmiast. To jest jedyny moment w całym module, w którym coś idzie w świat
   jako **skutek uboczny** naprawy czegoś innego — i musi być powiedziane przed, nie po.
   **Do potwierdzenia przez właściciela**, czy takie zachowanie zostaje (patrz raport B7).

Po `204`: `toast.success` „Konto rozłączone." + refetch listy. Konto pojawia się w sekcji
„Rozłączone konta".

### 9.6 `credentials_readable === false` — incydent, który ten ekran ma przeżyć

Gdy **którekolwiek** połączenie ma `credentials_readable: false`, nad siatką staje
`Alert variant="danger"` (`role="alert"`), niezamykalny:

> **Zapisanego dostępu do części kont nie da się już odczytać.** Nic nie zostało utracone
> na platformach — to aplikacja przestała umieć otworzyć własny zapis. Połącz te konta
> ponownie; publikacje czekające na nie są wstrzymane do tego czasu.

Karty dotkniętych kont: powierzchnia `bg-next-danger-subtle`, przetłumaczony
`credentials_unreadable`, jedyna akcja „Połącz ponownie". **Ekran renderuje się normalnie
w każdym innym aspekcie** — to jest cała racja istnienia tej flagi: to jest ekran, na który
przychodzi się naprawić dokładnie ten problem, więc nie może być jego ofiarą.

---

## 10. Powrót z OAuth — czternaście kodów

### 10.1 Odczyt

Callback przekierowuje na `oauth.return_path` z parametrami:

```
sukces:   /next/publishing/connections?connection=connected&platform=youtube
porażka:  /next/publishing/connections?connection=failed&platform=youtube&reason=<kod>
```

> **Nazwa parametru to `connection`, nie `connect`.** Stała `RESULT_KEY = 'connection'` w
> `PlatformOAuthCallbackController`. Wartości: `connected` / `failed`.

Reguły odczytu, wszystkie obowiązkowe:

1. Odczyt **raz**, przy montowaniu widoku (`onMounted`), przed pierwszym renderem banera.
2. **Natychmiastowe zdjęcie parametrów z URL** (`router.replace` bez `connection`/`platform`/
   `reason`, z zachowaniem reszty) — inaczej „wstecz" wskrzesza baner sprzed kwadransa.
3. `platform` **może być nieobecny** (kontroler filtruje `null` przy `unknown_platform`) —
   każde zdanie i każda akcja musi działać bez niego.
4. `reason` **nie jest walidowany względem zamkniętej listy przez backend**: dowolny kod
   błędu zgłoszony przez platformę (np. `server_error`) przechodzi **dosłownie**, obcięty
   do 64 znaków. Katalog frontu **musi** mieć fallback.
5. Po obu wynikach: **refetch** `GET /publishing/connections`.

### 10.2 Sukces

`toast.success({ title: t('publishing.oauth.connected'), description: platformLabel })` →
*„Konto połączone."* / *„YouTube"*. Dowód i tak pojawia się na karcie po refetchu, więc
toast jest właściwym naczyniem (D10).

### 10.3 Porażka — baner, tabela czternastu kodów

`Alert` przypięty nad siatką, `dismissible`, `role="alert"`. Tytuł zawsze:
`publishing.oauth.failed` → *„Nie udało się połączyć konta."* Treść: zdanie dla `reason`.
Akcja „Połącz ponownie" pojawia się **tylko tam, gdzie lekarstwo należy do użytkownika i
znamy platformę**.

| `reason` | Wariant | Akcja „Połącz ponownie" | Uwaga |
| --- | --- | --- | --- |
| `access_denied` | `warning` | tak | Użytkownik **odmówił** — nic się nie zepsuło. Czerwień byłaby oskarżeniem. |
| `missing_code` | `warning` | tak | |
| `oauth_state_malformed` | `warning` | tak | |
| `oauth_state_bad_signature` | `warning` | tak | **Nie nazywać ataku.** Najczęstsza droga tutaj to skopiowany odnośnik. |
| `oauth_state_expired` | `warning` | tak | |
| `oauth_state_already_used` | `warning` | tak | |
| `oauth_state_platform_mismatch` | `warning` | tak (jeśli `platform` znany) | |
| `oauth_browser_mismatch` | `warning` | tak | Zdanie katalogu mówi „zostań w tym oknie" — baner **dodaje** przycisk, który startuje w tym oknie. |
| `token_exchange_failed` | `danger` | tak | |
| `token_response_unusable` | `danger` | tak | Google bez refresh tokena. Zdanie mówi „zaakceptuj wszystkie uprawnienia". |
| `account_lookup_failed` | `danger` | tak | |
| `connection_failed` | `danger` | tak | Zdanie samo mówi, kiedy to sprawa administratora. |
| `workspace_unavailable` | `danger` | **nie** | Lekarstwo jest gdzie indziej (dostęp do przestrzeni), nie w powtórzeniu. |
| `unknown_platform` | `danger` | **nie** | `platform` bywa nieznany — nie ma czego powtórzyć. |
| **nieznany / przepuszczony kod platformy** | `danger` | tak (jeśli `platform` znany) | `publishing.oauth.failures.unknown`: *„Platforma odmówiła połączenia i nie wyjaśniła tego w sposób, który ta aplikacja rozpoznaje. Spróbuj ponownie."* + kod jako `font-next-mono text-next-2xs text-next-muted-foreground` w drugiej linii. **Nigdy pusty baner, nigdy sam surowy klucz jako zdanie.** |

Wszystkie czternaście zdań to **wierne kopie** katalogu serwerowego
(`lang/{pl,en}/publishing.php` → `oauth_failures`) w katalogu frontu (**L9**). Żadne z nich
nie powtarza prozy platformy i żadne nie nazywa ataku — to są właściwości tych zdań, nie
przypadek, i nie wolno ich „poprawić" przy przepisywaniu.

---

## 11. Akceptacje (ZALEŻNE od B6)

> **Cała ta sekcja zależy od batcha B6**, który dopiero ma uczynić `Publication`
> `Approvable` wzorem `Task`. **B8 nie buduje jej, dopóki B6 nie wyląduje** — do tego czasu
> zakładka „Akceptacje" w `PageHeader #tabs` **nie renderuje się wcale** (nie „wkrótce":
> pusta zakładka z obietnicą to dokładnie ten dług, który R0 musiało spłacać na Dashboardzie).

Gdy B6 wyląduje, zakładka `next.publishing.publication.approval` odwzorowuje zakładkę
akceptacji Zadania — **z tych samych, już wyekstrahowanych części**, nie przez skopiowanie
szablonu:

| Element | Źródło do reużycia |
| --- | --- |
| Model zakładki (stopnie, bieżący stopień, historia osierocona) | `pages/tasks/approvalTabModel.ts` → `buildApprovalTabModel(stages, pendingProcess, groupedHistory)` |
| Grupowanie historii przebiegu | `groupRunHistory` z `app/stores/approvalQueue.ts` |
| Rozwiązanie zatwierdzającego | `resolveApprover` / `approverTypeIcon` z `pages/approvals/approver.ts` |
| Ikona pipeline'u | `resolvePipelineIcon` z `ui/forms/pipelineIcon.ts` |
| Mapa statusów decyzji | `approvalStatus.ts` z `pages/approvals/` |
| Pobranie historii | bezpośrednio `GET /approvals/runs/{runId}` przez `api` (tak jak robi szuflada Zadania — po to, żeby własny stan ładowania/błędu był odcięty od cyklu życia kolejki) |

Co pokazuje: nagłówek pipeline'u (ikona + nazwa) · pionowy stepper stopni z rozwiązanym
statusem (`pending` / `approved` / `rejected` / `upcoming`) · zatwierdzający per stopień ·
decyzje z notatką i czasem · **`Alert info`, gdy pipeline jest podpięty, ale publikacja nie
jest obecnie w akceptacji** · przycisk „Zobacz w Akceptacjach" → `next.approvals.queue`.

**Wszystko read-only.** Decyzje zapadają w module Akceptacji, nigdy tutaj — to jest
niezmiennik istniejącej implementacji i nie ma powodu, żeby Publikacje go łamały.

### 11.1 Zamrożenie afordancji pod aktywną akceptacją — czyja to robota

**Frontend nie dokłada własnej reguły.** Jeśli B6 robi swoją część dobrze,
`PublicationPolicy::update()` / `::schedule()` uwzględnią aktywną akceptację, a
`can_be_edited` / `can_be_scheduled` przyjdą jako `false` — i cały ekran zamilknie sam,
przez bramki, które już opisano w D5.

Rola B8 sprowadza się do **zdania**, nie do warunku: gdy publikacja jest w aktywnej
akceptacji **i** flaga jest `false`, pasmo stanu (§7.2) dostaje dodatkową linię —
*„Wstrzymane na czas akceptacji: {nazwa pipeline'u}."* z linkiem do zakładki.

> **Jeśli B6 wyląduje, a flagi nadal będą `true` przy aktywnej akceptacji — to defekt
> backendu do zgłoszenia, a nie warunek do dopisania po stronie frontu.** Frontendowa
> reguła „ukryj Edytuj, gdy jest aktywna akceptacja" byłaby **drugim modelem autoryzacji**
> obok polityki, a drugi model jest tym, który się rozjeżdża. To ten sam argument, którym
> D5 uzasadnia, że trzy istniejące domknięcia są *nazwane i policzone*, a nie mnożone.

---

## 12. Stany: ładowanie, pusto, błąd, sukces

### 12.1 Ładowanie — szkielet imituje realny element, i jest ich kilka

| Ekran | Szkielet |
| --- | --- |
| Lista publikacji | **6 × szkielet karty**: `circle` 40 px (bąbel) + `text` 60 % (tytuł) + 2 × `text` 90 %/70 % (podtytuł) + `rect` 20 px (plakietka, prawy górny) + `text` 40 % (stopka metadanych). Realizacja: `EntityCard loading` w pętli. |
| Taby | Plakietki liczników jako `Skeleton variant="text" width="1.5rem"` — **etykiety tabów renderują się od razu** (są statyczne), liczy się tylko liczba. |
| Szczegół | Pasmo stanu jako `rect` pełnej szerokości `h-20`; lewa kolumna: `text` 40 % + 6 × `text`; prawa: 8 wierszy `DescriptionList` jako pary `text` 30 % / `text` 50 %. |
| Połączenia | **3 × szkielet karty platformy** (nagłówek `text` 30 % + 2 wiersze konta). Zawsze trzy — bo zawsze będą trzy. |
| Media w kompozytorze | Kafelek: `rect` 64×64 + `text` 70 %, tyle sztuk, ile uuidów w `media`. |

`Skeleton` kontenera dostaje `label` → jeden uprzejmy `role="status"` na region.
**Zakaz `Spinner` + „Ładowanie…" jako stanu głównego** któregokolwiek z tych ekranów.
`Spinner` zostaje tam, gdzie jest właściwy: wewnątrz `Button loading`.

### 12.2 Pusto

| Sytuacja | Zachowanie |
| --- | --- |
| Lista, brak filtrów, tab `all`, `total === 0` | `EmptyState variant="default" icon="send"` · „Nic tu jeszcze nie ma" · „Publikacja to zapowiedź tego, co i kiedy pójdzie w świat. Zacznij od szkicu — nic nie zostanie wysłane, dopóki tego nie zaplanujesz." · `#action`: **Nowa publikacja** · `#secondary`: link „Najpierw połącz konto" |
| Lista, aktywne filtry lub szukanie | `EmptyState variant="search"` · „Brak wyników dla tych filtrów" · `#action`: **Wyczyść filtry** |
| Tab `draft`, pusto | „Brak szkiców" · „Szkic to publikacja, która jeszcze nigdzie nie idzie." · `#action`: **Nowa publikacja** |
| Tab `scheduled`, pusto | „Nic nie czeka na swój termin" · „Zaplanowane publikacje pokażą się tutaj i na Kalendarzu." · `#secondary`: „Otwórz Kalendarz" |
| Tab `publishing`, pusto | „Nic nie jest teraz wysyłane" · „To normalne — publikacja spędza tu sekundy." · **bez akcji** |
| Tab `published`, pusto | „Nic jeszcze nie poszło w świat" · „Tu wyląduje wszystko, co zostało opublikowane — razem z odnośnikiem do posta." |
| Tab `failed`, pusto | „Nic nie zawiodło" · „Tu trafiają publikacje, o których **wiemy**, że nic nie powstało — i które można bezpiecznie zaplanować ponownie." |
| Tab `needs_reconcile`, pusto | „Nic nie czeka na sprawdzenie" · „Tu trafiają publikacje, o których **nie wiemy**, czy poszły w świat. Pusto to dobra wiadomość." |
| Tab `blocked`, pusto | „Nic nie jest wstrzymane" · „Publikacja trafia tutaj, gdy jej konto przestaje działać — żeby nie zamieniła się w dwanaście błędów o jednej przyczynie." |
| Połączenia, zero połączeń w ogóle | **Bez `EmptyState` na całym ekranie.** Trzy karty platform **są** treścią; każda ma własny stan pusty: „Brak konta" + „Nic tu jeszcze nie trafia." + `Button` „Połącz konto". Nakładka `EmptyState` zasłoniłaby dokładnie te trzy przyciski, po które ktoś przyszedł. |

> **Cztery ostatnie stany puste są różne, bo mówią różne rzeczy.** „Nic nie zawiodło"
> i „Nic nie czeka na sprawdzenie" wyglądają jak ta sama pustka i **nie są nią**: pierwsza
> mówi o wiedzy, druga o jej braku. Ten moduł ma dokładnie jedną rzecz do nauczenia — że
> `failed` i `needs_reconcile` to nie to samo — i stan pusty jest miejscem, w którym można
> to powiedzieć bez kosztu.

### 12.3 Błąd całego zapytania

`EmptyState variant="error"` (`role="alert"`) zamiast listy: „Nie udało się wczytać
publikacji" + treść z `err.response.data.message` (gdy jest) + `#action` **Spróbuj ponownie**.
`PageHeader`, `FilterBar` i taby **zostają aktywne** — zmiana filtra jest sama w sobie
ponowieniem.

Szczególne:

| Kod | Zachowanie |
| --- | --- |
| `400` (brak aktywnego workspace'u) | Nie renderujemy własnego zdania — to stan powłoki, nie modułu; `toast.danger` z treścią serwera. |
| `403` (obca przestrzeń) | `EmptyState error` „Nie masz dostępu do tej przestrzeni." |
| `404` na szczegółe | `EmptyState error` „Nie ma takiej publikacji" + „Mogła zostać usunięta." + `#action` „Wróć do listy". **Nie** „coś poszło nie tak" — 404 na uuidzie jest konkretną wiadomością. |
| błąd `GET /counts` przy działającej liście | Lista renderuje się normalnie, **plakietki tabów znikają** (nie pokazują `0`), a nad tabami `Alert warning sm`: „Nie udało się policzyć publikacji w tabach." + „Spróbuj ponownie". **Zakaz `count ?? 0`** — zamienia „nie wiemy" w „nic nie ma". |
| błąd `GET /connections` przy działającej liście publikacji | Select konta w kompozytorze pokazuje stan błędu z ponowieniem; karta „Wysyłka" na szczegółe renderuje `platform_connection_id` bez nazwy, z dopiskiem „nie udało się wczytać konta" — **nigdy „brak konta"**. |

### 12.4 Odświeżanie

Poprzednie dane **zostają na ekranie**, kontener dostaje `aria-busy="true"` i
`opacity-60 pointer-events-none`; kontrolki filtrów zostają aktywne. Wyścigi: store trzyma
monotoniczny `token` i odrzuca odpowiedzi starsze niż ostatni reset (wzorzec z
`app/stores/tasks.ts`).

### 12.5 Sukces zapisu

`useToast()`; **toast nie służy do błędów walidacji** (te są przy polach) ani do
nieodwracalnych rzeczy, które muszą zostać na ekranie (te są w pasmach i banerach).

| Zdarzenie | Toast |
| --- | --- |
| Utworzenie / zapis szkicu | `success` „Szkic zapisany" |
| Zaplanowanie | `success` „Zaplanowano na {termin}" |
| Zmiana terminu | `success` „Termin zmieniony" |
| Usunięcie (nieopublikowanej) | `success` „Publikacja przeniesiona do kosza" |
| Usunięcie opublikowanej | `success` **„Zapis usunięty. Post został na platformie."** |
| Sprawdzenie → znaleziony | `success` (§8.3) |
| Sprawdzenie → brak | `info` (§8.3) |
| Sprawdzenie → nadal nie wiadomo | **brak toasta** — komunikat zostaje w panelu (§8.3) |
| Połączenie konta | `success` (§10.2) |
| Rozłączenie | `success` „Konto rozłączone" |

---

## 13. Kolory, tokeny, dark mode

### 13.1 Mapa `status` → ton → tokeny (i dlaczego `draft` nie ma tonu)

Serwer wybiera **znaczenie** (`status_tone`), design system wybiera piksele. Mapa
`tone → variant` żyje w **jednym** module `pages/publishing/publishingMeta.ts`, z fallbackiem
`neutral`, wzorem `pages/workflows/workflowMeta.ts` → `toneToVariant()`.

| `status` | `status_tone` | `Badge variant` | Ikona | Powierzchnia pasma |
| --- | --- | --- | --- | --- |
| `draft` | **`null`** | `neutral` (fallback) | `file-text` | `bg-next-muted` |
| `scheduled` | `info` | `info` | `clock` | `bg-next-info-subtle` |
| `publishing` | `warning` | `warning` | `loader` | `bg-next-warning-subtle` |
| `published` | `success` | `success` | `check-circle` | `bg-next-success-subtle` |
| `failed` | `danger` | `danger` | `x-circle` | `bg-next-danger-subtle` |
| `needs_reconcile` | `danger` | `danger` | **`help-circle`** | `bg-next-danger-subtle` + obwódka `danger/30` |
| `blocked` | `warning` | `warning` | `lock` | `bg-next-warning-subtle` |

> **`draft` niesie `null`, nie `'neutral'`, i to jest kontrakt kalendarza.** Szkic nie ma
> chwili, więc nie ma miejsca na osi czasu i nie trafia na siatkę; `null` jest **drugim,
> strukturalnym stwierdzeniem tego samego faktu**. Frontend degraduje `null` do `neutral`
> **jawnie**, w mapie, z komentarzem — nigdy przez `tone ?? 'neutral'` rozsiane po
> szablonach.

> **`failed` i `needs_reconcile` dzielą `danger` celowo** — dla czytającego to ta sama
> pilność („to wymaga mnie"). Różnicę niosą **ikona** (`x-circle` vs `help-circle`),
> **proza** i **zestaw akcji**, nie kolor. Nie wolno „poprawić" tego, nadając
> `needs_reconcile` inny odcień: kolor jest tu słownikiem znaczeń współdzielonym z
> Kalendarzem (`CalendarColor::fromTone`), a nie paletą tego ekranu.

> **`blocked` jest `warning`, mimo że jego przyczyna — `needs_reauth` na połączeniu — jest
> `danger`.** To nie jest niespójność: przyczyna jest jedyną rzeczą, którą da się naprawić,
> więc świeci mocniej niż jej dwanaście objawów. Ekran Połączeń jest czerwony, lista
> publikacji pomarańczowa, i to jest właściwy kierunek uwagi.

### 13.2 Kolor nigdy sam

| Nośnik | Drugi (i trzeci) sygnał |
| --- | --- |
| Plakietka statusu | ikona **+** `status_label` (proza serwera) |
| Akcent karty `needs_attention` | lewa krawędź **+** zdanie awarii w stopce |
| Pasmo stanu | ikona **+** nagłówek **+** zdanie |
| Baner OAuth | ikona `Alert` **+** tytuł **+** zdanie |
| Kafelek mediów „plik zniknął" | ikona `alert-triangle` **+** tekst |
| Plakietka nawigacji | cyfra **+** zdanie `sr-only` |
| Status połączenia | ikona **+** `status_label` **+** (gdy awaria) przetłumaczony powód |

### 13.3 Dark mode

Wyłącznie podmiana tokenów pod `.next-root.dark` — **zero odwracania kolorów w
komponentach**. Punkty do sprawdzenia wzrokowo w obu motywach:

| Element | Ryzyko | Wymóg |
| --- | --- | --- |
| Panel `needs_reconcile` (`danger-subtle` + obwódka) | W dark `danger-subtle` jest ciemne i blisko `card`; panel mógłby zniknąć | Obwódka `border-next-danger/30` **jest obowiązkowa**, nie ozdobna — to ona utrzymuje panel jako osobną powierzchnię w dark |
| Pasmo `warning-subtle` (`publishing`, `blocked`) | Najtrudniejszy kontrast tekstu w całym module | Tekst **wyłącznie** `text-next-warning-subtle-foreground`; sprawdzić AA ręcznie w obu motywach |
| **Miniatury mediów** | Przezroczysty PNG znika na ciemnej karcie | Kafelek dostaje **stałe** podłoże `bg-next-muted` pod obrazem (w obu motywach), nie `transparent` |
| Bąbel glifu miejsca docelowego | `bg-next-muted` na `card` — w dark oba są blisko | Hairline `border-next-border` na bąbelku |
| `font-next-mono` identyfikatory (`remote_id`, `external_account_id`, `scopes`) | `muted-foreground` w dark jaśnieje; ciąg znaków bez spacji bywa nieczytelny | `text-next-2xs` **plus** `break-all`, kontrast sprawdzony osobno |
| Ikona modułu (biało-na-primary) | `primary` w dark jaśnieje do 58 %, a `primary-foreground` staje się prawie-czarną magentą | **Nic nie robimy w komponencie** — tokeny już to załatwiają; wymóg to nie nadpisywać ich lokalnie |

---

## 14. Responsywność

| Breakpoint | Układ |
| --- | --- |
| ≥ `next-xl` | Szczegół dwukolumnowy 2/3 + 1/3. Połączenia: **3 kolumny**. Lista: karty pełnej szerokości. |
| `next-lg` … `next-xl` | Szczegół dwukolumnowy. Połączenia: **2 kolumny**. `ModuleAside` widoczny. |
| `next-md` … `next-lg` | Szczegół **jednokolumnowy**, „Wysyłka" **nad** „Treścią". Połączenia: 2 kolumny. `ModuleAside` znika → `ModuleTabs`. |
| < `next-md` | Wszystko jednokolumnowe. Połączenia: 1 kolumna. Szuflada kompozytora `size="full"`. Taby statusów przewijane poziomo. Stopka metadanych karty zawija do dwóch linii. Cele dotykowe ≥ 44 px. |

Szczegóły wąskiego ekranu:

- **Taby statusów nie zwijają się w Select.** Osiem pozycji z licznikami to pasek, po
  którym się skanuje; zamiana go w `Select` ukryłaby liczby, które są tu połową informacji.
  Zostaje poziome przewijanie z gradientami krawędzi (zachowanie `Tabs`), a aktywny tab
  jest przewijany do widoku.
- **Karta publikacji poniżej `next-md`**: plakietka statusu **schodzi pod tytuł**, a kebab
  zostaje w prawym górnym rogu — stałe kontrolki nie zmieniają miejsca (reguła kolejności
  afordancji), zmienia się tylko to, co warunkowe.
- **Kafelki mediów** w kompozytorze: `grid-cols-2` poniżej `next-md`, `grid-cols-4` wyżej;
  przyciski `▲▼✕` zostają w kafelku (nie w menu) — na dotyku menu kontekstowe dla zmiany
  kolejności jest dwoma krokami za dużo.
- **Pasmo stanu i panel `needs_reconcile`**: pełna szerokość na każdym breakpoincie; akcje
  z pasma schodzą **pod** zdanie poniżej `next-md` i stają się `fullWidth`.
- **Modal planowania**: `size="sm"` wszędzie; poniżej `next-md` stopka układa przyciski
  pionowo, z „Zaplanuj" **na górze** (kciuk sięga najdalej w dół, a najbardziej
  konsekwentna akcja nie powinna być tam, gdzie kciuk ląduje przypadkiem).

---

## 15. Dostępność

### 15.1 Role i anonsowanie

| Powierzchnia | Wymóg |
| --- | --- |
| Pasmo stanu `draft`/`scheduled`/`publishing`/`published` | `role="status"` (uprzejme) |
| Pasmo `failed` / `blocked`, panel `needs_reconcile` | `role="alert"` (asertywne) |
| Baner powrotu z OAuth | `role="alert"`; fokus **przenoszony na baner** po jego pojawieniu się (`tabindex="-1"` + `focus({preventScroll:true})`) — człowiek wrócił z innej strony i to jest jedyna nowa treść na ekranie |
| Baner „wymaga uwagi" (lista) | `role="alert"`, renderowany **raz** po wczytaniu; klucz stabilny, żeby nie migotał przy każdym re-renderze |
| Wynik sprawdzenia „nadal nie wiadomo" | `role="status"` (uprzejme) — **nie** `alert`; to nie jest awaria (§8.3) |
| Odliczanie throttle | `aria-live="polite"`, aktualizacja co 10 s, nie co sekundę |
| Lista publikacji | `role="list"` / `listitem`; karta = jeden punkt tabulacji (rozciągnięty link tytułu), kebab = drugi. Nic więcej |
| Taby | `role="tablist"` z `Tabs`; aktywacja `automatic`; licznik w `aria-label` taba („Do sprawdzenia, 3") |
| Kafelki mediów | `role="list"`; `aria-label` przycisków: „Przesuń w górę: {nazwa}", „Usuń: {nazwa}"; po ruchu `aria-live="polite"`: „{nazwa}: pozycja {i} z {n}" |
| Szuflada i modale | `role="dialog"` + `aria-modal`, focus-trap, `Esc`, powrót fokusu — daje `Drawer` / `Modal`; **nie wolno wyłączać `closeOnEsc` poza czasem trwania żądania** (§7.4) |
| Link zewnętrzny „Otwórz na platformie" | `rel="noopener noreferrer"`, ikona `external-link`, dostępna nazwa zawiera „otwiera się na platformie" |
| Identyfikatory `font-next-mono` | Przycisk „Kopiuj" ma `aria-label` z nazwą pola; wynik kopiowania anonsowany uprzejmie |

### 15.2 Klawiatura

| Powierzchnia | Zachowanie |
| --- | --- |
| Lista | Tab: karta → kebab → karta… Enter na karcie otwiera szczegół. |
| Kebab | `DropdownMenu`: Enter/Space otwiera, ↑/↓ porusza, Esc zamyka i wraca na wyzwalacz |
| Kafelki mediów | Każdy przycisk osobnym punktem tabulacji; kolejność wzrokowa = kolejność tabulacji |
| Modal planowania | Focus startuje na `DateTimePicker`; Enter w polu **nie** zatwierdza (za łatwo o przypadkowe uzbrojenie) — zatwierdza wyłącznie przycisk |
| Panel `needs_reconcile` | Focus na przycisku „Sprawdź" przy wejściu na ekran **tylko wtedy**, gdy przyszliśmy z deep-linku `?status=needs_reconcile`; w innym wypadku bez przenoszenia fokusu |
| Disabled z powodem | Kontrolki wygaszone z powodem używają `aria-disabled` + zablokowanego handlera (**nie** atrybutu `disabled`), żeby zostały **osiągalne fokusem** i żeby czytnik ekranu przeczytał powód. Reguła domu: „disabled musi pozostać zrozumiały" |

### 15.3 Ruch

`prefers-reduced-motion` jest obsłużone globalnie w `.next-root`. Dwa miejsca z własną
odpowiedzialnością: ikona `loader` przy `publishing` **nie kręci się** przy zredukowanym
ruchu (zostaje statyczna, sens niesie tekst), a odliczanie throttle nie animuje liczby.

---

## 16. Inwentarz komponentów (reuse / extend / create)

### 16.1 Reuse — bez zmian

`PageHeader`, `ModuleAside`, `ModuleTabs`, `FilterBar`, `FilterTabBar`, `SaveViewModal`,
`Tabs`, `EntityCard`, `Card`, `Badge`, `Button`, `Icon`, `Skeleton`, `EmptyState`, `Alert`,
`Accordion`, `Modal`, `Drawer`, `ConfirmDialog`, `DropdownMenu`, `Tooltip`,
`DescriptionList`, `CreatorBadge`, `TextInput`, `Textarea`, `Select`, `FieldShell`
(w trybie `segmented` — pole złączone, §6.1), `FormField`, `DateTimePicker`,
`DateRangeFilter`, `Link`, `Avatar`, `Toast` (przez `useToast`).

Z innych stron (import międzymodułowy — dozwolony, precedens `forms → disk`, `tasks → approvals`):
`pages/disk/DiskFilePickerModal.vue`.

Composables: `useI18n`, `useToast`, `useConfirm`, `useFilterTabs`, `useInfiniteScroll`,
`useDebounce`, `useOverlayStack`, `useFocusTrap`. **`useRouteQueryHydration` nie istnieje w `next`.**

Router: `sectionRedirect()` (`app/router/sectionRedirect.ts`), `isPathActive()`.

### 16.2 Extend — dwie pozycje

| Plik | Zmiana | Uzasadnienie |
| --- | --- | --- |
| `ui/primitives/icons.ts` | dodać `'send'` do `IconName` **i** do `ICONS` | Ikona modułu i pozycji nawigacji. Rejestr nie ma dziś żadnego glifu „opublikuj/wyślij": `upload` czyta się jako wgrywanie pliku, `external-link` jako „otwiera się gdzie indziej", `share` nie istnieje. Rejestr jest z założenia rozszerzalny (tak dodano `repeat`, `map-pin`, `package`, `tag`). |
| `pages/AppLayout.vue` | pozycja `publishing` w `primaryNav` + plakietka `needs_attention` wzorem Akceptacji | §3.1 |

**Żadnych nowych zależności npm.** Żadnych logotypów marek (D14).

### 16.3 Create — pliki modułu

| Plik | Rola |
| --- | --- |
| `pages/publishing/PublishingModuleLayout.vue` | Shell: `ModuleAside` + `ModuleTabs`, dwa wpisy modułu |
| `pages/publishing/PublicationsView.vue` | Lista: nagłówek, FilterBar+SavedViews, baner uwagi, taby, karty, infinite scroll |
| `pages/publishing/PublicationCard.vue` | Karta na `EntityCard` (§5) |
| `pages/publishing/PublicationDetailView.vue` | Szczegół: pasmo + dwie kolumny + zakładki (§7) |
| `pages/publishing/PublicationStatusBand.vue` | Siedem wariantów pasma (§7.2) |
| `pages/publishing/ReconcilePanel.vue` | Panel `needs_reconcile` z trzema wynikami i throttle (§8) |
| `pages/publishing/PublicationEditorDrawer.vue` | Kompozytor (§6) |
| `pages/publishing/PublicationMediaField.vue` | Media: picker z Dysku, kolejność, stany braku pliku (§6.3) |
| `pages/publishing/SchedulePublicationModal.vue` | Modal planowania / zmiany terminu / „opublikuj teraz" (§7.4) |
| `pages/publishing/ConnectionsView.vue` | Połączenia: siatka kart platform + baner powrotu (§9, §10) |
| `pages/publishing/PlatformConnectionCard.vue` | Karta platformy z listą kont |
| `pages/publishing/OAuthReturnBanner.vue` | 14 kodów + fallback, tonacja i akcje (§10.3) |
| `pages/publishing/PublicationApprovalPanel.vue` | **B6-zależny** (§11) — nie budować przed B6 |
| `pages/publishing/publishingMeta.ts` | **Czyste, testowalne mapy:** `toneToVariant`, `statusIcon`, `platformIcon` (z fallbackiem), `PLATFORMS` (L1), `failureSentenceKey`, `oauthReasonTone` |
| `pages/publishing/publishingErrors.ts` | **Czyste predykaty** (wzorzec domu — brak globalnego mappera): `transitionRefusalOf`, `isLostRace`, `throttleSecondsOf`, `fieldErrorsOf` |
| `pages/publishing/types.ts` | Typy 1:1 z §2. **Bez wymyślonych pól.** |
| `app/stores/publishing.ts` | Pinia: lista, kursor, `counts`, filtry, token żądania, CRUD, `schedule`, `reconcile`, `upsertIntoList`/`removeFromList` |
| `app/stores/publishingConnections.ts` | Pinia: połączenia, `authorize`, `disconnect`. **Osobny store** — ekran Połączeń musi działać, gdy lista publikacji nie działa (incydent `APP_KEY`, §9.6) |

### 16.4 Testy Vitest — minimum obowiązkowe

1. **`PUT` odsyła komplet pól** — w tym `options`, którego formularz nie renderuje, i
   `media`, i `scheduled_at` (§6.6). Asercja na payloadzie, nie na UI.
2. `toneToVariant(null) === 'neutral'` oraz nieznany ton → `neutral` (§13.1).
3. Trzy wyniki sprawdzenia: `published` / `failed` / **`needs_reconcile` bez zmiany** —
   trzeci **nie** renderuje `Alert danger` i **nie** wywołuje `toast.danger` (§8.3).
4. `429` → przycisk `disabled` z odliczaniem z `Retry-After`; po upływie wraca sam (§8.4).
5. `422 lost_race` → renderuje `message` **z serwera** i akcję „Odśwież"; nie renderuje
   własnego zdania (§8.4).
6. Wszystkie 14 kodów `reason` + jeden nieznany → baner z niepustym zdaniem; żaden nie
   renderuje surowego klucza (§10.3).
7. `can_be_scheduled === true` przy `status === 'scheduled'` → **nie** renderuje przycisku
   „Zaplanuj", renderuje „Zmień termin" (D5).
8. `publishes_publicly === true && platform_connection_id === null` → „Zaplanuj" wygaszone
   **z widocznym powodem** (D5/L3).
9. Błąd `GET /counts` → plakietki tabów **znikają**, nigdzie nie pada `0` (§12.3).
10. Media: `404` z Dysku → kafelek ostrzegawczy + ostrzeżenie nad sekcją; `media` **nie**
    jest po cichu przycinane (§6.3).
11. Kolejność mediów: `▲`/`▼` zmienia tablicę i anonsuje pozycję; payload zachowuje
    kolejność (D12).
12. Parametry callbacku są **zdejmowane z URL** po odczycie (§10.1).

---

## Aneks powykonawczy (B8)

> Dopisany przez `frontend-agent` **po** zbudowaniu modułu i po przeglądzie. Wszystko poniżej
> opisuje **stan faktyczny kodu**, a nie zamiar — i tam, gdzie kod odbiega od specyfikacji,
> mówi to wprost, zamiast zostawiać dwa dokumenty mówiące różne rzeczy o tym samym ekranie.

### A.1 Dokument jest ucięty — sekcje 17–21 nie istnieją

Spis treści obiecywał jeszcze pięć sekcji: **17. Mapa i18n**, **18. Luki kontraktu**,
**19. Czego NIE ma w B8**, **20. Ryzyka spójności**, **21. Handoff do frontend-agent**.
Treść kończy się na §16.4 — końcówka **przepadła przy awarii**, a nie została świadomie
pominięta. Spis treści został przycięty do sekcji, które w dokumencie **są**; nikt nie ma
szukać czegoś, czego nie ma.

Co z tego wynika praktycznie:

- **Mapa i18n (§17) nie jest odtwarzana w tym dokumencie.** Źródłem prawdy są katalogi
  [`resources/js/next/app/i18n/en.ts`](../../resources/js/next/app/i18n/en.ts) i
  [`pl.ts`](../../resources/js/next/app/i18n/pl.ts), przestrzeń `publishing.*` — jedno
  miejsce zamiast dwóch, które by się rozjechały. Parity PL↔EN pilnuje `i18n.spec.ts`.
- **Luki kontraktu (§18)** są nazwane w miejscach, w których uderzają (L1, L3, L5, L6
  w docblokach `publishingMeta.ts`, `publicationActions.ts`, `ConnectionsView.vue`), plus dwie
  nowe z A.3 poniżej.

### A.2 Stan faktyczny — odstępstwa od litery specyfikacji

| Rzecz | Specyfikacja | Jak jest i dlaczego |
| --- | --- | --- |
| Klucz szuflady kompozytora | `?edit=1` | **`?edit=<uuid>`.** Jedna szuflada obsługuje listę i szczegół („jeden komponent, dwa wejścia"), więc musi wiedzieć **którą** publikację otwiera; `=1` nie niesie tej informacji. `?new=1` zostaje bez zmian. **Nie „naprawiać" tego wstecz.** |
| Metadane pliku z Dysku | `GET /disk/{id}` | **`GET /disk/{id}/info`.** Gołe `/disk/{id}` serwuje **bajty** pliku, nie JSON-a — kafelek mediów potrzebuje nazwy i typu, nie zawartości. |
| Strefa czasowa przestrzeni | — | **`GET /workspaces/{id}` → `timezone`.** Kontekst `auth` jej nie niesie, a każda chwila w module jest czytana na zegarze przestrzeni. To **drugie** źródło tej samej wartości obok `calendar.meta.timezone` — nazwane tutaj, żeby nie stało się cichym rozjazdem: dwa moduły czytają tę samą strefę dwiema drogami i muszą dać tę samą odpowiedź. Wartość jest zatrzaskiwana w store i **zrzucana przy zmianie przestrzeni** (inaczej chwile nowej przestrzeni byłyby czytane na zegarze poprzedniej). |
| Plakietki liczników w tabach | `withCount` jako dostępna nazwa | Klucz **usunięty**. `Tabs` nie przyjmuje `aria-label` per pozycja, a licznik i tak jest **wewnątrz** `<button role="tab">`, więc wchodzi do nazwy dostępnej („Szkice 3"). Klucz, którego nie da się użyć, jest kluczem, który ktoś kiedyś użyje źle. |

### A.3 Dwie luki kontraktu wykryte dopiero przy budowie

**L12 — „Rozłączone konta" (§9.3) są niemożliwe.** `PlatformConnectionService::index()` pyta
**bez** `withTrashed()`, a rozłączenie ustawia status `revoked` **i** miękko usuwa wiersz —
rozłączone konto nigdy nie wraca z `GET /publishing/connections`. Sekcja z §9.3 byłaby pusta
z konstrukcji, więc **nie została zbudowana**, a filtr `status === 'revoked'` usunięty razem
z kluczem `connections.revokedSection`. Jedyne miejsce, w którym rozłączone konto jest dziś
nazwane, to szczegół publikacji: wiersz wskazuje konto, którego nie ma na wczytanej liście →
*„Konto zostało rozłączone"* (nigdy „Nie wybrano" — to dwa różne fakty i tylko jeden z nich
tłumaczy wstrzymanie).
**Rekomendacja backendowa:** parametr `?include=disconnected` na `GET /publishing/connections`.

**L13 — zakładka Akceptacji jest uboższa niż §11.** `PublicationResource` niesie trzy skalary:
`approval_pipeline_id`, `is_in_approval`, `approval_state` — w przeciwieństwie do
`TaskResource`, który wiezie `approval_pipeline` (ze stopniami), `pending_approval_process`
**i** `approval_run_id`. Panel dociąga więc sam pipeline (`fetchPipeline(id)`, drugie żądanie)
i rysuje **ścieżkę oraz stan**, ale **nie** konkretne decyzje, ich autorów i czasy — bo nie ma
uruchomienia, do którego mógłby je przypiąć. Nie zmyślamy pola; zakładka mówi wprost, że
decyzje żyją w module Akceptacji, i tam linkuje.
**Rekomendacja backendowa:** wzorem `TaskResource` dołożyć `approval_run_id` (+ nazwę
pipeline'u), wtedy zakładka domknie §11 bez zmiany frontu poza podpięciem pola.
