<?php

namespace App\Integrations\Couriers;

use App\Models\CourierAccount;
use InvalidArgumentException;

class CourierGatewayFactory
{
    private const GATEWAYS = [
        'leopards' => LeopardsGateway::class,
        'postex' => PostExGateway::class,
        'tcs' => TcsGateway::class,
        'trax' => TraxGateway::class,
    ];

    public function for(CourierAccount $account): CourierGateway
    {
        $class = self::GATEWAYS[$account->carrier] ?? throw new InvalidArgumentException("No courier integration for {$account->carrier}.");

        return app($class);
    }
}
