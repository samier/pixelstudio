<?php

use Illuminate\Support\Facades\Schedule;

// Fallback for missed webhooks.
Schedule::command('pixelforge:sync')->everyMinute()->withoutOverlapping();
