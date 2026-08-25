<?php

declare(strict_types=1);

namespace Prism\Perplexity\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Embeddings\Response;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;

/**
 * Perplexity's embeddings endpoints.
 *
 * Two of them, and the difference matters. `/embeddings` treats each input
 * independently. `/contextualized-embeddings` treats the inputs as chunks of
 * one document and embeds each in light of the others, which is what you want
 * when you have split a document for retrieval and do not want every chunk to
 * read as though it arrived without context.
 *
 * Prism's embeddings abstraction is text-in, vector-out and has no concept of
 * "these belong together", so the second is reached through a provider option
 * rather than pretended to be the same call.
 */
class Embeddings
{
    public function __construct(protected PendingRequest $client) {}

    public function handle(Request $request): Response
    {
        $contextualized = (bool) $request->providerOptions('contextualized');

        $response = $this->client->post(
            $contextualized ? '/contextualized-embeddings' : '/embeddings',
            array_filter([
                'model' => $request->model(),
                'inputs' => $request->inputs(),
                // Only meaningful when the inputs are chunks of one document.
                'document_context' => $request->providerOptions('document_context'),
            ], fn (mixed $value): bool => $value !== null && $value !== []),
        );

        $data = $response->json();

        if (! is_array($data)) {
            throw PrismException::providerRequestErrorWithDetails(
                provider: 'Perplexity',
                statusCode: $response->status(),
                errorType: 'unreadable_response',
                errorMessage: 'The embeddings endpoint did not return a JSON object.',
            );
        }

        $this->assertNoError($data, $response->status());

        return new Response(
            embeddings: $this->extractEmbeddings($data),
            usage: new EmbeddingsUsage(
                tokens: $this->intOrNull(data_get($data, 'usage.total_tokens'))
                    ?? $this->intOrNull(data_get($data, 'usage.prompt_tokens')),
            ),
            meta: new Meta(
                id: (string) (data_get($data, 'id') ?? ''),
                model: (string) (data_get($data, 'model') ?? $request->model()),
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function assertNoError(array $data, int $status): void
    {
        $error = data_get($data, 'error');

        if ($error === null) {
            return;
        }

        // Perplexity's agent surface returns failures inside a 200. Assume the
        // same can happen here rather than trusting the status code, since the
        // cost of being wrong is an empty vector set read as a valid result.
        throw PrismException::providerRequestErrorWithDetails(
            provider: 'Perplexity',
            statusCode: $status,
            errorType: is_string(data_get($error, 'type')) ? data_get($error, 'type') : 'embeddings_error',
            errorMessage: is_string(data_get($error, 'message')) ? data_get($error, 'message') : 'Unknown error',
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<Embedding>
     */
    protected function extractEmbeddings(array $data): array
    {
        $items = data_get($data, 'data');

        if (! is_array($items)) {
            return [];
        }

        // Sorted by index before mapping: the API documents an `index` on each
        // item, and relying on array order to line vectors up with the inputs
        // that produced them would be a silent mismatch if it ever arrived out
        // of order.
        usort($items, fn (mixed $a, mixed $b): int => (int) data_get($a, 'index', 0) <=> (int) data_get($b, 'index', 0));

        $embeddings = [];

        foreach ($items as $item) {
            $vector = data_get($item, 'embedding');

            if (is_array($vector)) {
                $embeddings[] = Embedding::fromArray(array_map('floatval', $vector));
            }
        }

        return $embeddings;
    }

    protected function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
