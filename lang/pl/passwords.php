<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Komunikaty resetu hasła
    |--------------------------------------------------------------------------
    |
    | Zwracane przez brokera haseł. Reset hasła jako funkcja produktu jest świadomie
    | odłożony (patrz plan R0), ale komplet tłumaczeń istnieje już teraz — brakujący
    | plik to dokładnie ten sam defekt, który zwracał użytkownikowi „validation.required",
    | tylko na innym ekranie.
    |
    */

    'reset' => 'Twoje hasło zostało zmienione.',
    'sent' => 'Wysłaliśmy e-mailem link do zmiany hasła.',
    'throttled' => 'Odczekaj chwilę przed kolejną próbą.',
    'token' => 'Ten link do zmiany hasła jest nieprawidłowy.',
    'user' => 'Nie znaleźliśmy użytkownika o tym adresie e-mail.',

];
