<?php

return [
    'module_name' => 'Zatwierdzenia',

    'entity_types' => [
        'task' => 'Zadanie',
        // R4 B6 — druga encja z akceptacją w produkcie. Nazwana tym, o czym się decyduje, a nie modułem,
        // bo to jest napis, który akceptujący czyta u góry karty.
        'publication' => 'Publikacja',
    ],

    'status' => [
        'pending' => 'Oczekuje',
        'approved' => 'Zatwierdzono',
        'rejected' => 'Odrzucono',
    ],

    'approver_type' => [
        'user' => 'Użytkownik',
        'ai' => 'AI',
    ],

    'actions' => [
        'approve' => 'Zatwierdź',
        'reject' => 'Odrzuć',
        'create_pipeline' => 'Utwórz lejek',
        'edit_pipeline' => 'Edytuj lejek',
        'delete_pipeline' => 'Usuń lejek',
        'add_stage' => 'Dodaj etap',
        'remove_stage' => 'Usuń etap',
    ],

    'labels' => [
        'pipeline' => 'Lejek zatwierdzania',
        'pipelines' => 'Lejki zatwierdzania',
        'stage' => 'Etap',
        'stages' => 'Etapy',
        'queue' => 'Kolejka zatwierdzeń',
        'name' => 'Nazwa',
        'description' => 'Opis',
        'icon' => 'Ikona',
        'approver' => 'Zatwierdzający',
        'criteria' => 'Kryteria oceny',
        'note' => 'Notatka',
        'decision' => 'Decyzja',
        'no_items' => 'Brak elementów do zatwierdzenia',
        'no_pipelines' => 'Brak lejków zatwierdzania',
        'approval_in_progress' => 'Zatwierdzanie w toku',
        'stage_of' => 'Etap :current z :total',
        'rejected_note' => 'Powód odrzucenia',
    ],

    'validation' => [
        'pipeline_has_active_processes' => 'Nie można edytować/usunąć lejka z aktywnymi procesami zatwierdzania.',
        'no_pipeline_assigned' => 'Encja nie ma przypisanego lejka zatwierdzania.',
        'pipeline_has_no_stages' => 'Lejek zatwierdzania nie ma zdefiniowanych etapów.',
        'already_decided' => 'Ten etap został już rozstrzygnięty.',
        // R4 B6 — przedmiot akceptacji został usunięty, gdy był u zatwierdzającego. 422 zamiast
        // TypeError, którym to było wcześniej; patrz ApprovalService::decide().
        'approvable_missing' => 'Przedmiot tej akceptacji już nie istnieje.',
        // R4 B6 — jeden żywy proces na przedmiot; drugi oznaczałby dwóch akceptujących decydujących
        // o tym samym bez wiedzy o sobie. Patrz ApprovalService::startProcess().
        'process_already_pending' => 'Ten element jest już w akceptacji.',
        'note_required_on_rejection' => 'Notatka jest wymagana przy odrzuceniu.',
        'invalid_decision' => 'Nieprawidłowa decyzja.',
        'min_one_stage' => 'Lejek musi mieć co najmniej jeden etap.',
    ],

    'messages' => [
        'approved' => 'Etap został zatwierdzony.',
        'rejected' => 'Etap został odrzucony.',
        'pipeline_created' => 'Lejek zatwierdzania został utworzony.',
        'pipeline_updated' => 'Lejek zatwierdzania został zaktualizowany.',
        'pipeline_deleted' => 'Lejek zatwierdzania został usunięty.',
        'process_started' => 'Proces zatwierdzania został uruchomiony.',
    ],
];
