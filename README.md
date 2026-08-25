# Prism Perplexity

The rest of the Perplexity API for [Prism](https://github.com/Particle-Academy/prism) —
embeddings, direct web search, and the endpoints that do not fit Prism's provider abstraction.

```bash
composer require particle-academy/prism-perplexity
```

That is the whole setup. The package registers under the **same provider key** as Prism core,
so `using('perplexity', …)` gains capabilities and nothing that already worked changes.

## Embeddings

Prism core throws `unsupportedProviderAction` for Perplexity embeddings. With this installed:

```php
$response = Prism::embeddings()
    ->using('perplexity', 'text-embedding-v1')
    ->fromInput('The quick brown fox')
    ->asEmbeddings();

$response->embeddings[0]->embedding;  // float[]
```

### Contextualized embeddings

Perplexity has a second endpoint for chunks of **one document**, embedding each in light of the
others — which is what you want when you have split a document for retrieval and do not want
every chunk to read as though it arrived alone.

Prism's embeddings abstraction is text-in/vector-out and has no concept of "these belong
together", so it is reached explicitly rather than inferred:

```php
Prism::embeddings()
    ->using('perplexity', 'text-embedding-v1')
    ->fromArray($chunks)
    ->withProviderOptions(['contextualized' => true])
    ->asEmbeddings();
```

Vectors are ordered by the `index` the API returns, not by array position — lining vectors up
with the inputs that produced them by position would be a silent mismatch if a response ever
arrived out of order.

## Search

Web results with **no model in the loop** — the sources a grounded answer would have been built
from, without paying for the answer.

```php
$provider = app(PrismManager::class)->resolve('perplexity');

$results = $provider->search('what is prism php', [
    'max_results' => 5,
    'search_domain_filter' => ['github.com'],
]);
```

This is not a `Prism::` verb because Prism has no "search" concept — every other provider it
speaks to answers with a model. Bending a list of web results into a completion would lose the
structure that makes it worth having, so it is its own method.

An empty result list is an **answer**, not a failure.

## What lives here and what lives in core

| | Where |
|---|---|
| Text, structured output, streaming | Prism core |
| Slug→preset translation, Agent API transport | Prism core |
| Embeddings, contextualized embeddings | here |
| Search API | here |
| Async deep research | planned |

The split follows the abstraction: core carries what fits "prompt in, answer out". Anything
that is a genuinely different operation lives out here rather than distorting core to fit.

## Errors

Perplexity's agent surface returns failures inside an HTTP 200. This package assumes the same
is possible on every endpoint and branches on the response body, because an empty vector set or
an empty result list read as a valid answer is worse than an exception.

## Requirements

PHP 8.2+, Laravel 12.61+ or 13.12+, and `particle-academy/prism` 0.113+.
