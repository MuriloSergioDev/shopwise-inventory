<?php

declare(strict_types=1);

namespace App\Messaging\Handlers;

use App\Actions\ReleaseReservation;
use Shopwise\Contracts\Commands\Inventory\ReleaseReservationV1;
use Shopwise\Kit\Messaging\Envelope;
use Shopwise\Kit\Messaging\MessageHandler;

final readonly class ReleaseReservationHandler implements MessageHandler
{
    public function __construct(private ReleaseReservation $releaseReservation) {}

    public function handle(Envelope $envelope): void
    {
        $this->releaseReservation->handle(ReleaseReservationV1::fromArray($envelope->data)->checkoutId);
    }
}
