# Wizja produktu — AI Content Platform (robocza nazwa: Taskio)

> Dokument konsoliduje burzę mózgów (pliki z `G:\BrainStorm\AI SOCIAL MEDIA AUTOMATYZACJA`:
> CHECK LIST, funkcjonalności podstawowe i rozszerzone, moduły, roadmapa, use-case'y,
> diagram UX, widoki, propozycje nazw) z faktycznym stanem aplikacji na 2026-07-09.
> Jest to „prezentacja" produktu: czym jest, do czego służy, co oferuje i co można nim osiągnąć.

---

## 1. Czym jest ta aplikacja (elevator pitch)

**Platforma do automatyzacji tworzenia i publikacji treści social media, w której pracę
wykonują cyfrowe postacie AI — pod pełną kontrolą człowieka.**

Użytkownik tworzy **postać AI** (bota) — wirtualnego „pracownika" z własnym stylem
wypowiedzi, wiedzą, a docelowo także wyglądem i głosem. Postać dostaje **zadania**
(ręcznie, z **workflow** lub z **kampanii**), generuje treści (posty, grafiki, wideo,
audio), a każdy wynik przechodzi przez **proces akceptacji** (człowiek lub inny bot,
wieloetapowo). Zatwierdzone treści trafiają do **Publishing Hub**, który publikuje je na
platformach (YouTube, TikTok, Instagram, Facebook, X) według harmonogramu, zbiera
**statystyki** i podpowiada, co poprawić.

Jedno zdanie: **fabryka treści napędzana AI, z człowiekiem jako bramką jakości.**

### Trzy zasady, które odróżniają produkt

1. **Human-in-the-loop** — nic nie wychodzi „w świat" bez przejścia przez akceptacje;
   odrzucenie zawsze niesie powód i wskazanie błędnych pól, więc AI uczy się poprawiać.
2. **Bot jako pracownik, nie funkcja** — postać AI jest pełnoprawnym aktorem: można jej
   przypisać zadanie, może być recenzentem w akceptacjach, ma historię działań.
3. **Wszystko jest połączone automatyzacją** — moduły nie są silosami; workflow spina
   trigger → warunki → kroki przez wszystkie moduły (formularz → zadanie → generacja →
   akceptacja → publikacja → analityka).

### Nazwa

„Taskio" to nazwa robocza (dziedzictwo modułu zadań). Z burzy mózgów — TOP 3:
**Mythic.ai** (storytelling, epicko), **EchoForge** (kreacja odbijająca się echem),
**AITHOR** (AI-autor, gra słów). Decyzja otwarta — nie blokuje żadnego etapu prac.

---

## 2. Dla kogo — use-case'y wzorcowe

Cztery persony z burzy mózgów definiują 100% wymagań funkcjonalnych:

| Use-case | Przebieg przez moduły |
|---|---|
| **AI Instagramerka** | Postać (wygląd, styl) → Kampania „post co wtorek i czwartek" → Generator (zdjęcie + tekst) → Zadanie akceptacji → publikacja na IG. Dodatkowo posty nieregularne w reakcji na trendy/komentarze. |
| **TikTok fantasy storytelling** | Uniwersum (świat, postacie, miejsca) → historia + scenariusz (AI/ręcznie) → akceptacja scenariusza → generacja wideo → akceptacja wideo → publikacja na TikToku. |
| **AI Raper** | Postać rapera (styl, głos) → kampania cyklu utworów → AI: tytuł, koncept, tekst, bit, wokal (ElevenLabs) → teledysk + miniaturka → publikacja na YouTube. |
| **Streamer** | Shoty: AI wykrywa momenty na żywo → klip → akceptacja → TikTok. Podsumowania: analiza nagrania → montaż wideo → YouTube. AI-widzowie: postacie komentujące stream zgodnie z osobowością. |

Wspólny mianownik: **cykliczna, wysokowolumenowa produkcja treści, w której człowiek
tylko zatwierdza i koryguje kierunek.**

---

## 3. Filary produktu (model mentalny)

