<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Komunikaty walidacji
    |--------------------------------------------------------------------------
    |
    | Pełny polski odpowiednik framework'owego lang/en/validation.php.
    |
    | Ten plik nie jest kosmetyką. Aplikacja działa z `app.locale = pl` ORAZ
    | `app.fallback_locale = pl`, więc dopóki go nie było, KAŻDA walidacja Laravela w
    | całym produkcie zwracała klientowi surowy klucz („validation.required") zamiast
    | zdania — dokładnie tak, jak w zgłoszonym repro PATCH-a wpisu wiedzy. Brakujący
    | fallback na angielski sprawiał, że nie było nawet awaryjnego tekstu do pokazania.
    |
    | Zwracamy się do użytkownika bezosobowo („Pole … jest wymagane"), bo komunikat
    | trafia pod konkretne pole formularza, a nie do dialogu — krótkie, rzeczowe zdanie
    | czyta się tam lepiej niż zwrot w drugiej osobie.
    |
    */

    'accepted' => 'Pole :attribute musi zostać zaakceptowane.',
    'accepted_if' => 'Pole :attribute musi zostać zaakceptowane, gdy :other ma wartość :value.',
    'active_url' => 'Pole :attribute musi być poprawnym adresem URL.',
    'after' => 'Pole :attribute musi zawierać datę późniejszą niż :date.',
    'after_or_equal' => 'Pole :attribute musi zawierać datę nie wcześniejszą niż :date.',
    'alpha' => 'Pole :attribute może zawierać wyłącznie litery.',
    'alpha_dash' => 'Pole :attribute może zawierać wyłącznie litery, cyfry, myślniki i podkreślenia.',
    'alpha_num' => 'Pole :attribute może zawierać wyłącznie litery i cyfry.',
    'any_of' => 'Pole :attribute jest nieprawidłowe.',
    'array' => 'Pole :attribute musi być tablicą.',
    'ascii' => 'Pole :attribute może zawierać wyłącznie jednobajtowe znaki alfanumeryczne i symbole.',
    'before' => 'Pole :attribute musi zawierać datę wcześniejszą niż :date.',
    'before_or_equal' => 'Pole :attribute musi zawierać datę nie późniejszą niż :date.',
    'between' => [
        'array' => 'Pole :attribute musi zawierać od :min do :max elementów.',
        'file' => 'Plik w polu :attribute musi mieć od :min do :max kilobajtów.',
        'numeric' => 'Pole :attribute musi zawierać wartość od :min do :max.',
        'string' => 'Pole :attribute musi mieć od :min do :max znaków.',
    ],
    'boolean' => 'Pole :attribute musi mieć wartość prawda albo fałsz.',
    'can' => 'Pole :attribute zawiera niedozwoloną wartość.',
    'confirmed' => 'Potwierdzenie pola :attribute nie zgadza się.',
    'contains' => 'W polu :attribute brakuje wymaganej wartości.',
    'current_password' => 'Podane hasło jest nieprawidłowe.',
    'date' => 'Pole :attribute musi zawierać poprawną datę.',
    'date_equals' => 'Pole :attribute musi zawierać datę równą :date.',
    'date_format' => 'Pole :attribute musi odpowiadać formatowi :format.',
    'decimal' => 'Pole :attribute musi mieć :decimal miejsc po przecinku.',
    'declined' => 'Pole :attribute musi zostać odrzucone.',
    'declined_if' => 'Pole :attribute musi zostać odrzucone, gdy :other ma wartość :value.',
    'different' => 'Pola :attribute i :other muszą się różnić.',
    'digits' => 'Pole :attribute musi składać się z :digits cyfr.',
    'digits_between' => 'Pole :attribute musi składać się z :min do :max cyfr.',
    'dimensions' => 'Pole :attribute zawiera obraz o nieprawidłowych wymiarach.',
    'distinct' => 'Pole :attribute zawiera zduplikowaną wartość.',
    'doesnt_contain' => 'Pole :attribute nie może zawierać żadnej z wartości: :values.',
    'doesnt_end_with' => 'Pole :attribute nie może kończyć się żadną z wartości: :values.',
    'doesnt_start_with' => 'Pole :attribute nie może zaczynać się żadną z wartości: :values.',
    'email' => 'Pole :attribute musi być poprawnym adresem e-mail.',
    'encoding' => 'Pole :attribute musi być zakodowane w :encoding.',
    'ends_with' => 'Pole :attribute musi kończyć się jedną z wartości: :values.',
    'enum' => 'Wybrana wartość pola :attribute jest nieprawidłowa.',
    'exists' => 'Wybrana wartość pola :attribute jest nieprawidłowa.',
    'extensions' => 'Plik w polu :attribute musi mieć jedno z rozszerzeń: :values.',
    'file' => 'Pole :attribute musi zawierać plik.',
    'filled' => 'Pole :attribute musi mieć wartość.',
    'gt' => [
        'array' => 'Pole :attribute musi zawierać więcej niż :value elementów.',
        'file' => 'Plik w polu :attribute musi być większy niż :value kilobajtów.',
        'numeric' => 'Pole :attribute musi zawierać wartość większą niż :value.',
        'string' => 'Pole :attribute musi mieć więcej niż :value znaków.',
    ],
    'gte' => [
        'array' => 'Pole :attribute musi zawierać co najmniej :value elementów.',
        'file' => 'Plik w polu :attribute musi mieć co najmniej :value kilobajtów.',
        'numeric' => 'Pole :attribute musi zawierać wartość nie mniejszą niż :value.',
        'string' => 'Pole :attribute musi mieć co najmniej :value znaków.',
    ],
    'hex_color' => 'Pole :attribute musi zawierać poprawny kolor szesnastkowy.',
    'image' => 'Pole :attribute musi zawierać obraz.',
    'in' => 'Wybrana wartość pola :attribute jest nieprawidłowa.',
    'in_array' => 'Pole :attribute musi występować w :other.',
    'in_array_keys' => 'Pole :attribute musi zawierać co najmniej jeden z kluczy: :values.',
    'integer' => 'Pole :attribute musi być liczbą całkowitą.',
    'ip' => 'Pole :attribute musi być poprawnym adresem IP.',
    'ipv4' => 'Pole :attribute musi być poprawnym adresem IPv4.',
    'ipv6' => 'Pole :attribute musi być poprawnym adresem IPv6.',
    'json' => 'Pole :attribute musi zawierać poprawny ciąg JSON.',
    'list' => 'Pole :attribute musi być listą.',
    'lowercase' => 'Pole :attribute może zawierać wyłącznie małe litery.',
    'lt' => [
        'array' => 'Pole :attribute musi zawierać mniej niż :value elementów.',
        'file' => 'Plik w polu :attribute musi być mniejszy niż :value kilobajtów.',
        'numeric' => 'Pole :attribute musi zawierać wartość mniejszą niż :value.',
        'string' => 'Pole :attribute musi mieć mniej niż :value znaków.',
    ],
    'lte' => [
        'array' => 'Pole :attribute nie może zawierać więcej niż :value elementów.',
        'file' => 'Plik w polu :attribute nie może być większy niż :value kilobajtów.',
        'numeric' => 'Pole :attribute nie może zawierać wartości większej niż :value.',
        'string' => 'Pole :attribute nie może mieć więcej niż :value znaków.',
    ],
    'mac_address' => 'Pole :attribute musi być poprawnym adresem MAC.',
    'max' => [
        'array' => 'Pole :attribute nie może zawierać więcej niż :max elementów.',
        'file' => 'Plik w polu :attribute nie może być większy niż :max kilobajtów.',
        'numeric' => 'Pole :attribute nie może zawierać wartości większej niż :max.',
        'string' => 'Pole :attribute nie może mieć więcej niż :max znaków.',
    ],
    'max_digits' => 'Pole :attribute nie może składać się z więcej niż :max cyfr.',
    'mimes' => 'Pole :attribute musi zawierać plik typu: :values.',
    'mimetypes' => 'Pole :attribute musi zawierać plik typu: :values.',
    'min' => [
        'array' => 'Pole :attribute musi zawierać co najmniej :min elementów.',
        'file' => 'Plik w polu :attribute musi mieć co najmniej :min kilobajtów.',
        'numeric' => 'Pole :attribute musi zawierać wartość nie mniejszą niż :min.',
        'string' => 'Pole :attribute musi mieć co najmniej :min znaków.',
    ],
    'min_digits' => 'Pole :attribute musi składać się z co najmniej :min cyfr.',
    'missing' => 'Pole :attribute nie może występować.',
    'missing_if' => 'Pole :attribute nie może występować, gdy :other ma wartość :value.',
    'missing_unless' => 'Pole :attribute nie może występować, chyba że :other ma wartość :value.',
    'missing_with' => 'Pole :attribute nie może występować, gdy podano :values.',
    'missing_with_all' => 'Pole :attribute nie może występować, gdy podano wszystkie: :values.',
    'multiple_of' => 'Pole :attribute musi być wielokrotnością :value.',
    'not_in' => 'Wybrana wartość pola :attribute jest nieprawidłowa.',
    'not_regex' => 'Format pola :attribute jest nieprawidłowy.',
    'numeric' => 'Pole :attribute musi być liczbą.',
    'password' => [
        'letters' => 'Pole :attribute musi zawierać co najmniej jedną literę.',
        'mixed' => 'Pole :attribute musi zawierać co najmniej jedną wielką i jedną małą literę.',
        'numbers' => 'Pole :attribute musi zawierać co najmniej jedną cyfrę.',
        'symbols' => 'Pole :attribute musi zawierać co najmniej jeden znak specjalny.',
        'uncompromised' => 'Podane :attribute wystąpiło w wycieku danych. Wybierz inne.',
    ],
    'present' => 'Pole :attribute musi zostać przesłane.',
    'present_if' => 'Pole :attribute musi zostać przesłane, gdy :other ma wartość :value.',
    'present_unless' => 'Pole :attribute musi zostać przesłane, chyba że :other ma wartość :value.',
    'present_with' => 'Pole :attribute musi zostać przesłane, gdy podano :values.',
    'present_with_all' => 'Pole :attribute musi zostać przesłane, gdy podano wszystkie: :values.',
    'prohibited' => 'Pole :attribute jest niedozwolone.',
    'prohibited_if' => 'Pole :attribute jest niedozwolone, gdy :other ma wartość :value.',
    'prohibited_if_accepted' => 'Pole :attribute jest niedozwolone, gdy :other zostało zaakceptowane.',
    'prohibited_if_declined' => 'Pole :attribute jest niedozwolone, gdy :other zostało odrzucone.',
    'prohibited_unless' => 'Pole :attribute jest niedozwolone, chyba że :other ma jedną z wartości: :values.',
    'prohibits' => 'Pole :attribute wyklucza obecność pola :other.',
    'regex' => 'Format pola :attribute jest nieprawidłowy.',
    'required' => 'Pole :attribute jest wymagane.',
    'required_array_keys' => 'Pole :attribute musi zawierać wpisy dla: :values.',
    'required_if' => 'Pole :attribute jest wymagane, gdy :other ma wartość :value.',
    'required_if_accepted' => 'Pole :attribute jest wymagane, gdy :other zostało zaakceptowane.',
    'required_if_declined' => 'Pole :attribute jest wymagane, gdy :other zostało odrzucone.',
    'required_unless' => 'Pole :attribute jest wymagane, chyba że :other ma jedną z wartości: :values.',
    'required_with' => 'Pole :attribute jest wymagane, gdy podano :values.',
    'required_with_all' => 'Pole :attribute jest wymagane, gdy podano wszystkie: :values.',
    'required_without' => 'Pole :attribute jest wymagane, gdy nie podano :values.',
    'required_without_all' => 'Pole :attribute jest wymagane, gdy nie podano żadnej z wartości: :values.',
    'same' => 'Pole :attribute musi być zgodne z :other.',
    'size' => [
        'array' => 'Pole :attribute musi zawierać :size elementów.',
        'file' => 'Plik w polu :attribute musi mieć :size kilobajtów.',
        'numeric' => 'Pole :attribute musi mieć wartość :size.',
        'string' => 'Pole :attribute musi mieć :size znaków.',
    ],
    'starts_with' => 'Pole :attribute musi zaczynać się jedną z wartości: :values.',
    'string' => 'Pole :attribute musi być ciągiem znaków.',
    'timezone' => 'Pole :attribute musi zawierać poprawną strefę czasową.',
    'unique' => 'Taka wartość pola :attribute jest już zajęta.',
    'uploaded' => 'Nie udało się przesłać pliku w polu :attribute.',
    'uppercase' => 'Pole :attribute może zawierać wyłącznie wielkie litery.',
    'url' => 'Pole :attribute musi być poprawnym adresem URL.',
    'ulid' => 'Pole :attribute musi być poprawnym identyfikatorem ULID.',
    'uuid' => 'Pole :attribute musi być poprawnym identyfikatorem UUID.',

    /*
    |--------------------------------------------------------------------------
    | Własne komunikaty walidacji
    |--------------------------------------------------------------------------
    |
    | Konwencja „atrybut.reguła". Celowo PUSTE: komunikaty specyficzne dla domeny
    | mieszkają w plikach modułów (np. lang/pl/knowledge.php), gdzie stoją obok reguły,
    | którą opisują — trzymanie ich tutaj rozdzieliłoby regułę od jej zdania.
    |
    */

    'custom' => [],

    /*
    |--------------------------------------------------------------------------
    | Nazwy atrybutów
    |--------------------------------------------------------------------------
    |
    | Podmiana nazwy pola na czytelną. Celowo PUSTA na start: nazwa pola w komunikacie
    | pochodzi wtedy z klucza żądania, co jest prawdą, a nie zgadywaniem. Wpisy dodajemy
    | wtedy, gdy konkretny formularz tego potrzebuje — pusta lista nie kłamie, a lista
    | wypełniona „na zapas" rozjeżdża się z API przy pierwszej zmianie nazwy pola.
    |
    */

    'attributes' => [],

];
