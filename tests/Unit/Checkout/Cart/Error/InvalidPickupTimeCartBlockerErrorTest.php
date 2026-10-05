<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Tests\Unit\Checkout\Cart\Error;

use Kommandhub\ClickAndPickSW\Checkout\Cart\Error\InvalidPickupTimeCartBlockerError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Error\Error;

#[CoversClass(InvalidPickupTimeCartBlockerError::class)]
class InvalidPickupTimeCartBlockerErrorTest extends TestCase
{
    public function testExposesBlockingErrorMetadata(): void
    {
        $error = new InvalidPickupTimeCartBlockerError();

        static::assertSame('kmh-click-and-pick.invalidPickupTime', $error->getId());
        static::assertSame('kmh-click-and-pick.invalidPickupTime', $error->getMessageKey());
        static::assertSame(Error::LEVEL_ERROR, $error->getLevel());
        static::assertTrue($error->blockOrder());
        static::assertSame([], $error->getParameters());
        static::assertSame(
            'The selected pickup time is outside the location\'s opening hours. Please choose a valid time.',
            $error->getMessage()
        );
    }
}
