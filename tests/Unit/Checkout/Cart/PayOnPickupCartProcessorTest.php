<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Tests\Unit\Checkout\Cart;

use Kommandhub\ClickAndPickSW\Checkout\Cart\Error\InvalidPickupTimeCartBlockerError;
use Kommandhub\ClickAndPickSW\Checkout\Cart\Error\PickupLocationRequiredCartBlockerError;
use Kommandhub\ClickAndPickSW\Checkout\Cart\Error\UnsupportedDeliveryMethodCartBlockerError;
use Kommandhub\ClickAndPickSW\Checkout\Cart\PayOnPickupCartProcessor;
use Kommandhub\ClickAndPickSW\Checkout\Payment\PayOnPickupPaymentHandler;
use Kommandhub\ClickAndPickSW\Checkout\PickupSelection\PickupContextKeys;
use Kommandhub\ClickAndPickSW\Checkout\PickupSelection\PickupContextStorage;
use Kommandhub\ClickAndPickSW\Checkout\PickupSelection\PickupSelection;
use Kommandhub\ClickAndPickSW\Checkout\PickupSelection\StoredPickupSelection;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\Aggregate\PickupLocationOpeningHour\PickupLocationOpeningHourCollection;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\Aggregate\PickupLocationOpeningHour\PickupLocationOpeningHourEntity;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationEntity;
use Kommandhub\ClickAndPickSW\KmhClickAndPickSW;
use Kommandhub\ClickAndPickSW\PickupLocation\Availability\PickupLocationAvailabilityService;
use Kommandhub\ClickAndPickSW\PickupLocation\Availability\PickupTimeSlotService;
use Kommandhub\ClickAndPickSW\PickupLocation\PickupLocationSelectionResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

#[CoversClass(PayOnPickupCartProcessor::class)]
#[UsesClass(PickupLocationRequiredCartBlockerError::class)]
#[UsesClass(InvalidPickupTimeCartBlockerError::class)]
#[UsesClass(PickupLocationSelectionResolver::class)]
#[UsesClass(PickupContextStorage::class)]
#[UsesClass(PickupSelection::class)]
#[UsesClass(StoredPickupSelection::class)]
#[UsesClass(PickupTimeSlotService::class)]
#[UsesClass(PickupLocationAvailabilityService::class)]
#[UsesClass(UnsupportedDeliveryMethodCartBlockerError::class)]
#[UsesClass(PayOnPickupPaymentHandler::class)]
class PayOnPickupCartProcessorTest extends TestCase
{
    private const SALES_CHANNEL_ID = '11111111111111111111111111111111';
    private const LOCATION_ID = 'fedcba9876543210fedcba9876543210';
    private const TOKEN = 'context-token';

    private EntityRepository&MockObject $pickupLocationRepository;

    private SalesChannelContextPersister&MockObject $contextPersister;

    private PayOnPickupCartProcessor $processor;

    protected function setUp(): void
    {
        $this->pickupLocationRepository = $this->createMock(EntityRepository::class);
        $this->contextPersister = $this->createMock(SalesChannelContextPersister::class);

        $this->processor = new PayOnPickupCartProcessor(
            new PickupLocationSelectionResolver(
                $this->pickupLocationRepository,
                new PickupContextStorage($this->contextPersister)
            ),
            new PickupTimeSlotService(new PickupLocationAvailabilityService())
        );
    }

    public function testIgnoresOtherPaymentMethods(): void
    {
        $errors = new ErrorCollection();

        $this->pickupLocationRepository->expects(static::never())->method('search');

        $this->processor->validate(
            new Cart('token'),
            $errors,
            $this->salesChannelContext(
                $this->paymentMethod('App\\OtherPaymentHandler'),
                $this->shippingMethod('different-shipping-id', 'Courier')
            )
        );

        static::assertCount(0, $errors);
    }

    public function testAddsBlockingErrorForUnsupportedShippingMethod(): void
    {
        $errors = new ErrorCollection();

        $this->pickupLocationRepository->expects(static::never())->method('search');

        $this->processor->validate(
            new Cart('token'),
            $errors,
            $this->salesChannelContext(
                $this->paymentMethod(PayOnPickupPaymentHandler::class),
                $this->shippingMethod('different-shipping-id', 'Courier')
            )
        );

        static::assertCount(1, $errors);
        $error = $errors->first();
        static::assertInstanceOf(UnsupportedDeliveryMethodCartBlockerError::class, $error);
        static::assertSame(['deliveryMethodName' => 'Courier'], $error->getParameters());
    }