```
        KTO TWORZY                CO TWORZY                KIEDY/JAK AUTOMATYCZNIE
   ┌──────────────────┐   ┌────────────────────────┐   ┌──────────────────────────┐
   │ Postacie (Boty)  │   │ Generator treści        │   │ Workflowy                │
   │ styl, wiedza,    │──▶│ + Templatki             │◀──│ trigger→warunki→kroki    │
   │ głos, wygląd     │   │ tekst/grafika/wideo/    │   │ Kampanie (agregator)     │
   └──────────────────┘   │ audio                   │   │ Kalendarz                │
                          └────────────────────────┘   └──────────────────────────┘
              │                        │                            │
              ▼                        ▼                            ▼
   ┌─────────────────────────────────────────────────────────────────────────────┐
   │             KONTROLA CZŁOWIEKA: Zadania + Formularze + Akceptacje           │
   └─────────────────────────────────────────────────────────────────────────────┘
              │                        │                            │
              ▼                        ▼                            ▼
   ┌──────────────────┐   ┌────────────────────────┐   ┌──────────────────────────┐
   │ Dysk (Zasoby)    │   │ Publishing Hub          │   │ Analityka / feedback loop│
   │ pliki, wersje,   │   │ konta, kolejka, retry,  │──▶│ statystyki, porównania,  │
   │ tagi, edytory    │   │ historia publikacji     │   │ rekomendacje AI          │
   └──────────────────┘   └────────────────────────┘   └──────────────────────────┘
                          Fundament: Workspaces (multi-tenant) · Auth · Ustawienia ·
                          Etykiety · Komentarze · Historia zmian · Zapisane widoki · i18n PL/EN
```

---

## 4. Mapa modułów: wizja ↔ stan faktyczny

Legenda: ✅ gotowe · 🔶 częściowo / w trakcie · ⬜ planowane

### Zbudowane (Etapy 1–5 z CHECK LIST — zrobione)

| Moduł | Co daje dziś | Braki względem wizji |
|---|---|---|
| ✅ **Zadania** (Tasks) | Tablica kanban + lista, szczegóły z tabami, statusy z przejściami, załączniki, komentarze, historia, akcje botów, powiązanie z formularzami i pipeline'ami akceptacji | Subtaski/checklisty (tab jest placeholderem); filtry po postaci/kampanii dojdą z kolejnymi modułami |
| ✅ **Formularze** (Forms) | Kreator + renderer + lista; źródło ustrukturyzowanych danych dla zadań i workflow | Migracja starego frontendu do `next` niedokończona |
| ✅ **Postacie** (Bot) | Kreator bota: moduł tekstowy (słownik, ulubione zwroty, zakazy), wykonywanie zadań (narzędzia, źródła wiedzy), wiedza; lista; widok szczegółowy z historią akcji; reaper zawieszonych przebiegów | Moduł wizualny i głosowy = placeholdery (ElevenLabs, warianty wyglądu — dalsze etapy); sandbox/trening stylu — Etap „Custom Quality" |
| ✅ **Akceptacje** (Approvals) | Wieloetapowe pipeline'y; zatwierdzający: człowiek **lub AI** (`ApproverType::AI` + agent oceniający); odrzucenie z powodem i wskazaniem pól; kolejka decyzji, komentarze, badge | Deep-linki, widok mobilny, N+1 — znane follow-upy |
| ✅ **Workflowy** (Workflows) | Trigger → warunki → kroki; 2 triggery (harmonogram 12 rodzin z kompilatorem cron + AI-asystą; wysłanie formularza z warunkami per pole), 2 kroki (utwórz zadanie, utwórz raport z formularzy), typowany system zmiennych, silnik przebiegów z logami; **całość niezacommitowana!** | Trigger „zatwierdzono w akceptacjach", kroki per moduł (generuj treść, publikuj, wydarzenie), podpięcie akceptacji pod krok, limity kosztów — dojdą wraz z kolejnymi modułami |
| ✅ **Workspaces** (multi-tenant) | Przestrzenie współdzielone lub z własną bazą (pełny schemat tenant), tworzenie + provisioning | 🔶 członkowie/zaproszenia — Batch 2 w trakcie |
| ✅ **Fundamenty** | Auth (Sanctum), Users, Settings, Labels, Comments, Changelog (historia), FilterTabs (zapisane widoki na każdej liście), dokumentacja w aplikacji (`/docs`), design system `next`, i18n PL/EN | Frontend auth częściowo odłożony |
| 🔶 **Dysk** (Disk) | Dziś: infrastruktura załączników (upload, typy plików, `HasFiles`) | Docelowo pełny menedżer: foldery, tagi, metadane, historia, edytory per typ, wybór z dysku w FileInput → **Etap 6** |

