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
    public function agent(?Closure $sleeper = null): AgentClient
    {
        return new AgentClient($this->client(), $sleeper);
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
     * @param  string|list<string>  $query
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    public function search(string|array $query, array $options = []): array
    {
        return (new Search($this->client()))->handle($query, $options);
    }
}
