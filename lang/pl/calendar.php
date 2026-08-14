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
    ],

    // Własne źródło Kalendarza — nazwane tutaj, bo Kalendarz posiada to, co pokazuje.
    'sources' => [
        'event' => 'Wydarzenia',
    ],

];
