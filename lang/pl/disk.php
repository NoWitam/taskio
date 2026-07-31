<?php

return [
    'module_name' => 'Dysk',

    // Wirtualne, read-only drzewo „Zasoby" nad plikami należącymi do innych zasobów.
    'resources' => [
        'root' => 'Zasoby',
        'task' => 'Załączniki zadań',
        'form_report' => 'Raporty',
        'form_submission' => 'Załączniki formularzy',
    ],

    'ai' => [
        'failed' => 'Edycja przez AI nie powiodła się. Spróbuj ponownie.',
        'budget' => 'Osiągnięto dzienny limit edycji AI. Spróbuj ponownie jutro.',
        // Dostawca wyrenderował obraz i odmówił jego wydania (własna moderacja). Mówimy, co się
        // naprawdę stało i co użytkownik może zrobić — ponowna próba niczego nie zmieni.
        'safety' => 'Dostawca odrzucił tę treść ze względów bezpieczeństwa. Zmień opis lub strój i spróbuj ponownie.',
    ],

    'drafts' => [
        'too_large' => 'Ten szkic jest zbyt duży, aby go automatycznie zapisać.',
    ],

    'validation' => [
        'folder_not_empty' => 'Ten folder nie jest pusty. Najpierw przenieś lub usuń jego zawartość.',
        'folder_name_taken' => 'Folder o tej nazwie już tutaj istnieje.',
        'folder_move_into_self' => 'Nie można przenieść folderu do niego samego.',
        'folder_move_into_descendant' => 'Nie można przenieść folderu do jego własnego podfolderu.',
        'folder_too_deep' => 'Foldery nie mogą być zagnieżdżone głębiej niż :max poziomów.',
        'file_owned_by_resource' => 'Ten plik należy do innego elementu; zarządzaj nim w tamtym miejscu.',
        'restore_target_required' => [
            'detached' => 'Ten plik był załącznikiem innego elementu, więc nie wróci tam z powrotem. Wskaż folder, do którego ma zostać przywrócony.',
            'folder_missing' => 'Jego pierwotny folder już nie istnieje. Wskaż folder, do którego ma zostać przywrócony.',
            'folder_trashed' => 'Jego pierwotny folder jest w koszu. Wskaż folder, do którego ma zostać przywrócony.',
        ],
    ],
];
