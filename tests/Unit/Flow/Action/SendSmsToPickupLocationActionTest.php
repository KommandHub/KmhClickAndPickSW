<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Tests\Unit\Flow\Action;

use Kommandhub\ClickAndPickSW\Entity\OrderPickupLocation\OrderPickupLocationEntity;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationEntity;
use Kommandhub\ClickAndPickSW\Flow\Action\SendSmsToPickupLocationAction;
use Kommandhub\ClickAndPickSW\Flow\Aware\OrderPickupLocationAware;
use Kommandhub\ClickAndPickSW\Flow\Aware\PickupLocationAware;
use Kommandhub\ClickAndPickSW\Flow\Sms\SmsGateway;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\OrderAware;

#[CoversClass(SendSmsToPickupLocationAction::class)]
class SendSmsToPickupLocationActionTest extends TestCase
{
    private const ORDER_ID = '0123456789abcdef0123456789abcdef';
    private const SALES_CHANNEL_ID = '11111111111111111111111111111111';
    private const PICKUP_LOCATION_ID = 'fedcba9876543210fedcba9876543210';

    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testActionMetadata(): void
    {
        $action = new SendSmsToPickupLocationAction($this->logger, $this->createMock(SmsGateway::class));

        static::assertSame('action.kmh.pickup.notify_sms', SendSmsToPickupLocationAction::getName());
        static::assertSame([OrderAware::class, PickupLocationAware::class], $action->requirements());
    }

    public function testDoesNothingWhenSmsPluginIsNotAvailable(): void
    {
        // Soft dependency: KommandhubSmsSW absent/inactive -> gateway is null.
        $action = new SendSmsToPickupLocationAction($this->logger, null);

        $this->logger->expects(static::never())->method('warning');
        $this->logger->expects(static::never())->method('error');

        $action->handleFlow($this->flow($this->order(), $this->location('+2348012345678')));
    }

    public function testSendsSmsToNormalizedPickupPhoneWhenConfigured(): void
    {
        $gateway = $this->createMock(SmsGateway::class);
        $gateway->method('isConfigured')->with(self::SALES_CHANNEL_ID)->willReturn(true);
        $gateway
            ->expects(static::once())
            ->method('send')
            ->with('2348012345678', 'Pickup order 10001 for Downtown Store.', self::SALES_CHANNEL_ID)
            ->willReturn('provider-message-id');

        $action = new SendSmsToPickupLocationAction($this->logger, $gateway);

        $action->handleFlow($this->flow($this->order(), $this->location('+234 (801) 234-5678')));
    }

    public function testDoesNotSendWhenNoProviderConfigured(): void
    {
        $gateway = $this->createMock(SmsGateway::class);
        $gateway->method('isConfigured')->willReturn(false);
        $gateway->expects(static::never())->method('send');

        $action = new SendSmsToPickupLocationAction($this->logger, $gateway);

        $action->handleFlow($this->flow($this->order(), $this->location('+2348012345678')));
    }

    public function testSkipsAndWarnsWhenPickupLocationHasNoUsablePhone(): void
    {
        $gateway = $this->createMock(SmsGateway::class);
        $gateway->expects(static::never())->method('isConfigured');
        $gateway->expects(static::never())->method('send');

        $this->logger
            ->expects(static::once())
            ->method('warning')
            ->with(
                'Pickup location has no usable phone number; skipping SMS notification.',
                static::callback(static fn (array $context): bool => $context['pickupLocationId'] === self::PICKUP_LOCATION_ID
                    && $context['orderId'] === self::ORDER_ID)
            );

        $action = new SendSmsToPickupLocationAction($this->logger, $gateway);

        // Phone with no digits normalizes to null.
        $action->handleFlow($this->flow($this->order(), $this->location('n/a')));
    }

    public function testDoesNothingWhenPhoneIsNull(): void
    {
        $gateway = $this->createMock(SmsGateway::class);
        $gateway->expects(static::never())->method('send');

        $action = new SendSmsToPickupLocationAction($this->logger, $gateway);

        $action->handleFlow($this->flow($this->order(), $this->location(null)));
    }

    public function testDoesNothingWhenFlowDataIsMissing(): void
    {
        $gateway = $this->createMock(SmsGateway::class);
        $gateway->expects(static::never())->method('isConfigured');
        $gateway->expects(static::never())->method('send');

        $action = new SendSmsToPickupLocationAction($this->logger, $gateway);

        $action->handleFlow(new StorableFlow('pickup.order.placed', Context::createDefaultContext()));
    }

    public function testLogsAndSwallowsGatewayFailure(): void
    {
        $gateway = $this->createMock(SmsGateway::class);
        $gateway->method('isConfigured')->willReturn(true);
        $gateway->method('send')->willThrowException(new \RuntimeException('provider down'));

        $this->logger
            ->expects(static::once())
            ->method('error')
            ->with(
                'Failed to send pickup SMS notification.',
                static::callback(static fn (array $context): bool => $context['orderId'] === self::ORDER_ID
                    && $context['exception'] instanceof \RuntimeException)
            );

        $action = new SendSmsToPickupLocationAction($this->logger, $gateway);

        $action->handleFlow($this->flow($this->order(), $this->location('+2348012345678')));
    }

    public function testAppendsPickupTimeToMessageWhenRecordPresent(): void
    {
        $record = new OrderPickupLocationEntity();
        $record->setId('cccccccccccccccccccccccccccccccc');
        $record->setPickupTime(new \DateTimeImmutable('2024-06-03 10:30:00'));

        $gateway = $this->createMock(SmsGateway::class);
        $gateway->method('isConfigured')->willReturn(true);
        $gateway
            ->expects(static::once())
            ->method('send')
            ->with(
                '2348012345678',
                'Pickup order 10001 for Downtown Store. Pickup time: 2024-06-03 10:30.',
                self::SALES_CHANNEL_ID
            )
            ->willReturn('provider-message-id');

        $action = new SendSmsToPickupLocationAction($this->logger, $gateway);

        $action->handleFlow($this->flow($this->order(), $this->location('+2348012345678'), $record));
    }

    private function flow(OrderEntity $order, PickupLocationEntity $location, ?OrderPickupLocationEntity $record = null): StorableFlow
    {
        $data = [
            OrderAware::ORDER => $order,
            PickupLocationAware::PICKUP_LOCATION => $location,
        ];

        if ($record !== null) {
            $data[OrderPickupLocationAware::ORDER_PICKUP_LOCATION] = $record;
        }

        return new StorableFlow('pickup.order.placed', Context::createDefaultContext(), [], $data);
    }

    private function order(): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setSalesChannelId(self::SALES_CHANNEL_ID);
        $order->setOrderNumber('10001');

        return $order;
    }

    private function location(?string $phone): PickupLocationEntity
    {
        $location = new PickupLocationEntity();
        $location->setId(self::PICKUP_LOCATION_ID);
        $location->setName('Downtown Store');
        $location->setPhoneNumber($phone);

        return $location;
    }
}
