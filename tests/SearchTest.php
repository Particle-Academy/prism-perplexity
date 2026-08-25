<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Perplexity\Perplexity;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\PrismManager;

function perplexity(): Perplexity
{
    /** @var Perplexity */
    return app(PrismManager::class)->resolve('perplexity');
}

it('returns web results with no model in the loop', function (): void {
    Http::fake(['api.perplexity.ai/*' => Http::response([
        'results' => [
            ['title' => 'Prism', 'url' => 'https://example.com/a', 'snippet' => 'An AI backbone.'],
            ['title' => 'Prism docs', 'url' => 'https://example.com/b', 'snippet' => 'Docs.'],
        ],
    ])]);

    $results = perplexity()->search('what is prism php');

    expect($results)->toHaveCount(2)
        ->and($results[0]['url'])->toBe('https://example.com/a')
        ->and($results[1]['title'])->toBe('Prism docs');

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/search')
        && $request->data()['query'] === 'what is prism php');
});

it('accepts several queries at once', function (): void {
    Http::fake(['api.perplexity.ai/*' => Http::response(['results' => []])]);

    perplexity()->search(['first', 'second']);

    Http::assertSent(fn ($request): bool => $request->data()['query'] === ['first', 'second']);
});

it('passes options through', function (): void {
    Http::fake(['api.perplexity.ai/*' => Http::response(['results' => []])]);

    perplexity()->search('x', ['max_results' => 3, 'search_domain_filter' => ['example.com']]);

    Http::assertSent(fn ($request): bool => $request->data()['max_results'] === 3
        && $request->data()['search_domain_filter'] === ['example.com']);
});

it('treats no results as an answer, not a failure', function (): void {
    // Same rule the Agent API sets for a completed run with no sources: an
    // empty list means nothing matched, which is information.
    Http::fake(['api.perplexity.ai/*' => Http::response(['results' => []])]);

    expect(perplexity()->search('nothing matches this'))->toBe([]);
});

it('fails loudly on an error body', function (): void {
    Http::fake(['api.perplexity.ai/*' => Http::response([
        'error' => ['type' => 'rate_limited', 'message' => 'Slow down.'],
    ])]);

    expect(fn (): array => perplexity()->search('x'))
        ->toThrow(PrismException::class, 'rate_limited');
});

it('is the extended provider, so text still works through core', function (): void {
    // The package registers under the same key as Prism core. If that ever
    // stopped extending and started replacing, inherited capabilities would
    // vanish silently — so assert the inheritance directly.
    expect(perplexity())->toBeInstanceOf(Perplexity::class)
        ->and(perplexity())->toBeInstanceOf(Prism\Prism\Providers\Perplexity\Perplexity::class);
});
