<?php

return [

    // Moduł Kalendarza posiada kontrakt, rejestr — a od R3 B3 także JEDEN własny temat: WYDARZENIE.
    // Każdy napis dotyczący tematu POŻYCZONEGO (nazwa cudzego źródła, plakietka wystąpienia) nadal
    // tłumaczy moduł, który ten temat posiada; dzięki temu nowe źródło nie wymaga wpisu tutaj.
    'validation' => [
        'window_too_large' => 'Widok kalendarza może obejmować najwyżej :max dni naraz.',

        // Rozróżnik całodniowości, pilnowany w OBIE strony. Nadmiarowe dane są odrzucane, a nie po cichu
        // przycinane: nadawca, który sądzi, że zapisał godzinę wydarzenia rysowanego jako cały dzień,
        // nie ma żadnego sposobu, by tę rozbieżność zauważyć.
        'start_date_required' => 'Wydarzenie całodniowe wymaga daty.',
        'starts_at_required' => 'Wydarzenie, które nie jest całodniowe, wymaga godziny rozpoczęcia.',
        'instant_on_all_day' => 'Wydarzenie całodniowe nie ma godziny. Usuń ją albo wyłącz tryb całodniowy.',
        'day_on_timed' => 'Wydarzenie z godziną rozpoczęcia nie ma osobnej daty. Usuń ją albo włącz tryb całodniowy.',
        'end_before_start' => 'Wydarzenie nie może zakończyć się przed rozpoczęciem.',

        // Kolor w siatce niesie ZNACZENIE, które umie wyjaśnić źródło (priorytet zadania, wynik
        // przebiegu). Wydarzenie nie niesie żadnego, więc nie ma czego ustawiać — odrzucamy zamiast po
        // cichu pomijać, żeby nadawca, który nadal wysyła kolor, się o tym dowiedział.
        'color_not_accepted' => 'Wydarzenie nie ma własnego koloru; kalendarz pokazuje wszystkie wydarzenia tak samo.',

        // Wskaźnik jest opcjonalny, ale w całości: połowa wskaźnika nie prowadzi donikąd, a wygląda jak
        // odnośnik, za którym da się pójść.
        'subject_incomplete' => 'Powiązany element wymaga jednocześnie typu i identyfikatora.',

        // POWTARZALNOŚĆ (R3 B4). Gramatyka kadencji mieszka we wspólnej warstwie i odpowiada KODAMI, bez
        // jednego zdania — bo aplikacja jest przełączalna PL/EN, a każdy odbiorca tej gramatyki mówi o
        // czymś innym (dla automatyzacji to „godzina wyzwolenia", dla kalendarza „początek wydarzenia").
        // Poniższe napisy są renderem Kalendarza dla tych kodów, w słownictwie WĄSKIEGO podzbioru, który
        // ten moduł przyjmuje.
        'recurrence' => [
            // Kody gramatyki, które mogą wystąpić w podzbiorze Kalendarza.
            'mode_required' => 'Wybierz rodzaj powtarzania dla tej osi reguły.',
            'list_required' => 'Wybrany rodzaj powtarzania wymaga wartości w polu „:field".',
            'field_not_allowed' => 'Pole „:field" nie należy do wybranego rodzaju powtarzania. Usuń je albo zmień rodzaj.',
            'special_required' => 'Wybierz konkretną regułę dnia miesiąca.',
            'special_needs_ordinal' => 'Ta reguła wymaga wskazania, który z kolei dzień w miesiącu ma to być.',
            'special_needs_weekday' => 'Ta reguła wymaga wskazania dnia tygodnia.',

            // Kody, których podzbiór Kalendarza nie potrafi wywołać (okna kroków co N, reguła ostatniego
            // dnia roboczego, klucze wykluczeń, których nie zapisujemy). Renderowane, a nie pominięte —
            // bo brak gałęzi domyślnej jest tym, co każe NOWEMU kodowi rozbić się tutaj, zamiast dotrzeć
            // do użytkownika jako pusty komunikat.
            'unsupported' => 'Kalendarz nie obsługuje tego rodzaju powtarzania.',

            // Podzbiór: rodzaje, których nie przyjmujemy — i to nie jest oszczędność. Tryby „co N dni"
            // kompilują się do siatki resetowanej co miesiąc, więc znaczą co innego, niż brzmią.
            'day_mode_not_supported' => 'Kalendarz nie obsługuje tego rodzaju powtarzania dni.',
            'day_special_not_supported' => 'Kalendarz nie obsługuje tej reguły dnia miesiąca.',
            'month_mode_not_supported' => 'Kalendarz nie obsługuje tego rodzaju powtarzania miesięcy.',
            'exclusion_key_not_accepted' => 'Wydarzenie cykliczne pomija wybrane DATY. Żeby pominąć dni tygodnia albo miesiące, ustaw je wprost w regule powtarzania.',

            // Godzina serii to godzina samego wydarzenia, a strefa jest stemplowana przy zapisie —
            // nadawca nie ustawia żadnej z nich. Odrzucamy zamiast po cichu pomijać: kto to wysyła,
            // sądzi, że coś ustawia.
            'time_not_accepted' => 'Godzina powtarzania jest godziną samego wydarzenia i nie ustawia się jej osobno.',
            'timezone_not_accepted' => 'Strefa czasowa powtarzania pochodzi z ustawień przestrzeni roboczej i nie przesyła się jej w żądaniu.',

            // Kotwica: początek wydarzenia MUSI być pierwszym wystąpieniem reguły. Inaczej „początek"
            // przestaje znaczyć początek, a podział serii przestaje być poprawny z konstrukcji.
            'anchor_not_an_occurrence' => 'Początek wydarzenia musi być pierwszym wystąpieniem reguły powtarzania. Zmień datę początku albo regułę.',
            'whole_minute' => 'Wydarzenie cykliczne musi zaczynać się o pełnej minucie.',

            // Koniec serii: data albo liczba powtórzeń, nigdy oba.
            'end_is_one_thing' => 'Podaj albo datę końca powtarzania, albo liczbę powtórzeń — nie oba naraz.',
            'end_before_start' => 'Koniec powtarzania nie może wypadać przed początkiem wydarzenia.',
            'count_unreachable' => 'Ta reguła nie ma tylu wystąpień w rozsądnym horyzoncie. Podaj datę końca powtarzania zamiast liczby powtórzeń.',

            // Kotwica dowodzi, że KADENCJA trafia w początek wydarzenia. Seria to kadencja MINUS
            // pominięte dni, ograniczona własną datą końca — i taka seria potrafi nie mieć ani jednego
            // dnia. Bez tego sprawdzenia wydarzenie zapisuje się z kodem 201 i po prostu nigdzie się nie
            // pojawia.
            'series_has_no_occurrences' => 'Ta seria nie ma ani jednego wystąpienia — pominięte dni i data końca wykluczają wszystko. Zmień datę końca albo pominięte dni.',

            // Zakres operacji na serii.
            'scope_on_create' => 'Zakres serii dotyczy istniejącego wydarzenia; przy tworzeniu nie ma czego zawężać.',
            'occurrence_date_without_scope' => 'Data wystąpienia ma sens tylko wtedy, gdy operacja dotyczy pojedynczego wystąpienia albo wystąpień od wskazanego dnia.',
            'occurrence_date_required' => 'Wskaż dzień wystąpienia, którego dotyczy ta operacja.',
            'event_does_not_repeat' => 'To wydarzenie się nie powtarza, więc nie ma pojedynczych wystąpień.',
            'not_an_occurrence' => 'Ta seria nie ma wystąpienia w tym dniu.',
            'occurrence_has_no_rule' => 'Pojedyncze wystąpienie nie ma własnej reguły powtarzania — po edycji staje się osobnym, jednorazowym wydarzeniem.',
            'split_starts_before_the_split' => 'Nowa seria nie może zaczynać się przed wystąpieniem, od którego dzielisz — inaczej obie serie rysowałyby te same dni.',
            'exclusions_full' => 'Ta seria ma już maksymalną liczbę pominiętych dni (:max). Podziel serię zamiast pomijać kolejne wystąpienia.',
        ],
    ],

    // Własne źródło Kalendarza — nazwane tutaj, bo Kalendarz posiada to, co pokazuje.
    'sources' => [
        'event' => 'Wydarzenia',
    ],

    // R3 B5 — JAK CZĘSTO POWTARZA SIĘ SERIA, jako jedno zdanie przy każdym wystąpieniu.
    //
    // Tłumaczone TUTAJ, po stronie serwera, z tego samego powodu co etykieta źródła i plakietka
    // wystąpienia: słownikiem wystąpienia na drucie jest PROZA, więc klient potrafi narysować znacznik
    // serii dla źródła, o którym nigdy nie słyszał. Kod kadencji wymagałby nowego słownika po stronie
    // klienta dla każdego źródła, które taki kod dostanie — a obietnica „piąte źródło nie wymaga zmian
    // we frontendzie" po cichu przestałaby być prawdziwa.
    //
    // Moduł Workflowów ma własne zdanie o kadencji i NIE jest ono używane ponownie: tamto nazywa ODSTĘP
    // dwóch trybów poddobowych („Co 5 min"), których podzbiór Kalendarza w ogóle nie umie wyrazić, i
    // mówi do kogoś, kto czyta automatyzację, a nie do kogoś, kto planuje spotkanie.
    //
    // UWAGA DLA TŁUMACZY. Sufiks miesięcy oraz zwroty „ostatni <dzień tygodnia>" są wypisane wprost dla
    // każdego języka, a nie składane z nazwy i przymiotnika, bo w językach fleksyjnych (polski wśród
    // nich) złożyć ich nie sposób: „ostatni poniedziałek" i „ostatnia środa" różnią się PRZYMIOTNIKIEM,
    // którego żaden znacznik nie podstawi.
    'cadence' => [
        'daily' => 'Codziennie',
        'weekly' => 'Co tydzień: :days',
        'monthly_days' => 'Co miesiąc, dnia :days',
        'monthly_last_day' => 'Co miesiąc, ostatniego dnia',
        'monthly_nth_weekday' => 'Co miesiąc: :ordinal :weekday',
        'monthly_last_weekday' => 'Co miesiąc: :weekday',

        // REGUŁA MIESIĘCZNA ZAWĘŻONA DO JEDNEGO MIESIĄCA JEST REGUŁĄ ROCZNĄ i musi to powiedzieć.
        // „Co miesiąc, dnia 25 (sierpień)" jest prawdziwe słowo po słowie i czyta się jako „znowu za
        // miesiąc" — na najbardziej ludzkim presecie, jaki istnieje: urodzinach i rocznicach. Ktoś
        // planuje rocznicę ślubu, a siatka mówi mu, że powtórzy się za cztery tygodnie.
        //
        // Zwija się tak WYŁĄCZNIE rodzina miesięczna. „Codziennie (sierpień)" i „Co tydzień: pon.
        // (sierpień)" zostają bez zmian: ich słowo o kadencji jest nadal prawdziwe wewnątrz miesiąca,
        // który nazywają, więc nic w nich nie myli.
        'yearly_days' => 'Co roku, :days :month',
        'yearly_last_day' => 'Co roku, ostatniego dnia :month',
        'yearly_nth_weekday' => 'Co roku: :ordinal :weekday :month',
        'yearly_last_weekday' => 'Co roku: :weekday :month',

        // Oś miesięcy, gdy seria NIE biegnie co miesiąc. Nigdy pomijana, kiedy zawęża: „Co tydzień:
        // pon." to nieprawda o regule, która wypada wyłącznie w styczniu.
        'in_months' => ':cadence (:months)',

        'separator' => ', ',

        // 0 = niedziela .. 6 = sobota — konwencja wspólnej warstwy, ta sama co w Carbonie i w cronie.
        'weekdays' => [
            '0' => 'niedz.',
            '1' => 'pon.',
            '2' => 'wt.',
            '3' => 'śr.',
            '4' => 'czw.',
            '5' => 'pt.',
            '6' => 'sob.',
        ],

        'last_weekdays' => [
            '0' => 'ostatnia niedziela',
            '1' => 'ostatni poniedziałek',
            '2' => 'ostatni wtorek',
            '3' => 'ostatnia środa',
            '4' => 'ostatni czwartek',
            '5' => 'ostatni piątek',
            '6' => 'ostatnia sobota',
        ],

        // Liczebnik porządkowy zapisany CYFRĄ z kropką, i to jest decyzja, nie skrót. Słowny („trzecia"
        // / „trzeci") uzgadnia rodzaj z nazwą dnia, a nazwy dni tygodnia mają w polszczyźnie oba rodzaje
        // — zapis cyfrowy czyta się z rodzajem rzeczownika, więc „3. środa" i „3. wtorek" są poprawne
        // bez siedmiu wariantów każdego liczebnika.
        'ordinals' => [
            '1' => '1.',
            '2' => '2.',
            '3' => '3.',
            '4' => '4.',
            '5' => '5.',
        ],

        // Miesiące jako SAMODZIELNA NAZWA (mianownik) — do wyliczenia w `in_months`.
        'months' => [
            '1' => 'styczeń',
            '2' => 'luty',
            '3' => 'marzec',
            '4' => 'kwiecień',
            '5' => 'maj',
            '6' => 'czerwiec',
            '7' => 'lipiec',
            '8' => 'sierpień',
            '9' => 'wrzesień',
            '10' => 'październik',
            '11' => 'listopad',
            '12' => 'grudzień',
        ],

        // Miesiące tak, jak stoją WEWNĄTRZ DATY (dopełniacz) — „25 sierpnia", „ostatniego dnia
        // sierpnia". Po polsku to inna forma niż mianownik wyżej, po angielsku ta sama; dwa katalogi
        // zamiast jednego z tego samego powodu, dla którego wypisane są `last_weekdays`: język
        // fleksyjny nie złoży formy z nazwy i znacznika, a język, który by potrafił, płaci dwanaście
        // powtórzonych wierszy.
        'months_in_date' => [
            '1' => 'stycznia',
            '2' => 'lutego',
            '3' => 'marca',
            '4' => 'kwietnia',
            '5' => 'maja',
            '6' => 'czerwca',
            '7' => 'lipca',
            '8' => 'sierpnia',
            '9' => 'września',
            '10' => 'października',
            '11' => 'listopada',
            '12' => 'grudnia',
        ],
    ],

];
