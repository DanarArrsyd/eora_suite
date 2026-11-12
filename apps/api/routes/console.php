<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// D-092: exported files are deleted 24 hours after they were written (run by the `scheduler` service)
Schedule::command('eora:exports:expire')->hourly()->withoutOverlapping();

// BR-INV-11: the balance projection is checked against the ledger every night (run by the `scheduler` service)
Schedule::command('eora:inventory:verify-balances')->dailyAt('02:30')->withoutOverlapping();
