<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Listener;

use Kommandhub\ClickAndPickSW\Checkout\PickupSelection\OrderPickupLocationWriter;
use Kommandhub\ClickAndPickSW\Checkout\PickupSelection\PickupContextStorage;
use Kommandhub\ClickAndPickSW\Event\PickupOrderPlacedEvent;
use Kommandhub\ClickAndPickSW\PickupLocation\PickupLocationSelectionResolver;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * On order placement for a pickup order, persists the order pickup-location
 * record (location + chosen time + instructions — the single source of truth),
 * dispatches the {@see PickupOrderPlacedEvent} flow trigger, and clears the
 * pickup selection from the context so the next cart starts clean. Notifications
 * are handled by Flow Builder flows reacting to the trigger — no mail here.
 *
 * Loading pickup data onto an order is handled by the DAL OneToOne association
 * (`order.kmhPickupLocation`), so no order-loaded listener is needed.
 */
readonly class OrderListener
{
    public function __construct(
        private PickupLocationSelectionResolver $pickupLocationSelectionResolver,
        private OrderPickupLocationWriter $orderPickupLocationWriter,
        private PickupContextStorage $pickupContextStorage,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[AsEventListener(event: CheckoutOrderPlacedEvent::class)]
    public function onCheckoutOrderPlacedEvent(CheckoutOrderPlacedEvent $event): void
    {
        $salesChannelContext = $event->getSalesChannelContext();

        if (!$this->pickupLocationSelectionResolver->isPickupShippingMethod($salesChannelContext)) {
            return;
        }

        $selection = $this->pickupLocationSelectionResolver->resolveSelection($salesChannelContext);

        if ($selection === null) {
            return;
        }

        $order = $event->getOrder();

        $pickupRecord = $this->orderPickupLocationWriter->persist(
            $order->getId(),
            $selection,
            $salesChannelContext->getContext()
        );

        $this->eventDispatcher->dispatch(
            new PickupOrderPlacedEvent($salesChannelContext, $order, $selection->pickupLocation, $pickupRecord),
            PickupOrderPlacedEvent::EVENT_NAME
        );

        // The selection now lives on the order; drop it from the context so a new
        // cart in the same session doesn't inherit the previous pickup choice.
        $this->pickupContextStorage->clear($salesChannelContext);
    }
}
