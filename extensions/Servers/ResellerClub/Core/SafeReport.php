<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

/**
 * report() writes to the log, and a log that cannot be written (e.g. a daily file owned by another user inside the
 * container) throws again from inside our catch blocks. Anything that promises "never throws" must report through this.
 */
class SafeReport
{
    public static function report(\Throwable $e): void
    {
        try {
            report($e);
        } catch (\Throwable) {
            // nothing else to do: the failure to log must not become a second failure
        }
    }
}
