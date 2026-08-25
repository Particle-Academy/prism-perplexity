<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Embeddings\Response;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;

function fakeEmbeddings(array $body, int $status = 200): void
{
    Http::fake([
        'api.perplexity.ai/*' => Http::response($body, $status),
    ]);
}

it('adds embeddings to a provider that could not do them before', function (): void {
    // Prism core throws unsupportedProviderAction for Perplexity embeddings.
    // Installing this package is meant to make the same call work, without the
    // caller changing anything but their composer file.
    fakeEmbeddings([
        'id' => 'emb_1',
        'model' => 'text-embedding-v1',
        'data' => [
            ['index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
        ],
        'usage' => ['total_tokens' => 7],
    ]);

    $response = Prism::embeddings()
        ->using('perplexity', 'text-embedding-v1')
        ->fromInput('hello')
        ->asEmbeddings();

    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->embeddings)->toHaveCount(1)
        ->and($response->embeddings[0]->embedding)->toBe([0.1, 0.2, 0.3])
        ->and($response->usage->tokens)->toBe(7)
        ->and($response->meta->model)->toBe('text-embedding-v1');
});

it('orders vectors by index rather than trusting array order', function (): void {
    // The API documents an index on each item. Lining vectors up with the
    // inputs that produced them by array position would be a silent mismatch
    // if a response ever arrived out of order.
    fakeEmbeddings([
        'model' => 'text-embedding-v1',
        'data' => [
            ['index' => 2, 'embedding' => [3.0]],
            ['index' => 0, 'embedding' => [1.0]],
            ['index' => 1, 'embedding' => [2.0]],
        ],
        'usage' => ['total_tokens' => 3],
    ]);

    $response = Prism::embeddings()
        ->using('perplexity', 'text-embedding-v1')
        ->fromArray(['a', 'b', 'c'])
        ->asEmbeddings();

    expect(array_map(fn ($e): float => $e->embedding[0], $response->embeddings))
        ->toBe([1.0, 2.0, 3.0]);
});

it('hits the plain endpoint by default', function (): void {
    fakeEmbeddings(['model' => 'm', 'data' => [], 'usage' => ['total_tokens' => 0]]);

    Prism::embeddings()->using('perplexity', 'm')->fromInput('x')->asEmbeddings();

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/embeddings')
        && ! str_contains($request->url(), 'contextualized'));
});

it('hits the contextualized endpoint when asked', function (): void {
    // Chunks of one document embedded in light of each other — a different
    // operation, so it gets a different endpoint rather than a flag that
    // quietly changes what the same call means.
    fakeEmbeddings(['model' => 'm', 'data' => [], 'usage' => ['total_tokens' => 0]]);

    Prism::embeddings()
        ->using('perplexity', 'm')
        ->fromArray(['chunk one', 'chunk two'])
        ->withProviderOptions(['contextualized' => true])
        ->asEmbeddings();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/contextualized-embeddings'));
});

it('fails loudly on an error body even inside a 200', function (): void {
    // Perplexity's agent surface wraps failures in a 200. Assume the same is
    // possible here: an empty vector set read as a valid result is worse than
    // an exception.
    fakeEmbeddings([
        'error' => ['type' => 'invalid_model', 'message' => 'Unknown embedding model.'],
    ]);

    expect(fn (): Response => Prism::embeddings()
        ->using('perplexity', 'nope')
        ->fromInput('x')
        ->asEmbeddings())
        ->toThrow(PrismException::class, 'invalid_model');
});
