# AGENTS.md — particle-academy/prism-perplexity

The rest of the Perplexity API for Prism: embeddings, direct web search, and the
endpoints that do **not** fit Prism's provider abstraction.

> **Read [the shared guide](https://github.com/Particle-Academy/prism-parity/blob/main/docs/AGENTS.md)
> first** — the boundary, the gates, the binding decisions, the review skills.
> This file is only what is true of *this* repository.

## The membership test

There is a Perplexity provider **in core**. This package is not it, and the line
between them is the only design question here.

**If a Perplexity endpoint fits the provider abstraction, it belongs in core's
provider.** Text, structured, embeddings-as-a-capability — those are shapes
every provider has, and moving them here would fragment the abstraction for one
vendor.

**This package is for what does not fit**: direct search, async deep research —
endpoints with no analogue across the other seventeen providers, which would
therefore distort the contract if core had to express them.

When a new Perplexity endpoint appears, ask which side it lands on before
writing anything. Adding a well-fitting capability here is as much a boundary
failure as adding an ill-fitting one to core; it just fails quietly instead of
loudly.

## The incident this ecosystem learned from happened on this vendor

Perplexity's `withTools()` once **returned successfully while doing nothing** —
the run completed, the model appeared to simply decline to use any tool, and the
truth was that the capability had been silently dropped. It cost days, because a
successful run that did less is indistinguishable from a model exercising
judgement.

That is the origin of the ecosystem's hardest rule: **unsupported means throw.**
See
[0011](https://github.com/Particle-Academy/prism-parity/blob/main/docs/decisions/0011-when-silence-is-allowed.md).

It also means this package carries a specific obligation. Perplexity's API moves
fast and ships capabilities unevenly. Anything here that *might* be unsupported
on a given account, plan, or model must raise rather than return an empty
success — including when the vendor itself answers `200` with an empty result.
A vendor's ambiguity is not licence to pass ambiguity to the caller.

## Async deep research

Deep research is long-running and asynchronous, which is a shape most of Prism
does not have. The failure modes to keep guarded are unbounded polling, missing
timeouts, and a terminal state that never arrives. A poll loop with no ceiling
is a cost incident with no error message.

## Gates

```sh
composer test && composer types && composer format
```

CI runs `tests`, `phpstan`, `formatting`, `require-checker`.

Live-provider work belongs in `prism-labs`, which holds real keys and is never
deployed. No live-provider job belongs in this repository's CI.
