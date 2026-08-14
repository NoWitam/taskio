<?php

return [

    // Przebiegi workflow — komunikaty błędów silnika widoczne dla użytkownika. Trafiają do
    // `workflow_runs.error`, który szczegóły przebiegu pokazują dosłownie, więc muszą brzmieć jak
    // wyjaśnienie, a nie jak ślad stosu. Z założenia NIE-tajne: bez treści kroku, klucza korelacji
    // i danych oczekiwania.
    'runs' => [
        // Silnik wstrzymania/wznowienia. Przebieg wstrzymany („waiting") może zakończyć się źle na trzy
        // sposoby — trzy osobne komunikaty, bo każdy wymaga innej reakcji użytkownika.
        'definition_changed' => 'Ten workflow został zmieniony w trakcie wstrzymania przebiegu, więc nie dało się kontynuować go od miejsca zatrzymania. Uruchom go ponownie.',
        'wait_gone' => 'Zadanie, na które czekał ten przebieg, już nie istnieje, więc nie dało się go kontynuować. Uruchom przebieg ponownie.',
        'wait_timed_out' => 'Ten przebieg zbyt długo czekał na zakończenie swojego zadania i został zatrzymany. Uruchom go ponownie.',
        'resume_without_wait' => 'Nie dało się kontynuować tego przebiegu, ponieważ brakuje zapisu o tym, na co czekał.',
    ],

    // Kroki workflowu — teksty, które krok ZAPISUJE w domenie, oraz WŁASNE komunikaty odmowy kroku
    // (komunikaty silnika są wyżej, w `runs`). Z założenia NIE-tajne: bez identyfikatorów i treści kroku.
    'steps' => [
        'generate_content' => [
            // Nazwa pliku, pod którą krok zapisuje wygenerowany obraz na Dysku; do nazwy dopisywany jest
            // klucz części, więc receptura z wieloma obrazami nie tworzy pliku o tej samej nazwie.
            'image_name' => 'Wygenerowany obraz',

            // CZAS PRZEBIEGU: krok wskazuje autora (`bot_id`), którego nie da się już odnaleźć w tej
            // przestrzeni, więc generowanie zostało odmówione. To odmowa, nie degradacja — opublikowanie
            // treści bez niczyjego głosu i bez zamówionego wizerunku nie jest gorszą wersją tego, o co
            // poproszono. Trafia do `workflow_runs.error`.
            'bot_unavailable' => 'Bot, w imieniu którego ten krok tworzy treść, nie jest już dostępny w tej przestrzeni, więc nic nie zostało wygenerowane. Wskaż w kroku innego bota albo usuń to ustawienie.',

            // CZAS EDYCJI: ta sama weryfikacja przy zapisie workflowu, żeby definicja nie mogła zostać
            // zapisana w stanie, w którym każdy przebieg kończyłby się błędem.
            'bot_invalid' => 'Wybrany bot nie jest dostępny w tej przestrzeni.',
        ],
    ],

    // R3 Kalendarz. Nazwy źródeł i plakietki wystąpień są tłumaczone SERWEROWO i przenoszone w
    // odpowiedzi kalendarza: ekran kalendarza musi umieć narysować źródło, o którym nigdy nie słyszał —
    // inaczej „dodanie źródła nie wymaga zmian na froncie" przestaje być prawdą przy pierwszym z nich.
    'calendar' => [
        'schedule_source' => 'Zaplanowane automatyzacje',
        'run_source' => 'Przebiegi automatyzacji',
        'scheduled_badge' => 'Zaplanowane',
        // Przebieg, którego workflow już usunięto, i tak się wydarzył — jego kratka nadal musi coś mówić.
        'run_untitled' => 'Usunięta automatyzacja',

        // Jak często powtarza się zaplanowana automatyzacja — tekst, który czyni znacznik zagęszczenia
        // w kalendarzu informacyjnym („Seria — pokazano 64" nie mówi nic; „Co 5 min" mówi dlaczego
        // seria jest dłuższa niż dzień). Etykietę mają wyłącznie tryby INTERWAŁOWE; dlaczego nie mają
        // jej harmonogramy o stałych godzinach — patrz ScheduleCadenceLabel.
        'cadence' => [
            'every_minutes' => 'Co :count min',
            'every_hours' => 'Co :count h',
            // Interwał wraz z jego opcjonalnym oknem aktywności.
            'within' => ':cadence, :window',
        ],
    ],

    // TYPY KROKÓW jako tekst. Używa ich katalog zmiennych, który wysyła „<typ kroku> · <wyjście>" jako
    // nazwę wyświetlaną każdej zmiennej wyjściowej kroku. Edytor workflowów nazywa typy kroków z
    // własnego katalogu, więc nic nie dopasowuje się po tych napisach.
    'step_types' => [
        'create_task' => 'Utwórz zadanie',
        'create_form_report' => 'Utwórz raport formularza',
        'generate_content' => 'Wygeneruj treść',
        'create_event' => 'Utwórz wydarzenie w kalendarzu',
    ],

    // Stany przebiegu jako TEKST, dla miejsc, w których to serwer musi je nazwać (plakietka kalendarza
    // oraz `state_label` w API przebiegów). Metoda label() w enumie czyta teraz te klucze: wcześniej
    // miała polskie napisy na sztywno i była ostatnim śladem ogólnoaplikacyjnej usterki, w której proza
    // serwera szła po APP_LOCALE zamiast po języku czytającego. Nic nie dopasowuje się po tej prozie —
    // interfejs przebiegów rozpoznaje stan po `run.state`, a `state_label` traktuje wyłącznie jako
    // zapasowy napis — więc to domknięcie naprawy, a nie zmiana kontraktu.
    'run_states' => [
        'pending' => 'Oczekuje',
        'running' => 'W trakcie',
        'waiting' => 'Wstrzymany',
        'completed' => 'Zakończony',
        'failed' => 'Błąd',
        'cancelled' => 'Anulowany',
    ],

];
