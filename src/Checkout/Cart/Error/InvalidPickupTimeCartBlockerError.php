<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Checkout\Cart\Error;

use Shopware\Core\Checkout\Cart\Error\Error;

final class InvalidPickupTimeCartBlockerError extends Error
{
    private const KEY = 'kmh-click-and-pick.invalidPickupTime';

    public function __construct()
    {
        $this->message = 'The selected pickup time is outside the location\'s opening hours. Please choose a valid time.';

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
