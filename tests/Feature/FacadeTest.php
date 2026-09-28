<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Facades\Messages;

it('documents its root, is fakeable and reaches every action', function (): void {
    expect(Messages::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});
