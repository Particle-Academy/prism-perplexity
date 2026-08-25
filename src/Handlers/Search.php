<?php

declare(strict_types=1);

namespace Prism\Perplexity\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Exceptions\PrismException;

/**
 * Perplexity's Search API — web results, no model.
 *
 * Returns the sources a grounded answer would have been built from, without
 * paying for the answer. Useful when the application wants to do its own
 * synthesis, or wants to show a user where information came from before
 * spending tokens on summarising it.
 *
 * Results are returned as plain arrays rather than wrapped in a value object,
 * because Perplexity documents this payload as open-ended and a wrapper that
 * named only today's fields would quietly drop tomorrow's.
 */
class Search
{
    public function __construct(protected PendingRequest $client) {}

    /**
     * @param  string|list<string>  $query  One query, or several run together.
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    public function handle(string|array $query, array $options = []): array
    {
        $response = $this->client->post('/search', array_filter(
            array_merge(['query' => $query], $options),
            fn (mixed $value): bool => $value !== null && $value !== [],
        ));

        $data = $response->json();

        if (! is_array($data)) {
            throw PrismException::providerRequestErrorWithDetails(
                provider: 'Perplexity',
                statusCode: $response->status(),
                errorType: 'unreadable_response',
                errorMessage: 'The search endpoint did not return a JSON object.',
            );
        }

        if (data_get($data, 'error') !== null) {
            throw PrismException::providerRequestErrorWithDetails(
                provider: 'Perplexity',
                statusCode: $response->status(),
                errorType: is_string(data_get($data, 'error.type')) ? data_get($data, 'error.type') : 'search_error',
                errorMessage: is_string(data_get($data, 'error.message')) ? data_get($data, 'error.message') : 'Unknown error',
            );
        }

        $results = data_get($data, 'results');

        // No results is a legitimate answer to a search, not a failure — the
        // same rule the Agent API sets for a completed run with no sources.
        return is_array($results) ? array_values($results) : [];
    }
}
