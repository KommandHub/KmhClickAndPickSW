<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Installer;

use Kommandhub\ClickAndPickSW\Checkout\Payment\PayOnPickupPaymentHandler;
use Kommandhub\ClickAndPickSW\KmhClickAndPickSW;
use Shopware\Core\Checkout\Cart\Rule\ShippingMethodRule;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\Framework\Rule\Rule;

/**
 * Creates and maintains the "Pay on pickup" payment method and its availability
 * rule (restricting it to the self-pickup shipping method). Idempotent: safe to
 * run on every install and update.
 */
readonly class PaymentMethodInstaller
{
    public const PAYMENT_METHOD_ID = 'c76322cc1bff7828011f266d9b47f559';
    public const AVAILABILITY_RULE_ID = 'cde3be3c21d76fae830b2e815a6d82ef';
    public const TECHNICAL_NAME = 'kmh_pay_on_pickup';

    /**
     * @param EntityRepository<PaymentMethodCollection> $paymentMethodRepository
     */
    public function __construct(
        private EntityRepository $paymentMethodRepository,
        private PluginIdProvider $pluginIdProvider,
        private EntityRepository $salesChannelRepository,
    ) {
    }

    /**
     * @param string $pluginClass The plugin base class (for the plugin id lookup)
     */
    public function install(string $pluginClass, Context $context): void
    {
        $paymentId = $this->getPaymentMethodId($context);

        // Already present (existing install or update): keep the record and any
        // merchant edits to its availability rule, only re-point the handler so a
        // moved handler class does not break checkout.
        if ($paymentId !== null) {
            $this->paymentMethodRepository->update([
                [
                    'id' => $paymentId,
                    'handlerIdentifier' => PayOnPickupPaymentHandler::class,
                ],
            ], $context);

            return;
        }

        $pluginId = $this->pluginIdProvider->getPluginIdByBaseClass($pluginClass, $context);

        $this->paymentMethodRepository->create([
            [
                'id' => self::PAYMENT_METHOD_ID,
                'handlerIdentifier' => PayOnPickupPaymentHandler::class,
                'name' => 'Pay on pickup',
                'description' => 'Pay for your order when you pick it up at the store.',
                'pluginId' => $pluginId,
                'afterOrderEnabled' => true,
                'technicalName' => self::TECHNICAL_NAME,
                'availabilityRule' => [
                    'id' => self::AVAILABILITY_RULE_ID,
                    'name' => 'Payment method is self pick-up',
                    'priority' => 100,
                    'conditions' => [
                        [
                            'type' => ShippingMethodRule::RULE_NAME,
                            'value' => [
                                'operator' => Rule::OPERATOR_EQ,
                                'shippingMethodIds' => [KmhClickAndPickSW::SHIPPING_METHOD_ID],
                            ],
                        ],
                    ],
                ],
            ],
        ], $context);
    }

    /**
     * Link the payment method to every sales channel so it is actually offered —
     * an active method not attached to a channel is never selectable (this was the
     * case for "Pay on pickup"). The DAL many-to-many upsert makes re-adding an
     * existing mapping a no-op.
     */
    public function assignToSalesChannels(Context $context): void
    {
        $paymentId = $this->getPaymentMethodId($context);

        if ($paymentId === null) {
            return;
        }

        $salesChannelIds = $this->salesChannelRepository->searchIds(new Criteria(), $context)->getIds();

        $payload = array_map(
            static fn (string $salesChannelId): array => [
                'id' => $salesChannelId,
                'paymentMethods' => [['id' => $paymentId]],
            ],
            $salesChannelIds
        );

        if ($payload !== []) {
            $this->salesChannelRepository->update($payload, $context);
        }
    }

    public function activate(Context $context): void
    {
        $this->setActive(true, $context);
    }

    public function deactivate(Context $context): void
    {
        $this->setActive(false, $context);
    }

    private function setActive(bool $active, Context $context): void
    {
        $paymentId = $this->getPaymentMethodId($context);

        if ($paymentId === null) {
            return;
        }

        $this->paymentMethodRepository->update([
            [
                'id' => $paymentId,
                'active' => $active,
            ],
        ], $context);
    }

    private function getPaymentMethodId(Context $context): ?string
    {
        $byTechnicalName = $this->paymentMethodRepository
            ->searchIds(
                (new Criteria())->addFilter(new EqualsFilter('technicalName', self::TECHNICAL_NAME)),
                $context
            )
            ->firstId();

        if ($byTechnicalName !== null) {
            return $byTechnicalName;
        }

        return $this->paymentMethodRepository
            ->searchIds(
                (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', PayOnPickupPaymentHandler::class)),
                $context
            )
            ->firstId();
    }
}
