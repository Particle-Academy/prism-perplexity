<?php

declare(strict_types=1);

namespace Prism\Perplexity;

use Illuminate\Support\ServiceProvider;
use Prism\Prism\PrismManager;

class PrismPerplexityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Registered under the SAME key Prism core uses, so installing this
        // package upgrades `using('perplexity', …)` in place. A custom creator
        // wins over the built-in one in PrismManager::resolve(), and because
        // this provider extends the core class, nothing that worked before
        // stops working — text, structured and streaming are inherited
        // untouched and embeddings and search are added.
        $this->app->make(PrismManager::class)->extend(
            'perplexity',
            fn ($app, array $config): Perplexity => new Perplexity(
                apiKey: (string) ($config['api_key'] ?? ''),
                url: (string) ($config['url'] ?? 'https://api.perplexity.ai'),
            ),
        );
    }
}
