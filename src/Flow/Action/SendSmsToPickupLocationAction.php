<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Flow\Action;

use Kommandhub\ClickAndPickSW\Entity\OrderPickupLocation\OrderPickupLocationEntity;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationEntity;
use Kommandhub\ClickAndPickSW\Flow\Aware\OrderPickupLocationAware;
use Kommandhub\ClickAndPickSW\Flow\Aware\PickupLocationAware;
use Kommandhub\ClickAndPickSW\Flow\Sms\SmsGateway;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Flow\Dispatching\Action\FlowAction;
use Shopware\Core\Content\Flow\Dispatching\DelayableAction;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Framework\Event\OrderAware;

/**
 * Flow Builder action that texts the order's pickup location on its configured
 * phone number.
 *
 * Soft dependency on KommandhubSmsSW: that plugin is NOT required in composer.
 * Its SMS gateway is injected as an optional service (see services.yml,
 * `@?...NotificationGatewayInterface`). When KommandhubSmsSW is not installed or
 * not active the service is absent, $smsGateway is null, and this action does
 * nothing. When it is present the gateway is called by duck typing.
 */
class SendSmsToPickupLocationAction extends FlowAction implements DelayableAction
{
    public const ACTION_NAME = 'action.kmh.pickup.notify_sms';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?object $smsGateway = null,
    ) {
    }

    public static function getName(): string
    {
        return self::ACTION_NAME;
    }

    /**
     * @return array<string>
     */
    public function requirements(): array
    {
        return [OrderAware::class, PickupLocationAware::class];
    }

    public function handleFlow(StorableFlow $flow): void
    {
        $gateway = $this->smsGateway;

        if ($gateway === null) {
            // KommandhubSmsSW not installed/active — nothing to do.
            return;
        }

        if (!$flow->hasData(OrderAware::ORDER) || !$flow->hasData(PickupLocationAware::PICKUP_LOCATION)) {
            return;
        }

        $order = $flow->getData(OrderAware::ORDER);
        $pickupLocation = $flow->getData(PickupLocationAware::PICKUP_LOCATION);

        if (!$order instanceof OrderEntity || !$pickupLocation instanceof PickupLocationEntity) {
            return; // @codeCoverageIgnore
        }

        $recipient = $this->normalizePhoneNumber($pickupLocation->getPhoneNumber());

        if ($recipient === null) {
            $this->logger->warning('Pickup location has no usable phone number; skipping SMS notification.', [
                'pickupLocationId' => $pickupLocation->getId(),
                'orderId' => $order->getId(),
            ]);

            return;
        }

        /** @var SmsGateway $gateway The optional KommandhubSmsSW gateway, matched structurally. */
        $salesChannelId = $order->getSalesChannelId();

        try {
            if (!$gateway->isConfigured($salesChannelId)) {
                // No SMS provider configured for this sales channel — no-op.
                return;
            }

            $gateway->send($recipient, $this->buildMessage($order, $pickupLocation, $this->resolvePickupRecord($flow)), $salesChannelId);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send pickup SMS notification.', [
                'exception' => $e,
                'orderId' => $order->getId(),
            ]);
        }
    }

    private function normalizePhoneNumber(?string $phoneNumber): ?string
    {
        if ($phoneNumber === null) {
            return null;
        }

        // KommandhubSmsSW expects E.164 digits without a leading "+".
        $digits = preg_replace('/\D+/', '', $phoneNumber);

        return \is_string($digits) && $digits !== '' ? $digits : null;
    }

    private function resolvePickupRecord(StorableFlow $flow): ?OrderPickupLocationEntity
    {
        if (!$flow->hasData(OrderPickupLocationAware::ORDER_PICKUP_LOCATION)) {
            return null;
        }

        $record = $flow->getData(OrderPickupLocationAware::ORDER_PICKUP_LOCATION);

        return $record instanceof OrderPickupLocationEntity ? $record : null;
    }

    private function buildMessage(
        OrderEntity $order,
        PickupLocationEntity $pickupLocation,
        ?OrderPickupLocationEntity $pickupRecord
    ): string {
        $message = sprintf(
            'Pickup order %s for %s.',
            $order->getOrderNumber() ?? $order->getId(),
            $pickupLocation->getName()
        );

        $pickupTime = $pickupRecord?->getPickupTime();

        if ($pickupTime !== null) {
            // Stored in UTC; the store reads its own local time.
            $local = \DateTimeImmutable::createFromInterface($pickupTime)->setTimezone($this->timezoneOf($pickupLocation));
            $message .= ' Pickup time: ' . $local->format('Y-m-d H:i') . '.';
        }

        return $message;
    }

    private function timezoneOf(PickupLocationEntity $pickupLocation): \DateTimeZone
    {
        try {
            return new \DateTimeZone($pickupLocation->getTimezone() ?? 'UTC');
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }
}
