<?php

declare(strict_types=1);

namespace App\Messaging\Handlers;

use App\Actions\CommitReservation;
use Shopwise\Contracts\Commands\Inventory\CommitReservationV1;
use Shopwise\Kit\Messaging\Envelope;
use Shopwise\Kit\Messaging\MessageHandler;

final readonly class CommitReservationHandler implements MessageHandler
{
    public function __construct(private CommitReservation $commitReservation) {}

    public function handle(Envelope $envelope): void
    {
        $command = CommitReservationV1::fromArray($envelope->data);

        $this->commitReservation->handle($command->checkoutId, $command->reservationId);
    }
}
