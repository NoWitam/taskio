<?php

use App\Http\Controllers\UserController;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::put('/user/locale', [UserController::class, 'updateLocale']);
});

Route::get('/test', function () {
    $id = "019d53f8-6a08-715d-80b5-2f948e700ee8";
    $form = Form::find($id);

    // $form->normalizeFieldIds();
    // dump($form->content);

    // dump($form->getJsonSchema());

    $reports = [
        [
            'name' => 'Raport za marzec',
            'guidelines' => null
        ],
        [
            'name' => 'Podsumowanie ogólne formularzy',
            'guidelines' => 'Przygotuj zwięzły raport podsumowujący wyniki wszystkich odpowiedzi.
                Uwzględnij:
                - średnie oceny dla wszystkich pytań ratingowych,
                - najczęściej pojawiające się trudności,
                - główne mocne strony onboardingu,
                - najważniejsze obszary do poprawy,
                - ogólny poziom satysfakcji nowych pracowników.

                Raport ma być rzeczowy, biznesowy i napisany po polsku.
                Na końcu dodaj sekcję "Najważniejsze wnioski" z 3-5 punktami.',
        ],
        [
            'name' => 'Raport problemów i ryzyk',
            'guidelines' => 'Przeanalizuj odpowiedzi pod kątem problemów i ryzyk związanych z onboardingiem.
                Skup się szczególnie na:
                - odpowiedziach z niskimi ocenami (1-2),
                - powtarzających się problemach,
                - treści pól tekstowych sugerujących frustrację, brak wsparcia lub ryzyko odejścia,
                - sekcji sygnałów ryzyka.

                Raport powinien:
                - wskazać najpoważniejsze ryzyka,
                - ocenić ich możliwy wpływ na efektywność i retencję,
                - pogrupować problemy według kategorii,
                - zaproponować działania naprawcze.

                Na końcu dodaj sekcję "Priorytety działań" z podziałem na wysoki, średni i niski priorytet.',
        ],
        [
            'name' => 'Raport porównawczy między działami',
            'guidelines' => 'Porównaj wyniki onboardingu pomiędzy działami firmy.
                Uwzględnij:
                - średnie oceny w każdym dziale,
                - najczęstsze trudności zgłaszane w poszczególnych działach,
                - różnice w poziomie wsparcia managera, zespołu i jasności roli,
                - działy z najlepszym i najsłabszym doświadczeniem onboardingu.

                Raport powinien być podzielony na sekcje dla każdego działu.
                Na końcu wskaż:
                - który dział wypada najlepiej,
                - który wymaga największej poprawy,
                - jakie praktyki warto przenieść między działami.',
        ],
        [
            'name' => 'Raport rekomendacji usprawnień',
            'guidelines' => 'Na podstawie odpowiedzi przygotuj raport rekomendacji usprawniających onboarding.
                Twoim celem jest przełożenie feedbacku na konkretne działania.

                Uwzględnij:
                - najczęściej powtarzające się braki,
                - sugestie pracowników z pól otwartych,
                - problemy związane z dostępami, dokumentacją, wsparciem managera i jasnością roli.

                Dla każdej rekomendacji podaj:
                1. nazwę problemu,
                2. opis problemu,
                3. proponowane rozwiązanie,
                4. spodziewany efekt,
                5. priorytet wdrożenia.

                Raport ma być praktyczny i gotowy do przekazania managerom lub HR.',
        ],
        [
            'name' => 'Raport indywidualny dla jednego zgłoszenia',
            'guidelines' => 'Przygotuj indywidualny raport dla pojedynczego zgłoszenia.
                Raport powinien:
                - podsumować doświadczenie pracownika po 30 dniach,
                - wskazać mocne strony jego onboardingu,
                - zidentyfikować problemy i ryzyka,
                - ocenić poziom gotowości pracownika do samodzielnej pracy,
                - zaproponować konkretne działania follow-up dla managera.

                Raport ma być empatyczny, ale konkretny.
                Na końcu dodaj sekcję "Zalecane następne kroki".',
        ],
        [
            'name' => 'Executive summary dla leadership team',
            'guidelines' => 'Przygotuj executive summary dla leadership team.
                Raport ma być krótki, konkretny i skupiony na decyzjach biznesowych.

                Uwzględnij:
                - ogólną ocenę onboardingu,
                - 3 najważniejsze problemy,
                - 3 najważniejsze mocne strony,
                - wpływ tych wyników na efektywność nowych pracowników i ryzyko rotacji,
                - 3-5 rekomendowanych działań dla firmy.

                Używaj krótkich akapitów i języka biznesowego.
                Nie opisuj każdego przypadku osobno, chyba że jest wyjątkowo krytyczny.',
        ],
    ];

    FormReport::query()->forceDelete();

    foreach($reports as $report) {
        FormReport::create([
            'name' => $report['name'],
            'form_id' => $form->id,
            'guidelines' => $report['guidelines'],
            'sources' => ['form'],
            'submissions_from' => now()->subMonth()->startOfMonth(),
            'submissions_to' => now()->subMonth()->endOfMonth(),
            'creator_id' => request()->user()->id
        ]);
        sleep(2);
    }
});