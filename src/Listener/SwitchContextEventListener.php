<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Listener;

use Kommandhub\ClickAndPickSW\Checkout\PickupSelection\PickupContextKeys;
use Kommandhub\ClickAndPickSW\Checkout\PickupSelection\PickupContextStorage;
use Kommandhub\ClickAndPickSW\Checkout\PickupSelection\StoredPickupSelection;
use Kommandhub\ClickAndPickSW\KmhClickAndPickSW;
use Kommandhub\ClickAndPickSW\PickupLocation\PickupLocationValidator;
use Shopware\Core\Framework\Routing\Event\SalesChannelContextResolvedEvent;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\Event\SwitchContextEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Keeps the customer's pickup selection in sync with the sales-channel context.
 *
 * On a context switch that carries the pickup field, it validates and persists
 * the selection; on context resolution it re-attaches the selection as a context
 * extension the storefront reads. All persistence and validation is delegated —
 * this listener only wires the two context events to those services.
 *
 * The stored payload lives with Shopware's own shipping/payment selection and
 * inherits its lifecycle (see {@see PickupContextStorage}); the selection is
 * cleared when its cart becomes an order (see {@see OrderListener}).
 */
readonly class SwitchContextEventListener
{
    /**
     * Back-compat aliases for the pickup context keys, now owned by
     * {@see PickupContextKeys}. Kept because other services and tests reference
     * them.
     */
    final public const PICKUP_LOCATION_ID = PickupContextKeys::LOCATION_ID;
    final public const PICKUP_LOCATION_EXTENSION = PickupContextKeys::EXTENSION;
    final public const PICKUP_TIME = PickupContextKeys::TIME;
    final public const PICKUP_COMMENT = PickupContextKeys::COMMENT;

    public function __construct(
        private PickupContextStorage $pickupContextStorage,
        private PickupLocationValidator $pickupLocationValidator,
    ) {
    }

    /**
     * Validate + persist the pickup selection on a context switch that carries
     * the pickup field. Switches that do not carry it (payment method, address,
     * language, …) are ignored so they never disturb the stored selection.
     */
    #[AsEventListener(event: SwitchContextEvent::CONSISTENT_CHECK)]
    public function onSwitchContext(SwitchContextEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        $requestData = $event->getRequestData();

        if (!$requestData->has(PickupContextKeys::LOCATION_ID)) {
            return;
        }

        $pickupLocationId = $this->normalize($requestData->get(PickupContextKeys::LOCATION_ID));

        // Field present but empty — the customer explicitly removed the pickup
        // location (the remove control), so clear the whole selection.
        if ($pickupLocationId === null) {
            $this->pickupContextStorage->clear($context);

            return;
        }

        // Reject a stale/foreign/inactive location before it is persisted. The
        // pickup time is validated later at the checkout gate, which has the
        // location's schedule loaded.
        $this->pickupLocationValidator->validate($pickupLocationId, $context);

        $this->pickupContextStorage->save($context, new StoredPickupSelection(
            $pickupLocationId,
            $this->normalize($requestData->get(PickupContextKeys::TIME)),
            $this->normalize($requestData->get(PickupContextKeys::COMMENT)),
        ));
    }

    /**
     * Re-attach the stored pickup selection as a context extension. Only touches
     * the store for the Click & Pick shipping method, so every other request
     * avoids the lookup entirely.
     */
    #[AsEventListener(event: SalesChannelContextResolvedEvent::class)]
    public function onSalesChannelContextResolved(SalesChannelContextResolvedEvent $event): void
    {
        $context = $event->getSalesChannelContext();

        if ($context->getShippingMethod()->getId() !== KmhClickAndPickSW::SHIPPING_METHOD_ID) {
            return;
        }

        $stored = $this->pickupContextStorage->load($context);

        if ($stored->isEmpty()) {
            return;
        }

        $context->addExtension(PickupContextKeys::EXTENSION, new ArrayStruct([
            PickupContextKeys::LEGACY_ID => $stored->pickupLocationId,
            PickupContextKeys::LOCATION_ID => $stored->pickupLocationId,
            PickupContextKeys::TIME => $stored->pickupTime,
            PickupContextKeys::COMMENT => $stored->comment,
        ]));
    }

    private function normalize(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
