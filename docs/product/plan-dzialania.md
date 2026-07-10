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

**Podetapy (osobne PR-y):**
1. **Templatki:** model szablonu (prompt + zmienne + parametry typu długość/format/kanał),
   szablony systemowe: post, post ze zdjęciem, wideo (prosty prompt), wideo (scenariusz);
   lista + edytor z podglądem zmiennych. *Decyzja projektowa: zmienne szablonu reużywają
   typowanego systemu zmiennych z Workflows 5.1 (katalog po ścieżce) — nie budować drugiego.*
2. **Generator (sesje):** sesja generowania per szablon (formularz pod zmienne),
   historia zmian z możliwością cofnięcia (nie logi!), zapis wyniku na Dysk;
   auto-czyszczenie: sesje żyją tydzień → kosz → miesiąc → trwałe usunięcie; archiwizacja
   wyłącza czyszczenie.
3. **Boty w generatorze:** delegowanie „niech bot uzupełni formularz"; bot jako **autor**
   (treść generowana w jego stylu — reuse modułu tekstowego bota).
4. **Limity kosztów AI:** licznik kosztów per sesja/workspace + progi ostrzeżeń —
   fundament wymagany zanim workflow zacznie generować masowo.
5. **Workflow:** krok `generate_content` (szablon + mapowanie zmiennych) — od tej chwili
   workflow potrafi produkować treści.
6. **Typy mediów:** start = tekst + obraz; wideo jako scenariusz tekstowy (bez renderu);
   audio/muzyka — decyzja otwarta nr 2 z wizji.

**Ryzyka:** największy rozdział — ciąć na podetapy; koszty API w testach (mock providerów
przez Laravel AI); UX sesji (wzorzec czatu) wymaga solidnego projektu UX przed kodem.

---

## R3. Kalendarz (Etap 8)

**Cel:** wspólna oś czasu dla całej platformy.

**Backend:** model wydarzenia (tytuł, opis, zakres czasu, kolor/typ, powiązanie
polimorficzne ze źródłem), mapowanie istniejących modeli na wydarzenia (task z deadlinem,
zaplanowane uruchomienia workflow — read-only projekcje).
**Frontend:** widok miesiąca + lista (agenda), kreator wydarzenia, nawigacja do obiektów
źródłowych.
**Workflow:** krok `create_event`; trigger czasowy już jest (harmonogram z 5.1) — nie
dublować, kalendarz tylko **wizualizuje** też przyszłe odpalenia harmonogramów.
**Później (backlog):** import świąt/eventów zewnętrznych jako inspiracje dla kampanii.

**Ryzyka:** niskie; pilnować, by kalendarz był projekcją, a nie drugim źródłem prawdy.

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
- **Moduły wizualny i głosowy bota:** zdjęcie placeholderów — generowanie wyglądu
  (warianty), głos (ElevenLabs) — *albo tutaj, albo osobny rozdział; decyzja na planowaniu.*

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
