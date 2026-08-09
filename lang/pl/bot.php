<?php

return [

    // Delegacja bot ↔ sesja generowania (R2 pod-etap 3) — jedyne tłumaczone komunikaty serwera:
    // odpowiedzi na konflikt stanu edytowalności. Reszta jest strukturalna (FE renderuje raport wypełnienia).
    'delegation' => [
        'already_generating' => 'Ta sesja generuje treść. Poczekaj na zakończenie, zanim oddelegujesz ją botowi.',
        'not_editable' => 'Tej sesji nie można oddelegować w jej obecnym stanie.',
    ],

    // Budżet AI workspace’u odmówił uruchomienia bota, zanim run wystartował. Zapisywane na osi czasu
    // zadania jako powód niepowodzenia — bot, który przestał pracować, mówi DLACZEGO, zamiast wyglądać
    // na zepsutego.
    'budget' => [
        'run_refused' => 'Workspace wyczerpał miesięczny budżet AI, więc ten przebieg nie został uruchomiony. '
            . 'Podnieś limit albo poczekaj na kolejny miesiąc rozliczeniowy.',
    ],

    // Moduł WIEDZY (B6): podpięcie bota do prawdziwej bazy wiedzy i przeniesienie do niej
    // wbudowanych wpisów bota.
    'knowledge' => [
        'base_name' => 'Wiedza: :bot',
        'base_description' => 'Przeniesione z wbudowanego modułu wiedzy bota „:bot".',
        'base_charter' => 'Co bot „:bot" musi wiedzieć, żeby wykonywać swoje zadania. Baza powstała z '
            . 'wbudowanego modułu wiedzy bota — od teraz edytuj wiedzę tutaj.',
        'migration_note' => 'Przeniesione z wbudowanego modułu wiedzy bota „:bot"',
        'nothing_to_migrate' => 'Ten bot nie ma wbudowanych wpisów wiedzy do przeniesienia.',
        // Fail-closed: baza wiedzy odrzuca składnię szablonów, a kolumna bota nigdy jej nie sprawdzała.
        'migration_blocked' => 'Te wpisy zawierają składnię szablonów (@[...], {{ ... }}, bloki warunkowe) '
            . 'i nie mogą zostać przeniesione: :titles. Usuń ją w edytorze bota i spróbuj ponownie.',
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
