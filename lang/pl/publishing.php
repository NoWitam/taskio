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

    // ─────────────────────────────────────────────────────────────────────────────────────────────
    // POŁĄCZONE KONTA (B2)
    // ─────────────────────────────────────────────────────────────────────────────────────────────
    'connection_status' => [
        'active' => 'Połączone',
        // NIE „wygasłe" i NIE „błąd". Czytający ma zrozumieć, że tylko on to naprawi i że wymaga to
        // powrotu na platformę — a nie kliknięcia „ponów" tutaj.
        'needs_reauth' => 'Wymaga ponownego połączenia',
        'revoked' => 'Rozłączone',
    ],

    // KLUCZE TO DOKŁADNIE `PlatformConnectionManager::FAILURE_CODES`, w obu językach — pilnuje tego
    // `PublishingConnectionVocabularyTest`. Raz już się rozjechały: odświeżacz zapisywał
    // `token_refresh_failed`, a ten plik znał tylko `refresh_failed`, i ponieważ domyślna wartość w
    // fabryce pasowała do TEGO pliku, każdy test renderował zdanie — tylko nie to, które zobaczyłby
    // użytkownik po prawdziwej awarii.
    'connection_failures' => [
        'refresh_failed' => 'Platforma odmówiła odnowienia dostępu do tego konta. Połącz je ponownie, żeby dalej publikować.',
        'refresh_unsupported' => 'To połączenie nie ma już czym odnowić dostępu. Połącz konto ponownie.',
        // Przypadek rotacji APP_KEY. Celowo mówi, CO ZROBIĆ, a nie co się stało — to drugie jest
        // sprawą administratora, a lekarstwem i tak jest ponowne połączenie.
        'credentials_unreadable' => 'Zapisany dostęp do tego konta nie może już zostać odczytany przez aplikację. Połącz je ponownie.',
        // Zapisywane przez `revoke()`. Do przeglądu B2 nie zapisywał tego nikt, więc rozłączone konto
        // zostawało z kodem ostatniej awarii i tłumaczyło nieudane odnowienie dostępu do konta, które
        // ktoś świadomie usunął.
        'disconnected_by_user' => 'To konto zostało rozłączone.',
    ],

    // Dlaczego publikacje zostały wstrzymane. Każdy komunikat nazywa PRZYCZYNĘ i lekarstwo, bo z samą
    // publikacją wszystko jest w porządku — zepsute jest coś zupełnie gdzie indziej.
    'holds' => [
        'connection_needs_reauth' => 'Wstrzymane: konto, na które to idzie, wymaga ponownego połączenia. Napraw połączenie, a publikacja sama wróci na swój termin.',
        'connection_disconnected' => 'Wstrzymane: konto, na które to idzie, zostało rozłączone. Połącz je ponownie albo wybierz inne miejsce docelowe.',
    ],

    'oauth' => [
        'connected' => 'Konto połączone.',
        'failed' => 'Nie udało się połączyć konta.',
        'oauth_state_expired' => 'Ten odnośnik wygasł. Zacznij łączenie konta jeszcze raz.',
        'oauth_state_already_used' => 'Ten odnośnik został już użyty. Zacznij łączenie konta jeszcze raz.',
        // Łączenie dokończono w innej przeglądarce niż ta, w której się zaczęło. Celowo NIE mówi o
        // „bezpieczeństwie" ani nie nazywa ataku: najczęstszy powód to skopiowany odnośnik albo
        // wyłączone ciasteczka, a lekarstwo jest w obu przypadkach to samo.
        'oauth_browser_mismatch' => 'Łączenie trzeba dokończyć w tej samej przeglądarce, w której się zaczęło. Zacznij łączenie konta jeszcze raz i zostań w tym oknie.',
        'access_denied' => 'Prośba o uprawnienia została odrzucona, więc nic nie zostało połączone.',
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

        // B2. Miejsce docelowe istnieje, ale nie stoi za nim żadne konto — „próba" nic nie publikuje.
        'platform_not_connectable' => 'To miejsce docelowe nie ma konta do połączenia. To próba: nic, co tam trafi, nie opuszcza aplikacji.',
        // Miejsce docelowe jest prawdziwe, ale ta instalacja nie ma jeszcze do niego poświadczeń.
        // Mówi, czego brakuje, a nie „coś poszło nie tak" — bo lekarstwo należy do administratora.
        'platform_not_configured' => 'Ta aplikacja nie jest jeszcze zarejestrowana na tej platformie, więc nie można połączyć konta. Musi to najpierw skonfigurować administrator.',
        'connection_unusable' => 'To konto nie jest dostępne dla tego miejsca docelowego.',
    ],

    'failures' => [
        'title_missing' => 'Ta publikacja nie ma tytułu, więc nie ma czego wysłać.',
        'publish_outcome_unknown' => 'Straciliśmy kontakt z platformą i nie udało się potwierdzić, co się stało.',
        'reconciled_absent' => 'Sprawdziliśmy platformę: nic nie zostało opublikowane, więc można bezpiecznie spróbować ponownie.',
    ],

];
