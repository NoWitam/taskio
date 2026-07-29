<?php

return [

    // Delegacja bot ↔ sesja generowania (R2 pod-etap 3) — jedyne tłumaczone komunikaty serwera:
    // odpowiedzi na konflikt stanu edytowalności. Reszta jest strukturalna (FE renderuje raport wypełnienia).
    'delegation' => [
        'already_generating' => 'Ta sesja generuje treść. Poczekaj na zakończenie, zanim oddelegujesz ją botowi.',
        'not_editable' => 'Tej sesji nie można oddelegować w jej obecnym stanie.',
    ],

];
