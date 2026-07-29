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

    // Kroki workflowu — teksty, które krok ZAPISUJE w domenie (nie komunikaty błędów).
    'steps' => [
        'generate_content' => [
            // Nazwa pliku, pod którą krok zapisuje wygenerowany obraz na Dysku; do nazwy dopisywany jest
            // klucz części, więc receptura z wieloma obrazami nie tworzy pliku o tej samej nazwie.
            'image_name' => 'Wygenerowany obraz',
        ],
    ],

];
