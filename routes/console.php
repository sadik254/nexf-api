<?php

use App\Services\SupportInboxService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('support:fetch-replies', function (SupportInboxService $inbox): void {
    $count = $inbox->fetchUnread();
    $this->info("Processed {$count} guest support reply(s).");
})->purpose('Fetch verified guest support replies from the support IMAP inbox');

Schedule::command('orders:expire-unpaid-manual')->everyMinute();
Schedule::command('support:fetch-replies')->everyMinute()->withoutOverlapping();
