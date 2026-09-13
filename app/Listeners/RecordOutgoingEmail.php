<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Mail\EmailDeliveryLog;
use Illuminate\Mail\Events\MessageSending;

/**
 * Writes the log row for every message as it is handed to the transport.
 */
final class RecordOutgoingEmail
{
    public function __construct(private readonly EmailDeliveryLog $log) {}

    public function handle(MessageSending $event): void
    {
        $this->log->recordSending($event->message, $event->data);
    }
}
