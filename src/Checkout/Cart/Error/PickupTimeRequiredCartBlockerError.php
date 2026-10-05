<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Checkout\Cart\Error;

use Shopware\Core\Checkout\Cart\Error\Error;

final class PickupTimeRequiredCartBlockerError extends Error
{
    private const KEY = 'kmh-click-and-pick.pickupTimeRequired';

    public function __construct()
    {
        $this->message = 'Please choose a pickup time before placing your order.';

        parent::__construct($this->message);
    }

    public function getId(): string
    {
        return self::KEY;
    }

    public function getMessageKey(): string
    {
        return self::KEY;
    }

    public function getLevel(): int
    {
        return self::LEVEL_ERROR;
    }

    public function blockOrder(): bool
    {
        return true;
    }

    public function getParameters(): array
    {
        return [];
    }
}
