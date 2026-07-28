<?php

namespace App\Observers;

use App\Models\Shipment;
use App\Services\TelegramService;

class ShipmentObserver
{
    public function __construct(private TelegramService $telegramService)
    {
    }

    public function updated(Shipment $shipment): void
    {
        if (! $shipment->wasChanged('status')) {
            return;
        }

        $this->telegramService->notifyShipmentStatusChanged($shipment);

        if ($shipment->status === 'delivered') {
            if (! $shipment->order) {
                return;
            }

            if ($shipment->order->status === 'completed') {
                return;
            }

            $shipment->order->update(['status' => 'completed']);
        }
    }
}
