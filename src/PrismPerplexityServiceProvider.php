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
        //
        // The third argument asks for that replacement explicitly. Core now
        // refuses to let extend() overwrite a name that is already registered,
        // built-ins included, unless the caller says so — otherwise any package
        // could silently take over a provider and receive its credentials.
        // It is POSITIONAL on purpose: releases of core before that check have
        // a two-parameter extend(), and PHP ignores an extra positional
        // argument to a user method, whereas a named `replace:` would throw
        // "Unknown named parameter" there. One call works on both.
        $this->app->make(PrismManager::class)->extend(
            'perplexity',
            fn ($app, array $config): Perplexity => new Perplexity(
                apiKey: (string) ($config['api_key'] ?? ''),
                url: (string) ($config['url'] ?? 'https://api.perplexity.ai'),
            ),
            true,
        );
    }
}
