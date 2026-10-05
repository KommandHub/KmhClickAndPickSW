<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Tests\Unit\Checkout\Cart\Error;

use Kommandhub\ClickAndPickSW\Checkout\Cart\Error\UnsupportedDeliveryMethodCartBlockerError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Error\Error;

#[CoversClass(UnsupportedDeliveryMethodCartBlockerError::class)]
class UnsupportedDeliveryMethodCartBlockerErrorTest extends TestCase
{
    public function testExposesBlockingWarningErrorMetadata(): void
    {
        $error = new UnsupportedDeliveryMethodCartBlockerError('Express Delivery');

        static::assertSame('kmh-click-and-pick.unsupportedDeliveryMethod', $error->getId());
        static::assertSame('kmh-click-and-pick.unsupportedDeliveryMethod', $error->getMessageKey());
        static::assertSame(Error::LEVEL_WARNING, $error->getLevel());
        static::assertTrue($error->blockOrder());
        static::assertSame(
            ['deliveryMethodName' => 'Express Delivery'],
            $error->getParameters()
        );
        static::assertStringContainsString('Express Delivery', $error->getMessage());
    }
}
