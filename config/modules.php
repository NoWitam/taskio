<?php

/**
 * Konfiguracja dostępnych modułów aplikacji
 * 
 * Ta konfiguracja definiuje jakie moduły są dostępne w aplikacji
 * W przyszłości można dodać uprawnienia wymagane do dostępu do każdego modułu
 */

return [
    'available' => [
        'dashboard',
        'tasks',
        'forms',
        'approvals',
        'example',
        // 'settings',
    ],

    'modules' => [
        'dashboard' => [
            'name' => 'Dashboard',
            'icon' => 'home',
            'permissions' => [], // Brak specjalnych uprawnień na razie
        ],
        'tasks' => [
            'name' => 'Tasks',
            'icon' => 'check-circle',
            'permissions' => ['view_tasks', 'create_tasks'],
        ],
        'forms' => [
            'name' => 'Forms',
            'icon' => 'file-text',
            'permissions' => [],
        ],
        'approvals' => [
            'name' => 'Approvals',
            'icon' => 'badge-check',
            'permissions' => [],
        ],
        'example' => [
            'name' => 'Dashboard',
            'icon' => 'home',
            'permissions' => [], // Brak specjalnych uprawnień na razie
        ],
        // 'users' => [
        //     'name' => 'Users',
        //     'description' => 'User management',
        //     'icon' => 'users',
        //     'permissions' => ['view_users', 'manage_users'],
        // ],
    ],
];
