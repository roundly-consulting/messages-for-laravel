<?php

declare(strict_types=1);

return [
    'participation' => [
        'interface-required' => 'Trieda :class neimplementuje rozhranie :interface.',
        'not-a-participant' => '[:participant] nie je účastníkom tejto konverzácie.',
        'ownership-by-transfer' => 'Vlastníctvo je možné odovzdať iba cez transferOwnership().',
        'owner-must-transfer' => 'Vlastník musí pred opustením konverzácie odovzdať vlastníctvo cez transferOwnership().',
        'not-the-owner' => '[:participant] nie je vlastníkom tejto konverzácie, preto nemôže vlastníctvo odovzdať.',
        'direct-has-no-roles' => 'Priama konverzácia nemá žiadne roly, ktoré by bolo možné zmeniť.',
        'participant-missing' => 'Model účastníka [:participant] už neexistuje.',
    ],

    'permissions' => [
        'unauthorized' => '[:actor] nemá oprávnenie :action.',
        'requires-role' => '[:actor] potrebuje rolu „:role“, ak chce :action.',

        // Dopĺňajú :action vyššie.
        'actions' => [
            'join-thread' => 'pripojiť sa k tejto konverzácii',
            'send-messages' => 'posielať správy do tejto konverzácie',
            'add-participants' => 'pridávať účastníkov',
            'remove-participants' => 'odoberať účastníkov',
            'remove-participant' => 'odobrať tohto účastníka',
            'change-roles' => 'meniť roly účastníkov',
            'transfer-ownership' => 'odovzdať vlastníctvo',
            'rename-thread' => 'premenovať konverzáciu',
            'archive-thread' => 'archivovať konverzáciu',
            'delete-message' => 'odstrániť túto správu',
            'edit-message' => 'upraviť túto správu',
        ],

        // Dopĺňajú :role vyššie.
        'roles' => [
            'owner' => 'vlastník',
            'admin' => 'správca',
            'member' => 'člen',
        ],
    ],

    'scope' => [
        'message-in-another-thread' => 'Správa [:message] patrí do inej konverzácie.',
        'participant-in-another-thread' => 'Účastník [:participant] patrí do inej konverzácie.',
    ],

    'reply' => [
        'cross-thread' => 'Odpoveď musí smerovať na správu v tej istej konverzácii.',
    ],

    'preview' => [
        'deleted' => 'Táto správa bola odstránená.',
    ],

    'system' => [
        'participant_joined' => 'Účastník :participant sa pripojil ku konverzácii.',
        'participant_left' => 'Účastník :participant opustil konverzáciu.',
        'thread_renamed' => 'Konverzácia bola premenovaná na :name.',
        // Dopĺňa :participant, keď účastník nemá meno („Účastník bez mena sa pripojil…“).
        'unnamed_participant' => 'bez mena',
    ],
];