    public function testFallsBackToShippingMethodNameWhenTranslationIsMissing(): void
    {
        $errors = new ErrorCollection();

        $shippingMethod = new ShippingMethodEntity();
        $shippingMethod->setId('different-shipping-id');
        $shippingMethod->setName('Fallback Courier');
        $shippingMethod->setTranslated(['name' => ['unexpected']]);

        $this->pickupLocationRepository->expects(static::never())->method('search');

        $this->processor->validate(
            new Cart('token'),
            $errors,
            $this->salesChannelContext(
                $this->paymentMethod(PayOnPickupPaymentHandler::class),
                $shippingMethod
            )
        );

        static::assertCount(1, $errors);
        static::assertSame(
            ['deliveryMethodName' => 'Fallback Courier'],
            $errors->first()?->getParameters()
        );
    }

    public function testAllowsClickAndPickShippingWithValidPickupLocation(): void
    {
        $errors = new ErrorCollection();
        $context = $this->salesChannelContext(
            $this->paymentMethod(PayOnPickupPaymentHandler::class),
            $this->shippingMethod(KmhClickAndPickSW::SHIPPING_METHOD_ID, 'Self pick-up')
        );
        $this->storeSelection(self::LOCATION_ID);

        $pickupLocation = new PickupLocationEntity();
        $pickupLocation->setId(self::LOCATION_ID);

        $this->pickupLocationRepository
            ->expects(static::once())
            ->method('search')
            ->with(
                static::callback(function (Criteria $criteria): bool {
                    $filter = $criteria->getFilters()[0] ?? null;

                    if (!$filter instanceof MultiFilter) {
                        return false;
                    }

                    $queries = $filter->getQueries();

                    return $criteria->getLimit() === 1
                        && $queries[0] instanceof EqualsFilter
                        && $queries[0]->getField() === 'id'
                        && $queries[0]->getValue() === self::LOCATION_ID
                        && $queries[1] instanceof EqualsFilter
                        && $queries[1]->getField() === 'active'
                        && $queries[1]->getValue() === true
                        && $queries[2] instanceof EqualsFilter
                        && $queries[2]->getField() === 'salesChannels.id'
                        && $queries[2]->getValue() === self::SALES_CHANNEL_ID;
                }),
                $context->getContext()
            )
            ->willReturn($this->firstResult($pickupLocation));

        $this->processor->validate(new Cart('token'), $errors, $context);

        static::assertCount(0, $errors);
    }

    public function testAddsBlockingErrorWhenClickAndPickShippingHasNoPickupLocation(): void
    {
        $errors = new ErrorCollection();

        // No selection persisted.
        $this->storeSelection(null);
        $this->pickupLocationRepository->expects(static::never())->method('search');

        $this->processor->validate(
            new Cart('token'),
            $errors,
            $this->salesChannelContext(
                $this->paymentMethod('App\\OtherPaymentHandler'),
                $this->shippingMethod(KmhClickAndPickSW::SHIPPING_METHOD_ID, 'Self pick-up')
            )
        );

        static::assertCount(1, $errors);
        static::assertInstanceOf(PickupLocationRequiredCartBlockerError::class, $errors->first());
    }

    public function testAddsBlockingErrorWhenClickAndPickShippingHasInvalidPickupLocation(): void
    {
        $errors = new ErrorCollection();
        $context = $this->salesChannelContext(
            $this->paymentMethod('App\\OtherPaymentHandler'),
            $this->shippingMethod(KmhClickAndPickSW::SHIPPING_METHOD_ID, 'Self pick-up')
        );
        // Persisted, but the location no longer resolves (deactivated / removed).
        $this->storeSelection(self::LOCATION_ID);

        $this->pickupLocationRepository
            ->expects(static::once())
            ->method('search')
            ->with(static::isInstanceOf(Criteria::class), $context->getContext())
            ->willReturn($this->firstResult(null));

        $this->processor->validate(new Cart('token'), $errors, $context);

        static::assertCount(1, $errors);
        static::assertInstanceOf(PickupLocationRequiredCartBlockerError::class, $errors->first());
    }

    /**
     * Regression: the selection lives in the persisted context payload, not a
     * request-local extension. Even when the context carries no pickup extension
     * (a cart-calc pass whose context never passed the resolver listener, or the
     * very request that just persisted the selection), repeated calculations must
     * resolve the same valid selection and never add a false blocker.
     */
    public function testRepeatedCalculationsNeverProduceFalseBlocker(): void
    {
        $context = $this->salesChannelContext(
            $this->paymentMethod(PayOnPickupPaymentHandler::class),
            $this->shippingMethod(KmhClickAndPickSW::SHIPPING_METHOD_ID, 'Self pick-up')
        );
        static::assertNull($context->getExtension(PickupContextKeys::EXTENSION));

        $this->storeSelection(self::LOCATION_ID);

        $pickupLocation = new PickupLocationEntity();
        $pickupLocation->setId(self::LOCATION_ID);
        $this->pickupLocationRepository->method('search')->willReturn($this->firstResult($pickupLocation));

        // Simulate several cart-calculation passes within a submission.
        for ($pass = 0; $pass < 5; ++$pass) {
            $errors = new ErrorCollection();
            $this->processor->validate(new Cart('token'), $errors, $context);

            static::assertCount(0, $errors, "Pass {$pass} produced a false blocker error");
        }
    }