### Do zbudowania (Etapy 6–12 + backlog wizji)

| Moduł | Do czego służy | Źródło w wizji |
|---|---|---|
| ⬜ **Dysk — pełny** | Repozytorium zasobów: foldery, thumbnaile, tagi, metadane, historia, edytor per typ pliku (AI i nie tylko), integracja z FileInput | Etap 6; „Zasoby (Assets)" |
| ⬜ **Generator treści + Templatki** | Szablony (prompt + zmienne + parametry; systemowe: post, post ze zdjęciem, wideo proste, wideo-scenariusz), sesje generowania (jak czat, historia zmian z cofaniem, kosz/archiwum), zapis na dysk, delegowanie do bota, bot jako „autor" stylu | Etap 7; „Templatki", „Formaty/Treści", „Generowanie treści AI" |
| ⬜ **Kalendarz** | Kreator wydarzeń, widok kalendarza, mapowanie innych modeli (task z deadline), krok workflow „dodaj wydarzenie"; później: święta/eventy zewnętrzne jako inspiracje | Etap 8; „Kalendarz eventów i inspiracji" |
| ⬜ **Publishing Hub** | Mapowanie treści na publikacje per platforma, realne konta (min. 2 z: YT/FB/IG/TikTok/X), kolejka zaplanowanych + AI-sugestie, failed z powodem i retry, historia ze statystykami, streszczeniami i porównaniami AI | Etapy 9–10; „Publikacja i integracje" |
| ⬜ **Kampanie** | Agregator: zasady automatycznego tworzenia treści (platforma, zamysł kampanii, częstotliwość, przypięty approval i template), lista kampanii, filtrowanie publikacji po kampanii, widok analizy | Etap 10; „Zarządzanie kampaniami" |
| ⬜ **Dashboard** | Karty zadań, sugestie AI, nadchodzące terminy/kampanie, aktywność postaci, ostatnio otwierane (dziś: placeholder) | „Dashboard" (moduł 1 z brainstormu) |
| ⬜ **Powiadomienia** | Dzwonek + centrum powiadomień (akceptacje czekają, bot skończył, publikacja failed) — warunek sensownego dashboardu i automatyzacji | Wynika z wizji (nie ma własnego etapu — dodane w konsolidacji) |
| ⬜ **Analityka / feedback loop** | Panel skuteczności treści i kampanii, metryki z platform, rekomendacje AI („posty w stylu X mają +30% CTR") | „Moduł analityczny"; Etapy 9–10 |
| ⬜ **Custom Quality** (Etap 11 — do zaplanowania) | Propozycja zakresu: sandbox rozmowy z postacią, trening stylu na przykładach, historia testów + własne kryteria jakości treści; tu też może wejść moduł wizualny/głosowy bota | „Sandbox/Szkolenie AI", „Moduł szkoleniowy", „Styl i preferencje postaci" |
| ⬜ **Uniwersum** (Etap 12 — do zaplanowania) | Światy narracyjne: postacie, miejsca, historie, zależności; edytor scenariuszy z wersjonowaniem; kontynuacja fabuły z pamięcią; generator scenariusza → treść | „Uniwersum narracyjne", „Kontynuacja historii", „Edytor scenariuszy" |

### Backlog wizji (poza etapami — świadomie później)

- **Trendy & Inspiracje** — monitoring trendów, propozycje AI, „zainicjuj kampanię z trendu".
- **Streamy** — shoty na żywo (Twitch + Whisper), podsumowania streamów, AI-widzowie.
  *Uwaga: roadmapa MVP z brainstormu zawierała je w fazach 4–5, ale CHECK LIST (nowszy
  dokument operacyjny) nie przydziela im etapu — traktujemy jako backlog po Etapie 12.*
- **Muzyka** — generowanie beatów/utworów (MusicGen), edytor piosenki, eksport z metadanymi
  ID3/licencją. *Ta sama uwaga; częściowo może wejść do Generatora treści jako typ „audio".*
- **Kompozytor multimedialny** — timeline audio+wideo, render FFMPEG.
- **Monitoring komentarzy social** — inspiracje z komentarzy IG/TikTok, sentyment.
- **Wielojęzyczność treści** — generowanie lokalnych wersji kampanii (UI już jest PL/EN).
- **Eksporty profesjonalne** — scenariusze do PDF/DOCX, plany kampanii do CSV.

---

## 5. Co można osiągnąć (po ukończeniu planu)

1. **Autonomiczny kanał social media**: kampania „3 posty tygodniowo" → workflow budzi
   bota → bot generuje treść z templatki w swoim stylu → akceptacja (AI-recenzent, a
   człowiek tylko na ostatnim kroku) → automatyczna publikacja → statystyki wracają do
   analityki → AI koryguje rekomendacje.
2. **Seryjna produkcja narracyjna**: uniwersum fantasy → cotygodniowy odcinek: scenariusz
   → akceptacja → wideo → akceptacja → TikTok.
3. **Wirtualny artysta**: postać-raper z głosem i stylem → cykl utworów z teledyskami na YT.
4. **Zaplecze zespołowe**: przestrzenie robocze (także z własną bazą danych), role,
   zaproszenia, komentarze, historia zmian, zapisane widoki — praca zespołu ludzi *i botów*
   w jednym miejscu.

---

## 6. Zasady przekrojowe (obowiązują każdy nowy moduł)

1. **Planowanie przed kodem** (planning-agent), potem sekwencja: backend → UX → frontend →
   testy → dokumentacja → review.
2. **Human-in-the-loop**: każdy krok automatyzacji da się spiąć z akceptacjami.
3. **Multi-tenancy**: każda nowa tabela dostaje lustro w schemacie tenant (własna baza —
   bez `workspace_id` i bez FK między bazami).
4. **Workflow-first**: nowy moduł dokłada swoje triggery/warunki/kroki do Workflows —
   to jest mechanizm integracji, nie punktowe hooki.
5. **Koszty AI pod kontrolą**: operacje AI mają limity kosztów i logi (wymóg z CHECK LIST,
   krytyczny od Generatora treści wzwyż).
6. **i18n PL/EN** dla każdego tekstu UI; design system `next` (FilterBar + zapisane widoki
   na każdej liście, Button primitive, skeletony, ikony modułów).
7. **Dokumentacja w aplikacji** (`docs/` + moduł docs) i ADR-y dla decyzji.

---

## 7. Otwarte decyzje

| # | Decyzja | Rekomendacja |
|---|---|---|
| 1 | Nazwa produktu (Taskio → Mythic.ai / EchoForge / AITHOR?) | Odłożyć do czasu Publishing Hub; techniczne zmiany są tanie, branding zrobić raz |
| 2 | Streamy i Muzyka — kiedy? | Backlog po Etapie 12; Muzykę częściowo wchłonąć do Generatora (typ „audio") |
| 3 | Kampanie: osobny silnik czy nakładka na Workflows? | Nakładka na Workflows (kampania = szablon workflow z UI-em domenowym) — do potwierdzenia w planowaniu Etapu 10 |
| 4 | Zakres „Custom Quality" (Etap 11) | Sandbox + trening stylu + kryteria jakości; moduł wizualny/głosowy bota — zdecydować przy planowaniu |
| 5 | Które 2 platformy podpiąć realnie w pierwszej kolejności | Propozycja: YouTube (najstabilniejsze API) + Instagram/Facebook (Graph API); TikTok/X później |
| 6 | Dashboard — kiedy budować | Po Publishing Hub (rozdz. 6 planu), bo dopiero wtedy ma co pokazywać; wersję minimalną można wciągnąć wcześniej |
