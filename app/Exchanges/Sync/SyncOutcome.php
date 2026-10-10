<?php

namespace App\Exchanges\Sync;

/**
 * How a sync of one exchange went.
 */
enum SyncOutcome
{
    /** Every step ran, or was skipped because the driver cannot do it. */
    case Synced;

    /** A step failed. The reason is in the exchange's `last_error`. */
    case Failed;

    /** Another sync of the same exchange was running, so this one did nothing. */
    case Busy;
}
