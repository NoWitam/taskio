<?php

return [

    // Pełny odpowiednik lang/en/publishing.php. Aplikacja jest przełączalna PL+EN, a proza serwera idzie
    // za językiem CZYTAJĄCEGO, nie za APP_LOCALE — więc żaden napis nie może być wpisany na sztywno w
    // kodzie, łącznie z plakietką na kalendarzu i nazwą samego źródła.

    'status' => [
        'draft' => 'Szkic',
        'scheduled' => 'Zaplanowana',
        'publishing' => 'Publikowanie',
        'published' => 'Opublikowana',
        'failed' => 'Nieudana',
        // NIE „nieudana" i NIE „ponawianie". Sens tego stanu jest taki, że nie wiemy, czy post
        // istnieje — nazwa ma skłonić do sprawdzenia, a nie do kliknięcia „ponów".
        'needs_reconcile' => 'Do sprawdzenia',
        'blocked' => 'Wstrzymana',
    ],

    'platforms' => [
        'youtube' => 'YouTube',
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        // Nazwane tak, żeby nikt nie wybrał tego w przekonaniu, że coś zostanie opublikowane.
        'dry_run' => 'Próba (nic nie zostanie opublikowane)',
    ],

    'calendar' => [
        'source' => 'Publikacje',
    ],

    'transitions' => [
        // NAJWAŻNIEJSZE zdanie w tym pliku.
        'reconcile_before_retry' => 'Ta publikacja może już być opublikowana — straciliśmy kontakt, zanim udało się to potwierdzić. Najpierw sprawdź platformę; ponowna publikacja może wystawić ją drugi raz, a opublikowanego posta nie da się stąd wycofać.',
        'blocked_holds' => 'Ta publikacja jest wstrzymana, bo jej połączenie nie działa. Połącz konto ponownie i zaplanuj ją jeszcze raz — publikowanie teraz zakończy się błędem dla każdej pozycji czekającej na to połączenie.',
        'terminal' => 'To zostało już opublikowane. Nie da się tego stąd zmienić.',
        'not_allowed' => 'Publikacja nie może przejść z „:from" do „:to".',
    ],

    'validation' => [
        'status_not_accepted' => 'Status publikacji wynika z zaplanowania i opublikowania jej, a nie z treści żądania.',
        'remote_not_accepted' => 'To, co odesłała platforma, zapisujemy w chwili, gdy to nastąpi; nie jest to część żądania.',
        'platform_unknown' => 'To nie jest miejsce, w którym ta aplikacja potrafi publikować.',
        'scheduled_at_unreadable' => 'To nie wygląda na datę i godzinę.',
        'scheduled_in_the_past' => 'Ta chwila już minęła. Wybierz termin w przyszłości albo opublikuj od razu.',
    ],

    'failures' => [
        'title_missing' => 'Ta publikacja nie ma tytułu, więc nie ma czego wysłać.',
        'publish_outcome_unknown' => 'Straciliśmy kontakt z platformą i nie udało się potwierdzić, co się stało.',
        'reconciled_absent' => 'Sprawdziliśmy platformę: nic nie zostało opublikowane, więc można bezpiecznie spróbować ponownie.',
    ],

];
