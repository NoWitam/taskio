<?php

return [

    // Sesje generowania (R2 pod-etap 2b) — jedyne tłumaczone komunikaty serwera: odpowiedzi na konflikt
    // stanu + zapasowy komunikat błędu części. Pozostała treść odpowiedzi jest strukturalna (renderuje FE).
    'sessions' => [
        'already_generating' => 'Ta sesja już generuje treść. Poczekaj na zakończenie.',
        'not_editable' => 'Nie można edytować sesji w trakcie generowania.',
        'template_not_found' => 'Nie znaleziono wybranego szablonu.',
        'template_forbidden' => 'Nie masz dostępu do wybranego szablonu.',
        'part_failed' => 'Nie udało się wygenerować tej części. Sprawdź jej odwołania i spróbuj ponownie.',
        // Łańcuch obrazu (R2 pod-etap 2c) — tłumaczone, NIE-tajne błędy części (nigdy prompt/baza).
        'image_failed' => 'Nie udało się wygenerować tego obrazu. Sprawdź jego bazę i filtry i spróbuj ponownie.',
        'image_base_unavailable' => 'Nie znaleziono źródła obrazu. Sprawdź plik bazowy lub slot i spróbuj ponownie.',
        'image_budget' => 'Ten obraz ma zbyt wiele edycji AI, aby wygenerować go w jednym przebiegu. Zmniejsz liczbę edycji AI i spróbuj ponownie.',
        'image_generate_budget' => 'Ta receptura generuje zbyt wiele obrazów AI w jednym przebiegu. Zmniejsz liczbę obrazów generowanych przez AI i spróbuj ponownie.',
        'ai_generate_unsupported' => 'Generowanie obrazu z opisu tekstowego nie jest jeszcze dostępne.',
        // Dostawca wyrenderował obraz i odmówił jego wydania (własna moderacja treści). Osobny komunikat, bo
        // to jedyny błąd obrazu, który UŻYTKOWNIK może naprawić — odesłanie go do „bazy i filtrów" byłoby
        // mylące: poprawką jest opis postaci lub jej strój.
        'image_safety' => 'AI odmówiło wydania tego obrazu ze względu na zasady dotyczące treści. Zmień opis postaci lub jej strój i spróbuj ponownie.',
        // Kadr storyboardu, którego renderowanie zostało przerwane (padł worker) i który odzyskał reaper.
        // NIE-tajny i konkretny: pozostałe kadry są całe, więc naprawą jest wygenerowanie tego jednego od nowa.
        'frame_lost' => 'Nie udało się dokończyć tego kadru. Wygeneruj go ponownie, aby spróbować jeszcze raz.',
        'saved_image_name' => 'Wygenerowany obraz',
        'deleted' => 'Sesja została usunięta.',
        // Pętla dopracowania (R2 pod-etap 2d) — tłumaczone, NIE-tajne komunikaty konfliktu / możliwości.
        'nothing_to_undo' => 'Nie ma czego cofnąć w tej części.',
        'refine_not_supported' => 'Tej części nie można dopracować instrukcją.',
        'refine_no_image' => 'Nie ma jeszcze wygenerowanego obrazu do dopracowania. Najpierw go wygeneruj.',
        // Bramka budżetu AI przed przebiegiem (R2 pod-etap 4) — odmowa przy przekroczeniu limitu (HTTP 429). NIE-tajne.
        'ai_budget_exceeded' => 'Ten obszar roboczy osiągnął miesięczny budżet AI. Zwiększ limit lub poczekaj do następnego miesiąca, aby generować dalej.',
    ],

];
