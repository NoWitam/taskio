<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Komunikaty uwierzytelniania
    |--------------------------------------------------------------------------
    |
    | `failed` jest celowo nieprecyzyjny — nie mówi, czy nie zgadza się e-mail, czy
    | hasło. Rozróżnienie zamieniłoby formularz logowania w narzędzie do sprawdzania,
    | które adresy są zarejestrowane.
    |
    */

    'failed' => 'Te dane logowania nie pasują do naszych zapisów.',
    'password' => 'Podane hasło jest nieprawidłowe.',
    'throttle' => 'Zbyt wiele prób logowania. Spróbuj ponownie za :seconds s.',

];
