<?php

return [

    // Bazy wiedzy + wpisy (B1). Jedyne tłumaczone komunikaty serwera w tym module: odpowiedzi na
    // konflikt stanu i komunikaty walidacji, na które autor może zareagować. Reszta jest strukturalna.
    'entries' => [
        'stale_write' => 'Ktoś inny zapisał ten wpis, gdy go edytowałeś. Odśwież wpis, aby zobaczyć jego wersję, zanim zapiszesz ponownie.',
        'slug_conflict' => 'Adres „:slug" jest teraz używany przez inny wpis. Zmień nazwę tamtego wpisu, a potem przywróć ten.',
        'seed_is_alias' => '„:seed" to inna forma nazwy wpisu, który już istnieje: „:title". Otwórz tamten wpis zamiast pisać nowy.',
    ],

    // Kreator AI (B11a). Tłumaczona jest tylko odmowa budżetowa: każdy inny wynik to stabilny KOD na
    // sesji (`failure_reason`), który klient ubiera w słowa sam — ma na to miejsce i zna język.
    'drafting' => [
        'ai_budget_exceeded' => 'Kreator AI jest niedostępny: ta przestrzeń wyczerpała szacowany miesięczny budżet AI. Odnowi się na początku kolejnego miesiąca, a właściciel może podnieść limit.',
        // Wskazuje drogę dalej, a nie samą odmowę: powtórzone wyszukanie kosztowałoby tyle samo i
        // znalazłoby to samo, a poprawka sprawia, że kolejne ma już sens.
        'context_already_expanded' => 'Kontekst tej sesji został już poszerzony. Poproś najpierw o poprawkę — kolejne wyszukanie ruszy względem tego, co wróci.',
    ],

    // Indeksowanie (B2a) — jedyny konflikt stanu, na który użytkownik może trafić klikając przycisk.
    'index' => [
        'not_retryable' => 'Nie ma czego ponawiać: indeksowanie tego wpisu ma stan „:status". Ponowić można tylko przebieg, który zakończył się błędem, wyczerpał budżet AI albo zindeksował wpis częściowo.',
    ],

    // Krawędzie grafu (B2b). Odrzucić można tylko PROPOZYCJĘ maszyny: wikilink to treść wpisu (zmień
    // treść), a link ręczny narysował człowiek celowo (usuń go).
    'links' => [
        'not_dismissable' => 'Odrzucić można tylko proponowane powiązania. Aby usunąć link zapisany w treści, zmień treść wpisu; link dodany ręcznie po prostu usuń.',
    ],

    // RELACJE TYPOWANE — stwierdzenia, które baza zapisuje o parach wpisów. To jedyne odmowy, jakie
    // może napotkać poprawnie zbudowane i autoryzowane żądanie; każda mówi, co zrobić zamiast tego, bo
    // odmowa mówiąca tylko „nie" zostawia autora z pytaniem, czy trafił na regułę, czy na błąd.
    'relations' => [
        'duplicate' => 'Ta baza już zapisuje taką relację. Zakończ istniejącą albo nadaj tej inną datę początku, jeśli chodzi o osobne zdarzenie.',
        'cap_reached' => 'Ten wpis ma już maksymalną liczbę :max aktywnych relacji. Zakończ tę, która przestała obowiązywać, albo podziel wpis — coś połączonego z czterdziestoma innymi rzeczami to zwykle kategoria, a nie temat.',
        'pair_refused' => 'Typ „:from_entry_type" nie może mieć relacji „:relation_type" do typu „:to_entry_type". Sprawdź kierunek albo popraw typ jednego z wpisów.',
        'type_not_allowed' => 'Ta baza wiedzy nie używa relacji „:relation_type". Właściciel bazy może dodać ją do dozwolonych typów relacji.',
        'property_refused' => 'Ten rodzaj relacji nie ma pola „:property". Usuń je albo zapisz ten szczegół w opisie.',
        'dates_reversed' => 'Ta relacja skończyłaby się (:valid_to), zanim się zaczęła (:valid_from). Popraw jedną z dat.',
        // Klucz, którego bieżąca propozycja nie zawiera, znaczy, że klient patrzy na NIEAKTUALNY
        // podgląd — niemal zawsze dlatego, że szkic został poprawiony i operacje przenumerowano.
        // Odmowa zamiast częściowego zastosowania: działanie na nieaktualnym wyborze to sposób, w jaki
        // recenzent zatwierdza jedno, a dostaje coś innego.
        'unknown_op_key' => 'Ta propozycja zmieniła się od czasu jej otwarcia (:keys). Odśwież przegląd i wybierz ponownie.',
        // Zakończenie i relacja, która je zastępuje, opisują JEDNĄ zmianę. Przyjęcie połowy zostawia
        // bazę mówiącą coś, czego nikt nie miał na myśli — więc para jest odrzucana, a nie stosowana w
        // połowie ani po cichu uzupełniana (to zapisałoby coś, czego recenzent nie zaznaczył).
        'inseparable_ops' => 'Te operacje opisują jedną zmianę i trzeba je przyjąć razem albo wcale (:pairs).',
        // Nieosiągalne z obecnego UI, ale zgłaszane na TABLICY, żeby klient filtrujący po dokładnym
        // kluczu `graph_op_keys` pokazał to w panelu operacji, a nie na karcie szkicu.
        'duplicate_op_key' => 'Ta sama operacja została wybrana więcej niż raz. Odśwież przegląd i wybierz ponownie.',
    ],

    // CZASOWNIKI relacji czytane w przód, tak by dopełniały „<wpis> …" — panel składa zdanie, a nie
    // etykietę: „Anna — jest członkiem — Acme".
    'relation_types' => [
        'member_of' => 'jest członkiem',
        'works_on' => 'pracuje nad',
        'knows' => 'zna',
        'created' => 'stworzył(a)',
        'owns' => 'jest właścicielem',
        'located_in' => 'znajduje się w',
        'participated_in' => 'brał(a) udział w',
        'occurred_during' => 'wydarzyło się podczas',
        'part_of' => 'jest częścią',
        'is_a' => 'jest rodzajem',
        'uses' => 'używa',
        'depends_on' => 'zależy od',
        'precedes' => 'poprzedza',
        'caused' => 'spowodował(a)',
        'opposes' => 'jest w konflikcie z',
        'visited' => 'odwiedził(a)',
        'organized' => 'zorganizował(a)',
        'won' => 'wygrał(a)',
        'interacted_with' => 'nawiązał(a) kontakt z',
        'related_to' => 'jest powiązany(a) z',
    ],

    // Te same czasowniki czytane WSTECZ, dla panelu po drugiej stronie relacji. Nie da się ich wyliczyć
    // z formy w przód — właśnie dlatego serwer wysyła obie.
    'relation_types_inverse' => [
        'member_of' => 'ma członka',
        'works_on' => 'jest przedmiotem pracy',
        'knows' => 'zna',
        'created' => 'został(a) stworzony(a) przez',
        'owns' => 'należy do',
        'located_in' => 'jest miejscem dla',
        'participated_in' => 'miał(o) uczestnika',
        'occurred_during' => 'był tłem dla',
        'part_of' => 'obejmuje',
        'is_a' => 'jest kategorią dla',
        'uses' => 'jest używany(a) przez',
        'depends_on' => 'jest zależnością dla',
        'precedes' => 'następuje po',
        'caused' => 'został(a) spowodowany(a) przez',
        'opposes' => 'jest w konflikcie z',
        'visited' => 'został(a) odwiedzony(a) przez',
        'organized' => 'został(a) zorganizowany(a) przez',
        'won' => 'został(a) wygrany(a) przez',
        'interacted_with' => 'przyjął(ęła) kontakt od',
        'related_to' => 'jest powiązany(a) z',
    ],

    'relation_states' => [
        'active' => 'aktualna',
        'ended' => 'zakończona',
        'retracted' => 'wycofana',
    ],

    // RODZAJ rzeczy, o której mówi wpis. Czyta je wyłącznie macierz relacji, a wpis, którego nikt nie
    // sklasyfikował, jest NIEOTYPOWANY, a nie „inny".
    'entry_types' => [
        'person' => 'Osoba',
        'organization' => 'Organizacja',
        'event' => 'Wydarzenie',
        'place' => 'Miejsce',
        'product' => 'Produkt',
        'work' => 'Dzieło',
        'concept' => 'Pojęcie',
        'other' => 'Inne',
    ],

    'validation' => [
        // Schemat metadanych (pola zadeklarowane przez bazę).
        'schema_must_be_list' => 'Schemat metadanych musi być listą pól.',
        'schema_too_many_fields' => 'Baza wiedzy może zadeklarować najwyżej :max pól metadanych.',
        'field_key_invalid' => 'Każde pole metadanych wymaga klucza z liter, cyfr i podkreśleń, niezaczynającego się od cyfry.',
        'field_key_duplicate' => 'Klucze pól metadanych muszą być różne.',
        'field_label_invalid' => 'Etykieta pola metadanych musi być tekstem o długości najwyżej 255 znaków.',

        // Wartości metadanych (mapa wpisu).
        'metadata_must_be_object' => 'Metadane muszą być obiektem wartości pól.',
        'metadata_field_unknown' => 'Pole „:field" nie jest zadeklarowane w tej bazie wiedzy.',

        // Bramka dyrektyw szablonów (fail-closed). Każdy komunikat nazywa znalezioną składnię, więc
        // autor wie, co usunąć, zamiast zgadywać, co znaczy „nieprawidłowa treść".
        'directive_directive' => 'Treść wiedzy nie może zawierać dyrektyw edytora (@[...]). Usuń je i zapisz ponownie.',
        'directive_reference' => 'Treść wiedzy nie może zawierać znaczników szablonu ({{ ... }}). Usuń je i zapisz ponownie.',
        'directive_if_block' => 'Treść wiedzy nie może zawierać warunkowych bloków szablonu (```if-block). Usuń je i zapisz ponownie.',
        'directive_branch' => 'Treść wiedzy nie może zawierać znaczników gałęzi warunkowych ([[IF]], [[ELSE_IF]], [[ELSE]]). Usuń je i zapisz ponownie.',
        'directive_nul' => 'Treść wiedzy nie może zawierać bajtów zerowych.',

        // Limit fragmentów (B2a). To limit KOSZTU indeksowania jednego wpisu, niezależny od limitu
        // znaków: wpis podzielony na wiele krótkich sekcji z nagłówkami daje więcej fragmentów niż
        // ten sam tekst napisany ciągiem. Komunikat podaje obie liczby, bo bez nich autor nie ma jak
        // ocenić, o ile musi skrócić wpis.
        'too_many_chunks' => 'Ten wpis dzieli się na :count fragmentów, a jeden wpis może mieć ich najwyżej :max. Podziel go na kilka mniejszych wpisów.',

        // Slug (stabilny adres wpisu).
        'slug_normalized' => 'Ten adres nie ma oczekiwanej postaci. Użyj „:slug".',
        'slug_taken' => 'Inny wpis w tej bazie wiedzy używa już tego adresu.',

    ],

];
