<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// ADR-0004: payments always replies within 5 minutes; this ends payments Stripe left unfinished.
Schedule::command('payments:expire-stale')->everyMinute()->withoutOverlapping();
