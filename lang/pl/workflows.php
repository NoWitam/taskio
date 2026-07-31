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

];
