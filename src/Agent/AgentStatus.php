<?php

declare(strict_types=1);

namespace Prism\Perplexity\Agent;

enum AgentStatus: string
{
    case Queued = 'queued';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case Incomplete = 'incomplete';
    /** Cancel was accepted but hasn't finished, so this isn't terminal; keep polling for Cancelled. */
    case Cancelling = 'cancelling';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Incomplete, self::Cancelled], true);
    }
}
