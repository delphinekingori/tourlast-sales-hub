<?php

namespace App\Console\Commands;

use App\Models\FollowUp;
use App\Support\Alerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hub:send-meeting-reminders')]
#[Description('Remind owners of timed meetings, calls, site visits and follow-ups that start soon')]
class SendMeetingReminders extends Command
{
    public function handle(): int
    {
        $leadMinutes = max(1, (int) config('hub.meeting_reminder_minutes'));

        $items = FollowUp::query()
            ->with(['user', 'lead', 'subject'])
            ->open()
            ->where('has_time', true)
            ->whereNull('reminder_sent_at')
            ->where('due_at', '>', now())
            ->where('due_at', '<=', now()->addMinutes($leadMinutes))
            ->orderBy('due_at')
            ->get();

        $sent = 0;

        foreach ($items as $item) {
            if (! $item->user?->is_active) {
                continue;
            }

            // Claim the item first so overlapping runs can never send it twice.
            $claimed = FollowUp::query()->whereKey($item->id)->whereNull('reminder_sent_at')->update(['reminder_sent_at' => now()]);

            if (! $claimed) {
                continue;
            }

            $minutes = max(1, (int) ceil(now()->diffInSeconds($item->due_at) / 60));
            $contact = $item->contact_name ? " with {$item->contact_name}" : '';
            $place = $item->location ? " at {$item->location}" : '';

            Alerts::sendToUser(
                $item->user,
                'meeting_reminder',
                "{$item->type->label()} at {$item->due_at->format('H:i')}",
                "{$item->task}{$contact} ({$item->subjectLabel()}){$place} starts in {$minutes} ".str('minute')->plural($minutes).'.',
                route('calendar.index'),
            );

            $sent++;
        }

        $this->components->info("{$sent} ".str('reminder')->plural($sent).' sent.');

        return self::SUCCESS;
    }
}
