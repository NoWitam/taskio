<?php

return [

    // Delegacja bot ↔ sesja generowania (R2 pod-etap 3) — jedyne tłumaczone komunikaty serwera:
    // odpowiedzi na konflikt stanu edytowalności. Reszta jest strukturalna (FE renderuje raport wypełnienia).
    'delegation' => [
        'already_generating' => 'Ta sesja generuje treść. Poczekaj na zakończenie, zanim oddelegujesz ją botowi.',
        'not_editable' => 'Tej sesji nie można oddelegować w jej obecnym stanie.',
    ],

    // Moduł WYGLĄDU: tworzenie i wybór wizerunku bota.
    'visual' => [
        // Odmowa własności dla każdego id pliku w module (kandydaci/zatwierdzony muszą należeć do
        // tego bota; referencją może być też plik z Dysku).
        'invalid_file' => 'Ten plik nie należy do tego bota.',
        'reference_required' => 'Wskaż obraz źródłowy: wgraj plik albo wybierz go z Dysku.',
        'reference_unreadable' => 'Nie udało się odczytać obrazu źródłowego. Wybierz inny.',
        // Nie ma z czego rysować: moduł nie ma opisu, a żądanie nie dodało instrukcji.
        'nothing_to_generate' => 'Opisz, jak ma wyglądać bot, zanim wygenerujesz obraz.',
        'not_a_candidate' => 'Ten obraz nie jest kandydatem wygenerowanym dla tego bota.',
        'canonical_locked' => 'To jest zatwierdzony wizerunek. Zatwierdź inny (lub cofnij zatwierdzenie), zanim go usuniesz.',
    ],

];
