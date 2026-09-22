<?php

declare(strict_types=1);

namespace Prism\Perplexity;

use Closure;
use Prism\Perplexity\Agent\AgentClient;
use Prism\Perplexity\Handlers\Embeddings;
use Prism\Perplexity\Handlers\Search;
use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Providers\Perplexity\Perplexity as BasePerplexity;

/**
 * Perplexity, with the parts of its API that Prism core does not cover.
 *
 * Extends rather than replaces. Text, structured output and streaming are
 * inherited from Prism's own provider, so installing this package ADDS
 * capabilities to `using('perplexity', …)` instead of forking it — a caller
 * with existing Perplexity code changes nothing and gains embeddings.
 *
 * The split is deliberate. Prism core carries what fits its provider
 * abstraction: prompt in, text or structured output back. Perplexity also
 * offers a plain embeddings endpoint, a search endpoint that returns web
 * results with no model involved, and long-running background research. The
 * middle one has no equivalent concept in Prism at all, which is exactly why
 * it lives out here rather than distorting the core abstraction to fit.
 */
class Perplexity extends BasePerplexity
{
    /**
     * The long-running research endpoint.
     *
     * Client options are threaded through, as every sibling method does. They
     * were not, and on this endpoint that could not be worked around from the
     * caller at all: `client()` fell back to `withOptions([])`, leaving
     * Laravel's 30-second default on the one endpoint whose entire purpose is
     * to run for minutes. Reported as #2, measured at 57-59 seconds for a
     * routine deep-research call.
     *
     * The default timeout is raised for the same reason, and it is a DEFAULT
     * rather than a fixed value -- an explicit `timeout` in $clientOptions
     * always wins. Making it configurable alone would have fixed the instance
     * and left every caller to rediscover the same thirty seconds.
     *
     * 30s is the framework's default for an ordinary request. It is not a
     * sensible one here, and the failure it produced was the least diagnosable
     * shape available: cURL 28 with zero bytes received, which reads like a
     * network hang rather than a ceiling that was always going to fire.
     *
     * @param  array<string, mixed>  $clientOptions
     * @param  array<int, mixed>  $clientRetry
     */
    public function agent(?Closure $sleeper = null, array $clientOptions = [], array $clientRetry = []): AgentClient
    {
        return new AgentClient(
            $this->client(array_replace(['timeout' => 300], $clientOptions), $clientRetry),
            $sleeper,
        );
    }

    #[\Override]
    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return (new Embeddings(
            $this->client($request->clientOptions(), $request->clientRetry())
        ))->handle($request);
    }

    /**
     * Search the web directly, with no model in the loop.
     *
     * Not a Prism capability — Prism has no "search" verb, because every other
     * provider it speaks to answers with a model. Exposed as its own method
     * rather than bent into `text()`, since pretending a list of web results is
     * a completion would lose the structure that makes it worth having.
     *
     * @param  string|list<string>  $query  One query, or several run together.
     * @param  array<string, mixed>  $options  Search parameters, sent to the endpoint.
     * @param  array<string, mixed>  $clientOptions  Transport options: timeout, connect_timeout.
     * @param  array<int, mixed>  $clientRetry
     * @return list<array<string, mixed>>
     */
    public function search(
        string|array $query,
        array $options = [],
        array $clientOptions = [],
        array $clientRetry = [],
    ): array {
        // Threaded for the same reason as agent() above, though far less
        // urgently: search is an ordinary-length request, so the framework
        // default is defensible here. It is still a knob a caller could not
        // reach, and the gap was identical.
        return (new Search($this->client($clientOptions, $clientRetry)))->handle($query, $options);
    }
}
