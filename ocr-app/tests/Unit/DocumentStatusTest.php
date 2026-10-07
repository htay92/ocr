<?php

use App\Models\Document;

// Spec 2.4 state diagram.
it('allows the transitions in the spec state diagram', function (string $from, string $to) {
    $document = Document::factory()->status($from)->create();

    $document->transitionTo($to);

    expect($document->fresh()->status)->toBe($to);
})->with([
    ['pending', 'processing'],
    ['processing', 'extracted'],
    ['processing', 'needs_review'],
    ['processing', 'failed'],
    ['failed', 'pending'],
    ['extracted', 'confirmed'],
    ['needs_review', 'confirmed'],
    'a job that dies before it starts' => ['pending', 'failed'],
]);

it('refuses transitions the spec does not allow', function (string $from, string $to) {
    $document = Document::factory()->status($from)->create();

    expect(fn () => $document->transitionTo($to))->toThrow(LogicException::class);
    expect($document->fresh()->status)->toBe($from);
})->with([
    ['pending', 'extracted'],
    ['extracted', 'processing'],
    ['confirmed', 'pending'],
    ['needs_review', 'failed'],
]);

it('stamps confirmed_at when a document is confirmed', function () {
    $document = Document::factory()->status('extracted')->create();

    $document->transitionTo(Document::CONFIRMED);

    expect($document->fresh()->confirmed_at)->not->toBeNull();
});
