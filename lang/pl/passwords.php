<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Komunikaty resetu hasła
    |--------------------------------------------------------------------------
    |
    | Zwracane przez brokera haseł. Reset hasła jako funkcja produktu BYŁ świadomie
    | odłożony (plan R0), a tłumaczenia istniały wcześniej niż on — teraz funkcja
    | dogoniła ten plik (decyzja D5 rozdziału R4: po R4 utrata konta to utrata
    | kontroli nad żywymi tokenami OAuth do firmowych kanałów).
    |
    */

    'reset' => 'Twoje hasło zostało zmienione.',
    'sent' => 'Wysłaliśmy e-mailem link do zmiany hasła.',
    'throttled' => 'Odczekaj chwilę przed kolejną próbą.',
    'token' => 'Ten link do zmiany hasła jest nieprawidłowy.',
    'user' => 'Nie znaleźliśmy użytkownika o tym adresie e-mail.',

    /*
    |--------------------------------------------------------------------------
    | Odpowiedź, której endpoint „nie pamiętam hasła" faktycznie udziela
    |--------------------------------------------------------------------------
    |
    | `sent` i `user` powyżej to dwa wyniki brokera i dokładnie ich NIE wolno
    | powiedzieć na głos: rozróżnienie ich zamienia formularz w narzędzie do
    | sprawdzania, które adresy są zarejestrowane. To jedno zdanie wraca dla
    | każdego adresu — znanego i nieznanego, wysłanego i zdławionego —
    | patrz PasswordResetService. Ta sama zasada co `auth.failed`.
    |
    | Jest sformułowane warunkowo („jeśli konto istnieje"), a nie jako
    | stwierdzenie wysyłki, bo dla nieznanego adresu nic nie zostało wysłane
    | i zdanie i tak musi być prawdziwe.
    |
    */

    'requested' => 'Jeśli konto o tym adresie istnieje, wysłaliśmy na nie link do zmiany hasła.',

    /*
    |--------------------------------------------------------------------------
    | Wiadomość e-mail z linkiem
    |--------------------------------------------------------------------------
    |
    | Treść dla App\Modules\Auth\Mail\PasswordResetMail. Leży tutaj, obok wyników
    | brokera, żeby cała proza tego przepływu była w jednym pliku na język; mail
    | z zaproszeniem (zaszyty po angielsku) to starsza konwencja, którą to poprawia.
    |
    */

    'mail' => [
        'subject' => 'Zmiana hasła w Taskio',
        'greeting' => 'Cześć :name,',
        'intro' => 'Otrzymaliśmy prośbę o ustawienie nowego hasła do Twojego konta w Taskio.',
        'action' => 'Ustaw nowe hasło',
        'fallback' => 'Albo wklej ten link do przeglądarki:',
        'expires' => 'Link jest ważny :minutes min i można go użyć raz.',
        'ignore' => 'Jeśli to nie Ty prosiłeś o zmianę, zignoruj tę wiadomość — hasło pozostanie bez zmian.',
    ],

];
