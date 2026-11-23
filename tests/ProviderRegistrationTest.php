<?php

declare(strict_types=1);

use Prism\Perplexity\Perplexity;
use Prism\Perplexity\PrismPerplexityServiceProvider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Perplexity\Perplexity as CorePerplexity;

/**
 * Whether the loaded core refuses an extend() over an already registered name
 * unless the caller asks to replace it (particle-academy/prism#163).
 */
function coreRefusesImplicitReplace(): bool
{
    $parameters = (new ReflectionMethod(PrismManager::class, 'extend'))->getParameters();

    return isset($parameters[2]) && $parameters[2]->getName() === 'replace';
}

it('boots and resolves perplexity to the upgraded provider, not the built-in', function (): void {
    // TestCase registers this package's service provider, so reaching this
    // line at all means boot() did not throw "already registered".
    $provider = app(PrismManager::class)->resolve('perplexity');

    expect($provider)->toBeInstanceOf(Perplexity::class)
        ->and($provider)->toBeInstanceOf(CorePerplexity::class)
        ->and(get_class($provider))->toBe(Perplexity::class);
});

it('hands the upgraded provider to callers that go through the facade', function (): void {
    $request = Prism::text()->using('perplexity', 'sonar');

    expect($request->provider())->toBeInstanceOf(Perplexity::class);
});

it('boots again on a manager where perplexity is already taken', function (): void {
    // Booting twice is the case the core check exists for: the second extend()
    // is a replacement of a name that is now a custom creator, not just a
    // built-in. It must still succeed, and still resolve to this package.
    (new PrismPerplexityServiceProvider(app()))->boot();

    expect(app(PrismManager::class)->resolve('perplexity'))->toBeInstanceOf(Perplexity::class);
});

it('is exercising the core it claims to be', function (): void {
    $manager = new PrismManager(app());

    if (coreRefusesImplicitReplace()) {
        // The check is live: an implicit replacement of the built-in must be
        // refused, which is what makes the explicit argument load-bearing.
        expect(fn () => $manager->extend('perplexity', fn (): CorePerplexity => new CorePerplexity('', '')))
            ->toThrow(InvalidArgumentException::class, 'already registered');

        return;
    }

    // Older core: extend() takes two parameters, and the extra positional
    // argument this package passes must be accepted rather than rejected.
    expect((new ReflectionMethod(PrismManager::class, 'extend'))->getNumberOfParameters())->toBe(2);
    $manager->extend('perplexity', fn (): Perplexity => new Perplexity('', 'https://api.perplexity.ai'), true);
    expect($manager->resolve('perplexity'))->toBeInstanceOf(Perplexity::class);
});
