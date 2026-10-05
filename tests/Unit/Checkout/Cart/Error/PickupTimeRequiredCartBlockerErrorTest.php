<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Tests\Unit\Checkout\Cart\Error;

use Kommandhub\ClickAndPickSW\Checkout\Cart\Error\PickupTimeRequiredCartBlockerError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Error\Error;

#[CoversClass(PickupTimeRequiredCartBlockerError::class)]
class PickupTimeRequiredCartBlockerErrorTest extends TestCase
{
    public function testExposesBlockingErrorMetadata(): void
    {
        $error = new PickupTimeRequiredCartBlockerError();

        static::assertSame('kmh-click-and-pick.pickupTimeRequired', $error->getId());
        static::assertSame('kmh-click-and-pick.pickupTimeRequired', $error->getMessageKey());
        static::assertSame(Error::LEVEL_ERROR, $error->getLevel());
        static::assertTrue($error->blockOrder());
        static::assertSame([], $error->getParameters());
        static::assertSame(
            'Please choose a pickup time before placing your order.',
            $error->getMessage()
        );
    }
}
