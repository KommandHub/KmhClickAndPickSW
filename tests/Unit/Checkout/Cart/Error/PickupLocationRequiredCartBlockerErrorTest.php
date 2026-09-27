<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Tests\Unit\Checkout\Cart\Error;

use Kommandhub\ClickAndPickSW\Checkout\Cart\Error\PickupLocationRequiredCartBlockerError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Error\Error;

#[CoversClass(PickupLocationRequiredCartBlockerError::class)]
class PickupLocationRequiredCartBlockerErrorTest extends TestCase
{
    public function testExposesBlockingErrorMetadata(): void
    {
        $error = new PickupLocationRequiredCartBlockerError();

        static::assertSame('kmh-click-and-pick.pickupLocationRequired', $error->getId());
        static::assertSame('kmh-click-and-pick.pickupLocationRequired', $error->getMessageKey());
        static::assertSame(Error::LEVEL_ERROR, $error->getLevel());
        static::assertTrue($error->blockOrder());
        static::assertSame([], $error->getParameters());
        static::assertSame(
            'Please select a valid pickup location for Click & Pick before placing your order.',
            $error->getMessage()
        );
    }
}
