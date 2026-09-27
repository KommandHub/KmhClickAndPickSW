<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Checkout\Cart\Error;

use Shopware\Core\Checkout\Cart\Error\Error;

class UnsupportedDeliveryMethodCartBlockerError extends Error
{
    private const KEY = 'kmh-click-and-pick.unsupportedDeliveryMethod';

    public function __construct(protected readonly string $deliveryMethodName)
    {
        $this->message = sprintf('The selected delivery method "%s" is not supported for the chosen payment method. Please select a different delivery method.', $deliveryMethodName);
        parent::__construct($this->message);
    }

    public function getId(): string
    {
        return $this->getMessageKey();
    }

    public function getMessageKey(): string
    {
        return self::KEY;
    }

    public function getLevel(): int
    {
        return self::LEVEL_WARNING;
    }

    public function blockOrder(): bool
    {
        return true;
    }

    public function getParameters(): array
    {
        return [
            'deliveryMethodName' => $this->deliveryMethodName,
        ];
    }
}
