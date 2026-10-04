<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/**
 * Every language ships the same keys and the same placeholders, and the provider really
 * loads the Slovak lines — a missing `sk` file would otherwise fall back to English silently.
 */
dataset('translation files', ['messages']);

it('ships the same keys in every language', function (string $file): void {
    $en = Arr::dot(require __DIR__.'/../../resources/lang/en/'.$file.'.php');
    $sk = Arr::dot(require __DIR__.'/../../resources/lang/sk/'.$file.'.php');

    expect($en)->not->toBeEmpty()
        ->and(array_keys($sk))->toBe(array_keys($en));
})->with('translation files');

it('keeps every placeholder in every language', function (string $file): void {
    $en = Arr::dot(require __DIR__.'/../../resources/lang/en/'.$file.'.php');
    $sk = Arr::dot(require __DIR__.'/../../resources/lang/sk/'.$file.'.php');

    $placeholders = static function (string $line): array {
        preg_match_all('/:([a-z_]+)/i', $line, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    };

    foreach ($en as $key => $line) {
        expect($placeholders((string) ($sk[$key] ?? '')))
            ->toBe($placeholders((string) $line), "Placeholders differ for [{$file}.{$key}].");
    }
})->with('translation files');

it('loads slovak through the service provider', function (): void {
    app()->setLocale('sk');

    expect(trans('messages::messages.preview.deleted'))->toBe('Táto správa bola odstránená.');

    app()->setLocale('en');

    expect(trans('messages::messages.preview.deleted'))->toBe('This message was deleted.');
});
