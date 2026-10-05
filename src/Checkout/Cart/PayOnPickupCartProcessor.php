<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Checkout\Cart;

use Kommandhub\ClickAndPickSW\Checkout\Cart\Error\InvalidPickupTimeCartBlockerError;
use Kommandhub\ClickAndPickSW\Checkout\Cart\Error\PickupLocationRequiredCartBlockerError;
use Kommandhub\ClickAndPickSW\Checkout\Cart\Error\PickupTimeRequiredCartBlockerError;
use Kommandhub\ClickAndPickSW\Checkout\Cart\Error\UnsupportedDeliveryMethodCartBlockerError;
use Kommandhub\ClickAndPickSW\Checkout\Payment\PayOnPickupPaymentHandler;
use Kommandhub\ClickAndPickSW\PickupLocation\Availability\PickupTimeSlotService;
use Kommandhub\ClickAndPickSW\PickupLocation\PickupLocationSelectionResolver;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('shopware.cart.validator')]
readonly class PayOnPickupCartProcessor implements CartValidatorInterface
{
    public function __construct(
        private PickupLocationSelectionResolver $pickupLocationSelectionResolver,
        private PickupTimeSlotService $pickupTimeSlotService,
    ) {
    }

    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        $this->validatePayOnPickupDeliveryMethod($errors, $context);
        $this->validatePickupLocationSelection($errors, $context);
    }

    private function validatePayOnPickupDeliveryMethod(
        ErrorCollection $errors,
        SalesChannelContext $context
    ): void {
        $paymentMethod = $context->getPaymentMethod();

        if ($paymentMethod->getHandlerIdentifier() !== PayOnPickupPaymentHandler::class) {
            return;
        }

        $deliveryMethod = $context->getShippingMethod();

        if ($this->pickupLocationSelectionResolver->isPickupShippingMethod($context)) {
            return;
        }

        $deliveryMethodName = $deliveryMethod->getTranslation('name');

        if (!\is_string($deliveryMethodName) || $deliveryMethodName === '') {
            $deliveryMethodName = $deliveryMethod->getName() ?? '';
        }

        $errors->add(new UnsupportedDeliveryMethodCartBlockerError(
            $deliveryMethodName
        ));
    }

    private function validatePickupLocationSelection(
        ErrorCollection $errors,
        SalesChannelContext $context
    ): void {
        if (!$this->pickupLocationSelectionResolver->isPickupShippingMethod($context)) {
            return;
        }

        $selection = $this->pickupLocationSelectionResolver->resolveSelection($context);

        if ($selection === null) {
            $errors->add(new PickupLocationRequiredCartBlockerError());

            return;
        }

        // Server-side pickup-time validation gate: a chosen time must fall inside
        // the location's schedule (its timezone, overrides and future rules).
        if ($selection->pickupTime === null) {
            // The store needs a time to prepare the order; without one it can't.
            $errors->add(new PickupTimeRequiredCartBlockerError());

            return;
        }

        if (!$this->pickupTimeSlotService->isBookable($selection->pickupLocation, $selection->pickupTime)) {
            $errors->add(new InvalidPickupTimeCartBlockerError());
        }
    }
}
