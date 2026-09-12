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

    // DWA WYNIKI, które callback wkłada w przekierowanie. Kody powodów są niżej, we własnej mapie — z
    // tego samego powodu, dla którego `connection_failures` ma własną: jedna z tych list jest przypięta
    // do stałej, a druga nie.
    'oauth' => [
        'connected' => 'Konto połączone.',
        'failed' => 'Nie udało się połączyć konta.',
    ],

    // DLACZEGO nie udało się połączyć konta — kody, które tłumaczy klient.
    //
    // KLUCZE TO DOKŁADNIE `OAuthCallbackReason::ALL`, w obu językach; pilnuje tego
    // `PublishingConnectionVocabularyTest`. Do B3 były tu cztery, a callback potrafił zgłosić
    // czternaście — więc dziesięć z nich wyświetliłoby się jako własny surowy klucz na ekranie, na
    // który człowiek trafia po nieudanym połączeniu konta. Gorszego momentu na pokazanie identyfikatora
    // technicznego nie ma.
    //
    // Każde zdanie mówi, CO ZROBIĆ. Czytający właśnie wrócił z Google albo Meta z niczym, a „wystąpił
    // błąd" nie mówi mu nic, czego by już nie wiedział. Żadne nie powtarza prozy platformy i żadne nie
    // nazywa ataku — do refusali bezpieczeństwa najczęściej prowadzi skopiowany odnośnik albo wyłączone
    // ciasteczka.
    'oauth_failures' => [
        'unknown_platform' => 'To nie jest serwis, do którego ta aplikacja potrafi podłączyć konto.',
        'missing_code' => 'Platforma odesłała cię z powrotem bez przyznania dostępu. Zacznij łączenie konta jeszcze raz.',
        // Trzy różne odmowy dzielą to zdanie celowo — patrz kontroler. Która z nich zaszła, świadomie
        // nie jest ujawniane temu, kto trzyma ten odnośnik.
        'workspace_unavailable' => 'Tego konta nie da się już połączyć z tym obszarem roboczym. Sprawdź, czy nadal masz do niego dostęp, i spróbuj ponownie.',
        'connection_failed' => 'Coś poszło nie tak przy łączeniu konta i nic nie zostało zapisane. Spróbuj ponownie; jeśli powtórzy się to kolejny raz, do logów będzie musiał zajrzeć administrator.',
        'access_denied' => 'Prośba o uprawnienia została odrzucona, więc nic nie zostało połączone.',

        'oauth_state_malformed' => 'Nie rozpoznajemy tego odnośnika. Zacznij łączenie konta jeszcze raz z poziomu tej aplikacji.',
        // Ten jeden oznacza, że ktoś próbował. Nie mówi o tym nic: użytkownik, który trafił tu przez
        // nieaktualny odnośnik, potrzebuje dokładnie tej samej instrukcji, a nazwanie ataku zaniepokoi
        // niewłaściwą osobę.
        'oauth_state_bad_signature' => 'Nie udało się zweryfikować tego odnośnika. Zacznij łączenie konta jeszcze raz z poziomu tej aplikacji.',
        'oauth_state_expired' => 'Ten odnośnik wygasł. Zacznij łączenie konta jeszcze raz.',
        'oauth_state_already_used' => 'Ten odnośnik został już użyty. Zacznij łączenie konta jeszcze raz.',
        'oauth_state_platform_mismatch' => 'Ten odnośnik dotyczył innego serwisu. Zacznij od nowa przy koncie, które chcesz połączyć.',
        // Łączenie dokończono w innej przeglądarce niż ta, w której się zaczęło. Celowo NIE mówi o
        // „bezpieczeństwie" ani nie nazywa ataku: najczęstszy powód to skopiowany odnośnik albo
        // wyłączone ciasteczka, a lekarstwo jest w obu przypadkach to samo.
        'oauth_browser_mismatch' => 'Łączenie trzeba dokończyć w tej samej przeglądarce, w której się zaczęło. Zacznij łączenie konta jeszcze raz i zostań w tym oknie.',

        // Odmowa punktu tokenowego. W dwóch pierwszych przypadkach lekarstwo należy do użytkownika, w
        // trzecim do administratora — i każde zdanie mówi, do kogo, zamiast proponować ogólne „ponów".
        'token_exchange_failed' => 'Platforma nie przyznała dostępu do tego konta. Spróbuj ponownie i upewnij się, że jesteś tam zalogowany na właściwe konto.',
        'token_response_unusable' => 'Platforma przyznała dostęp w postaci, której ta aplikacja nie potrafi zapisać. Zacznij łączenie konta jeszcze raz i zaakceptuj wszystkie uprawnienia, o które prosi.',
        'account_lookup_failed' => 'Dostęp został przyznany, ale platforma nie powiedziała, jakiego konta dotyczy — więc nie było czego zapisać. Sprawdź, czy to konto ma kanał albo stronę, na której ta aplikacja może publikować, i spróbuj ponownie.',
    ],

    'transitions' => [
        // NAJWAŻNIEJSZE zdanie w tym pliku.
        'reconcile_before_retry' => 'Ta publikacja może już być opublikowana — straciliśmy kontakt, zanim udało się to potwierdzić. Najpierw sprawdź platformę; ponowna publikacja może wystawić ją drugi raz, a opublikowanego posta nie da się stąd wycofać.',
        'blocked_holds' => 'Ta publikacja jest wstrzymana, bo jej połączenie nie działa. Połącz konto ponownie i zaplanuj ją jeszcze raz — publikowanie teraz zakończy się błędem dla każdej pozycji czekającej na to połączenie.',
        'terminal' => 'To zostało już opublikowane. Nie da się tego stąd zmienić.',
        'not_allowed' => 'Publikacja nie może przejść z „:from" do „:to".',
        // Z samym przejściem nie ma nic złego — to ekran jest nieaktualny. Zdanie mówi, GDZIE ta
        // publikacja jest teraz, bo tylko to jest potrzebne, żeby zdecydować jeszcze raz.
        'lost_race' => 'Coś zajęło się już tą publikacją — jest teraz w stanie „:from". Nic nie zostało zmienione. Odśwież, żeby zobaczyć, na czym stoi.',
    ],

    // ─────────────────────────────────────────────────────────────────────────────────────────────
    // KARTA AKCEPTACJI (B6)
    // ─────────────────────────────────────────────────────────────────────────────────────────────
    // To, co akceptujący czyta o publikacji, zanim zdecyduje, oraz jedyna odmowa, jaką wystawia trwająca
    // akceptacja. Każda etykieta nazywa coś, czego zmiana zmieniłaby to, co zobaczy świat — nie ma tu nic
    // dekoracyjnego.
    //
    // `under_review` CELOWO nie leży w `transitions` powyżej. Tamten katalog jest przypięty klucz w klucz
    // do słownika `PublicationTransitionRefused` (patrz PublishingConnectionVocabularyTest), a wstrzymanie
    // przez akceptację nie jest przejściem, którego odmówiła maszyna — to wiersz, o którym ktoś decyduje.
    'approval' => [
        // NIE „nie masz uprawnień" — tę samą odpowiedź dostaje każdy, łącznie z autorem, i nikt z nich nie
        // ma problemu z uprawnieniami. Zdanie mówi, u kogo to jest i co kończy czekanie.
        'under_review' => 'Ta publikacja jest u osoby akceptującej, więc nie można jej teraz zmienić ani zaplanować. Po zakończeniu akceptacji znów będzie edytowalna — a jeśli miała publikować się automatycznie, to właśnie akceptacja ją zaplanuje.',
        'fields' => [
            'platform' => 'Miejsce publikacji',
            'account' => 'Konto',
            'planned_for' => 'Zaplanowano na',
            'media' => 'Załączone media',
        ],
        // Miejsce próbne nie ma konta — tak samo jak publikacja, której połączenie zostało usunięte na
        // stałe. Oba czyta się jako wypowiedziany brak, a nie jako pustą komórkę do interpretacji.
        'no_account' => 'Bez konta (nic nie jest publikowane)',
        // Zaakceptowanie posta na piątek i zaakceptowanie takiego, który pójdzie „gdy tylko powiesz tak",
        // to dwie różne decyzje — więc ta druga jest powiedziana wprost, a nie zostawiona jako pustka.
        'no_moment' => 'Natychmiast po akceptacji',
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

        // ── B3, słownik samej kolejki ─────────────────────────────────────────────────────────────
        // Nie udało się zapisać zadania do kolejki, więc nic nigdzie nie poszło. To jest `failed`, a nie
        // `needs_reconcile`, i zdanie mówi to wprost: nie ma czego sprawdzać.
        'dispatch_failed' => 'Nie udało się przekazać tego do wykonania, więc nic nigdzie nie zostało wysłane. Zaplanuj to jeszcze raz.',
        // Proces obsługujący publikację zginął, trzymając claim. Mówi, CO ZROBIĆ, i nie proponuje
        // ponowienia — stąd jedynym uczciwym krokiem jest sprawdzić.
        'publish_worker_failed' => 'Proces obsługujący tę publikację zakończył się, zanim zdążył powiedzieć, co się stało. Sprawdź platformę, zanim zaplanujesz ją ponownie.',
        // Nikt po nią nie wrócił. Ta sama instrukcja, z powodem, na który da się zareagować.
        'reaper_stale' => 'Ta publikacja została wzięta do publikacji i nic nie wróciło. Sprawdź platformę, zanim zaplanujesz ją ponownie — może już być opublikowana.',

        // ── PODŁOGA (D4) ──────────────────────────────────────────────────────────────────────────
        // WIERNA KOPIA zdania, które klient ma u siebie od B3 (`failures.unknown` w pl.ts), a nie nowe
        // zdanie o porażce: serwer potrzebował go dopiero teraz, bo e-mail o nieudanej publikacji jest
        // pierwszą serwerową prozą renderującą kod awarii. B4 dołoży kody adapterów, których ta wersja
        // nie zna, a surowy klucz w skrzynce pocztowej to najgorszy możliwy moment na identyfikator
        // techniczny. Kod jest NAZWANY, żeby rozmowa ze wsparciem miała się o co zaczepić.
        'unknown' => 'Publikacja zatrzymała się z powodem, którego ta wersja aplikacji jeszcze nie opisuje (:code).',
    ],

    // ─────────────────────────────────────────────────────────────────────────────────────────────
    // WIADOMOŚĆ E-MAIL O NIEUDANEJ PUBLIKACJI (D4)
    // ─────────────────────────────────────────────────────────────────────────────────────────────
    // Trzeci operacyjny e-mail produktu, po zaproszeniu i resecie hasła. Leży tutaj, obok katalogu
    // `failures`, bo treść listu SKŁADA SIĘ z tamtych zdań — sam nie opisuje żadnej porażki.
    //
    // „Nic nie zostało wysłane" wolno powiedzieć TYLKO dlatego, że ten list wychodzi wyłącznie dla
    // statusu `failed`, którego kontrakt to „platformę zapytano i dowiedliśmy, że nic nie powstało".
    // Dla `needs_reconcile` to zdanie byłoby nieprawdą — i właśnie dlatego tamten status nie jest
    // konkluzją i nie wysyła listu.
    'mail' => [
        'failed' => [
            'subject' => 'Publikacja nie została opublikowana: :title',
            'greeting' => 'Cześć :name,',
            'intro' => 'Publikacja „:title" miała trafić na :platform i nie została opublikowana. Nic nie zostało wysłane.',
            'planned' => 'Planowany termin: :moment (:timezone).',
            'action' => 'Otwórz publikację',
            'fallback' => 'Albo wklej ten link do przeglądarki:',
            'footer' => 'Ten list wysyła Taskio za każdym razem, gdy publikacja zakończy się niepowodzeniem.',
            // Zastępnik PUSTEGO tytułu w temacie i treści (ścieżka workflow celowo przepuszcza taki do
            // `title_missing`) — bez niego temat kończy się „: ", a intro cytuje pusty string.
            'untitled' => 'Publikacja bez tytułu',
        ],
    ],

];
