<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Tests\Unit\Listener;

use Kommandhub\ClickAndPickSW\Checkout\PickupSelection\PickupContextKeys;
use Kommandhub\ClickAndPickSW\Checkout\PickupSelection\PickupContextStorage;
use Kommandhub\ClickAndPickSW\Checkout\PickupSelection\StoredPickupSelection;
use Kommandhub\ClickAndPickSW\KmhClickAndPickSW;
use Kommandhub\ClickAndPickSW\Listener\SwitchContextEventListener;
use Kommandhub\ClickAndPickSW\PickupLocation\PickupLocationValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Routing\Event\SalesChannelContextResolvedEvent;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\System\SalesChannel\Event\SwitchContextEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

#[CoversClass(SwitchContextEventListener::class)]
#[UsesClass(StoredPickupSelection::class)]
class SwitchContextEventListenerTest extends TestCase
{
    private const LOCATION_ID = 'fedcba9876543210fedcba9876543210';
    private const TOKEN = 'context-token';

    private PickupContextStorage&MockObject $storage;

    private PickupLocationValidator&MockObject $validator;

    private SwitchContextEventListener $listener;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(PickupContextStorage::class);
        $this->validator = $this->createMock(PickupLocationValidator::class);
        $this->listener = new SwitchContextEventListener($this->storage, $this->validator);
    }

    public function testIgnoresSwitchesWithoutPickupField(): void
    {
        // A payment- or address-only context switch carries no pickup field and
        // must not validate or touch the stored selection.
        $this->validator->expects(static::never())->method('validate');
        $this->storage->expects(static::never())->method('save');
        $this->storage->expects(static::never())->method('clear');

        $this->listener->onSwitchContext($this->switchEvent(
            new RequestDataBag(['paymentMethodId' => 'some-payment-method'])
        ));
    }

    public function testClearsSelectionWhenPickupFieldIsExplicitlyEmpty(): void
    {
        $context = $this->context();

        $this->validator->expects(static::never())->method('validate');
        $this->storage->expects(static::once())->method('clear')->with($context);
        $this->storage->expects(static::never())->method('save');

        $this->listener->onSwitchContext($this->switchEvent(
            new RequestDataBag([PickupContextKeys::LOCATION_ID => '']),
            $context
        ));
    }

    public function testTreatsNonStringPickupFieldAsCleared(): void
    {
        $context = $this->context();

        // A present-but-non-string value normalizes to null → treated as a clear,
        // never validated or persisted as a location.
        $this->validator->expects(static::never())->method('validate');
        $this->storage->expects(static::once())->method('clear')->with($context);
        $this->storage->expects(static::never())->method('save');

        $this->listener->onSwitchContext($this->switchEvent(
            new RequestDataBag([PickupContextKeys::LOCATION_ID => ['unexpected']]),
            $context
        ));
    }

    public function testValidatesAndPersistsSelection(): void
    {
        $context = $this->context();

        $this->validator
            ->expects(static::once())
            ->method('validate')
            ->with(self::LOCATION_ID, $context);

        $this->storage
            ->expects(static::once())
            ->method('save')
            ->with(
                $context,
                static::callback(static fn (StoredPickupSelection $selection): bool => $selection->pickupLocationId === self::LOCATION_ID
                    && $selection->pickupTime === '2024-06-03T10:00:00+01:00'
                    && $selection->comment === 'Ring the bell')
            );

        $this->listener->onSwitchContext($this->switchEvent(
            new RequestDataBag([
                PickupContextKeys::LOCATION_ID => self::LOCATION_ID,
                PickupContextKeys::TIME => ' 2024-06-03T10:00:00+01:00 ',
                PickupContextKeys::COMMENT => ' Ring the bell ',
            ]),
            $context
        ));
    }

    public function testDoesNotPersistWhenValidationFails(): void
    {
        $context = $this->context();

        $this->validator
            ->method('validate')
            ->willThrowException(new \RuntimeException('invalid location'));

        $this->storage->expects(static::never())->method('save');

        $this->expectException(\RuntimeException::class);

        $this->listener->onSwitchContext($this->switchEvent(
            new RequestDataBag([PickupContextKeys::LOCATION_ID => self::LOCATION_ID]),
            $context
        ));
    }

    public function testResolvedIgnoresNonPickupShippingMethod(): void
    {
        // No lookup at all when the customer is not on the Click & Pick method.
        $this->storage->expects(static::never())->method('load');

        $context = $this->context('some-other-shipping-method');

        $this->listener->onSalesChannelContextResolved(
            new SalesChannelContextResolvedEvent($context, self::TOKEN)
        );

        static::assertNull($context->getExtension(PickupContextKeys::EXTENSION));
    }

    public function testResolvedAttachesStoredSelectionAsExtension(): void
    {
        $context = $this->context(KmhClickAndPickSW::SHIPPING_METHOD_ID);

        $this->storage
            ->method('load')
            ->with($context)
            ->willReturn(new StoredPickupSelection(self::LOCATION_ID, '2024-06-03T10:00:00+01:00', 'Ring the bell'));

        $this->listener->onSalesChannelContextResolved(
            new SalesChannelContextResolvedEvent($context, self::TOKEN)
        );

        $extension = $context->getExtension(PickupContextKeys::EXTENSION);

        static::assertInstanceOf(ArrayStruct::class, $extension);
        static::assertSame(self::LOCATION_ID, $extension->get(PickupContextKeys::LEGACY_ID));
        static::assertSame(self::LOCATION_ID, $extension->get(PickupContextKeys::LOCATION_ID));
        static::assertSame('2024-06-03T10:00:00+01:00', $extension->get(PickupContextKeys::TIME));
        static::assertSame('Ring the bell', $extension->get(PickupContextKeys::COMMENT));
    }

    public function testResolvedAttachesNothingWhenSelectionEmpty(): void
    {
        $context = $this->context(KmhClickAndPickSW::SHIPPING_METHOD_ID);

        $this->storage->method('load')->willReturn(new StoredPickupSelection());

        $this->listener->onSalesChannelContextResolved(
            new SalesChannelContextResolvedEvent($context, self::TOKEN)
        );

        static::assertNull($context->getExtension(PickupContextKeys::EXTENSION));
    }

    private function switchEvent(RequestDataBag $requestData, ?SalesChannelContext $context = null): SwitchContextEvent
    {
        return new SwitchContextEvent(
            $requestData,
            $context ?? $this->context(),
            new DataValidationDefinition(),
            []
        );
    }

    private function context(?string $shippingMethodId = null): SalesChannelContext&MockObject
    {
        $context = $this->getMockBuilder(SalesChannelContext::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingMethod'])
            ->getMock();

        $shippingMethod = new ShippingMethodEntity();
        $shippingMethod->setId($shippingMethodId ?? 'default-shipping-method');
        $context->method('getShippingMethod')->willReturn($shippingMethod);

        return $context;
    }
}
