<?php

declare(strict_types=1);

namespace Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Prism\Perplexity\PrismPerplexityServiceProvider;
use Prism\Prism\PrismServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PrismServiceProvider::class,
            PrismPerplexityServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('prism.providers.perplexity', [
            'api_key' => 'pplx-test',
            'url' => 'https://api.perplexity.ai',
        ]);
    }
}
