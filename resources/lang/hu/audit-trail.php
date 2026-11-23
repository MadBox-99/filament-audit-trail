<?php

declare(strict_types=1);

return [
    'events' => [
        'login' => 'Belépés',
        'login_inferred' => 'Belépés (levezetett)',
        'logout' => 'Kilépés',
        'login_failed' => 'Sikertelen belépés',
        'page_view' => 'Oldalmegtekintés',
        'action_mounted' => 'Akció megnyitva',
        'action_executed' => 'Akció végrehajtva',
        'created' => 'Létrehozás',
        'updated' => 'Módosítás',
        'deleted' => 'Törlés',
        'restored' => 'Visszaállítás',
        'import' => 'Import',
        'export' => 'Export',
        'account_created' => 'Fiók létrehozva',
    ],
    'sources' => [
        'live' => 'Élő',
        'reconstructed' => 'Helyreállított',
    ],
    'resource' => [
        'navigation_label' => 'Audit napló',
        'model_label' => 'audit esemény',
        'plural_model_label' => 'audit események',
    ],
    'columns' => [
        'occurred_at' => 'Idő',
        'user' => 'Felhasználó',
        'event' => 'Esemény',
        'team' => 'Csapat',
        'subject' => 'Tárgy',
        'subject_type' => 'Tárgy típusa',
        'subject_id' => 'Tárgy azonosítója',
        'action' => 'Akció / útvonal',
        'url' => 'URL',
        'ip' => 'IP',
        'source' => 'Forrás',
        'properties' => 'Tulajdonságok',
        'evidence' => 'Bizonyíték',
    ],
    'inferred' => 'levezetett időpont',
    'filters' => [
        'date_range' => 'Dátum-tartomány',
        'from' => 'Ettől',
        'until' => 'Eddig',
    ],
    'export' => [
        'completed' => 'Az audit napló exportálása elkészült, :count sor.',
    ],
    'export_columns' => [
        'occurred_at' => 'Idő',
        'event' => 'Esemény',
        'user_email' => 'Felhasználó e-mail',
        'team_id' => 'Csapat azonosító',
        'subject_type' => 'Tárgy típusa',
        'subject_id' => 'Tárgy azonosítója',
        'action' => 'Akció',
        'url' => 'URL',
        'ip' => 'IP',
        'source' => 'Forrás',
        'inferred' => 'Levezetett',
        'evidence_key' => 'Bizonyíték kulcs',
    ],
];
