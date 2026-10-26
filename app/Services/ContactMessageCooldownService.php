<?php

namespace App\Services;

use App\Models\FraudGuardSetting;
use App\Models\SupportTicket;

/**
 * Server-side contact-form cooldown. It deliberately reuses the Fraud Guard
 * repeat-order interval, but is always on so a disabled checkout module never
 * turns the public contact form into an abuse endpoint.
 */
class ContactMessageCooldownService
{
    /** Returns the seconds remaining, or zero when another message is allowed. */
    public function retryAfter(string $ip, string $email): int
    {
        $baseMinutes = max(1, (int) (FraudGuardSetting::query()->value('repeat_order_minutes') ?? 5));
        $tickets = SupportTicket::query()
            ->whereNotNull('contact_ip')
            ->where(function ($query) use ($ip, $email): void {
                $query->where('contact_ip', $ip)->orWhere('guest_email', $email);
            })
            ->latest('created_at')
            ->get(['contact_ip', 'guest_email', 'created_at']);

        $waits = [];
        foreach ([
            $tickets->filter(fn (SupportTicket $ticket) => $ticket->contact_ip === $ip)->values(),
            $tickets->filter(fn (SupportTicket $ticket) => strtolower((string) $ticket->guest_email) === $email)->values(),
        ] as $events) {
            if ($events->isEmpty()) continue;
            $run = 1;
            for ($index = 1; $index < $events->count(); $index++) {
                if ($events[$index - 1]->created_at->diffInHours($events[$index]->created_at) >= 24) break;
                $run++;
            }
            $waits[] = $events->first()->created_at->copy()->addMinutes($baseMinutes * (2 ** ($run - 1)));
        }

        $availableAt = collect($waits)->filter(fn ($time) => $time->isFuture())->max();
        return $availableAt ? max(1, now()->diffInSeconds($availableAt, false)) : 0;
    }
}
