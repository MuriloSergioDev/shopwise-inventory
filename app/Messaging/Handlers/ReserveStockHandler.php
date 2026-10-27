<?php

declare(strict_types=1);

namespace App\Messaging\Handlers;

use App\Actions\ReserveStock;
use Shopwise\Contracts\Commands\Inventory\ReserveStockV1;
use Shopwise\Kit\Messaging\Envelope;
use Shopwise\Kit\Messaging\MessageHandler;

final readonly class ReserveStockHandler implements MessageHandler
{
    public function __construct(private ReserveStock $reserveStock) {}

    public function handle(Envelope $envelope): void
    {
        $command = ReserveStockV1::fromArray($envelope->data);

        $lines = [];
        /** @var array{sku: string, quantity: int} $line */
        foreach ($command->lines as $line) {
            $lines[] = ['sku' => (string) $line['sku'], 'quantity' => (int) $line['quantity']];
        }

        $this->reserveStock->handle($command->checkoutId, $lines);
    }
}