    public function testAddsBlockingErrorWhenChosenPickupTimeIsOutsideSchedule(): void
    {
        $errors = new ErrorCollection();
        $context = $this->salesChannelContext(
            $this->paymentMethod(PayOnPickupPaymentHandler::class),
            $this->shippingMethod(KmhClickAndPickSW::SHIPPING_METHOD_ID, 'Self pick-up')
        );
        // A time is chosen, but the location has no opening hours covering it.
        $this->storeSelection(self::LOCATION_ID, '2024-06-03T10:00:00+00:00');

        $this->pickupLocationRepository->method('search')->willReturn(
            $this->firstResult($this->location([]))
        );

        $this->processor->validate(new Cart('token'), $errors, $context);

        static::assertCount(1, $errors);
        static::assertInstanceOf(InvalidPickupTimeCartBlockerError::class, $errors->first());
    }

    public function testAllowsChosenPickupTimeInsideSchedule(): void
    {
        $errors = new ErrorCollection();
        $context = $this->salesChannelContext(
            $this->paymentMethod(PayOnPickupPaymentHandler::class),
            $this->shippingMethod(KmhClickAndPickSW::SHIPPING_METHOD_ID, 'Self pick-up')
        );
        // Monday 10:00 UTC, inside Monday 09:00–17:00.
        $this->storeSelection(self::LOCATION_ID, '2024-06-03T10:00:00+00:00');

        $this->pickupLocationRepository->method('search')->willReturn(
            $this->firstResult($this->location([[1, '09:00', '17:00']]))
        );

        $this->processor->validate(new Cart('token'), $errors, $context);

        static::assertCount(0, $errors);
    }

    /**
     * @param list<array{int, string, string}> $intervals
     */
    private function location(array $intervals): PickupLocationEntity
    {
        $entities = [];

        foreach ($intervals as [$dayOfWeek, $open, $close]) {
            $entity = new PickupLocationOpeningHourEntity();
            $entity->setId(bin2hex(random_bytes(16)));
            $entity->setPickupLocationId(self::LOCATION_ID);
            $entity->setDayOfWeek($dayOfWeek);
            $entity->setOpenTime($open);
            $entity->setCloseTime($close);
            $entities[] = $entity;
        }

        $location = new PickupLocationEntity();
        $location->setId(self::LOCATION_ID);
        $location->setTimezone('UTC');
        $location->setOpeningHoursSchedule(new PickupLocationOpeningHourCollection($entities));

        return $location;
    }

    private function storeSelection(?string $locationId, ?string $time = null, ?string $comment = null): void
    {
        $payload = array_filter([
            PickupContextKeys::LOCATION_ID => $locationId,
            PickupContextKeys::TIME => $time,
            PickupContextKeys::COMMENT => $comment,
        ], static fn ($value): bool => $value !== null);

        $this->contextPersister->method('load')->willReturn($payload);
    }

    private function salesChannelContext(
        PaymentMethodEntity $paymentMethod,
        ShippingMethodEntity $shippingMethod
    ): SalesChannelContext&MockObject {
        $context = $this->getMockBuilder(SalesChannelContext::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getContext',
                'getPaymentMethod',
                'getShippingMethod',
                'getSalesChannelId',
                'getToken',
                'getCustomer',
                'getPermissions',
            ])
            ->getMock();
        $context->method('getContext')->willReturn(Context::createDefaultContext());
        $context->method('getPaymentMethod')->willReturn($paymentMethod);
        $context->method('getShippingMethod')->willReturn($shippingMethod);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $context->method('getToken')->willReturn(self::TOKEN);
        $context->method('getCustomer')->willReturn(null);
        $context->method('getPermissions')->willReturn([]);

        return $context;
    }

    private function paymentMethod(string $handlerIdentifier): PaymentMethodEntity
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setHandlerIdentifier($handlerIdentifier);

        return $paymentMethod;
    }

    private function shippingMethod(string $id, string $name): ShippingMethodEntity
    {
        $shippingMethod = new ShippingMethodEntity();
        $shippingMethod->setId($id);
        $shippingMethod->setTranslated(['name' => $name]);

        return $shippingMethod;
    }

    private function firstResult(?object $first): EntitySearchResult&MockObject
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('first')->willReturn($first);

        return $result;
    }
}
