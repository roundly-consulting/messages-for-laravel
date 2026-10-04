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
        'unauthorized' => '[:actor] nemá oprávnenie vykonať akciu „:action“.',
        'requires-role' => '[:actor] potrebuje na akciu „:action“ rolu :role.',
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
    ],
];
