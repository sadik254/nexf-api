<?php

use App\Services\SupportInboxService;
use App\Http\Controllers\MediaAssetController;
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

Artisan::command('media:purge-trash', function (MediaAssetController $media): void {
    $count = $media->purgeExpired();
    $this->info("Purged {$count} expired media file(s).");
})->purpose('Permanently purge media files that have been in trash for 30 days');

Schedule::command('media:purge-trash')->daily()->withoutOverlapping();
