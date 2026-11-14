<?php

namespace App\Console\Commands;

use App\Models\ScheduledMessage;
use App\Services\ScheduledMessageSender;
use Illuminate\Console\Command;

class SendScheduledMessages extends Command
{
    protected $signature = 'messages:send-scheduled';

    protected $description = 'Sends every scheduled message whose time has come';

    public function handle(): int
    {
        $due = ScheduledMessage::where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(200)
            ->get();

        foreach ($due as $scheduled) {
            ScheduledMessageSender::send($scheduled);
        }

        if ($due->count() > 0) {
            $this->info("Sent {$due->count()} scheduled message(s).");
        }

        return self::SUCCESS;
    }
}