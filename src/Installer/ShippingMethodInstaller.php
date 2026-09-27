<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Installer;

use Kommandhub\ClickAndPickSW\KmhClickAndPickSW;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\DeliveryTime\DeliveryTimeEntity;

/**
 * Creates and maintains the "Self pick-up" shipping method (a free, self-service
 * delivery method). Idempotent: the method and its delivery time use fixed ids,
 * so re-running on every install/update is a no-op once they exist.
 */
readonly class ShippingMethodInstaller
{
    public const TECHNICAL_NAME = 'kmh_self_pickup';

    private const DELIVERY_TIME_ID = '2dcbf55b7c2a65548e8c3e7ea821f1b4';

    public function __construct(
        private EntityRepository $shippingMethodRepository,
        private EntityRepository $deliveryTimeRepository,
        private EntityRepository $ruleRepository,
    ) {
    }

    /**
     * @throws \Exception
     */
    public function install(Context $context): void
    {
        // Already present (existing install or update): leave it untouched —
        // historical orders reference it and merchants may have customised it.
        if ($this->shippingMethodExists($context)) {
            return;
        }

        $this->shippingMethodRepository->create([
            [
                'id' => KmhClickAndPickSW::SHIPPING_METHOD_ID,
                'active' => true,
                'technicalName' => self::TECHNICAL_NAME,
                'name' => 'Self pick-up',
                'description' => 'Pick up your order yourself from the selected location.',
                'deliveryTimeId' => $this->ensureDeliveryTime($context),
                'availabilityRuleId' => $this->getAllCustomersRuleId($context),
                'prices' => [
                    [
                        'calculation' => 1,
                        'quantityStart' => 0,
                        'currencyPrice' => [
                            [
                                'currencyId' => Defaults::CURRENCY,
                                'gross' => 0,
                                'net' => 0,
                                'linked' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ], $context);
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
        if (!$this->shippingMethodExists($context)) {
            return;
        }

        $this->shippingMethodRepository->update([
            [
                'id' => KmhClickAndPickSW::SHIPPING_METHOD_ID,
                'active' => $active,
            ],
        ], $context);
    }

    private function shippingMethodExists(Context $context): bool
    {
        return $this->shippingMethodRepository
            ->searchIds(new Criteria([KmhClickAndPickSW::SHIPPING_METHOD_ID]), $context)
            ->firstId() !== null;
    }

    /**
     * Ensure a dedicated pickup delivery time exists and return its id. Using a
     * fixed id keeps the shipping method bound to a deterministic, pickup-suited
     * delivery time (15-30 minutes) rather than whatever row happens to sort
     * first in the delivery_time table. Idempotent.
     */
    private function ensureDeliveryTime(Context $context): string
    {
        $existingId = $this->deliveryTimeRepository
            ->searchIds(new Criteria([self::DELIVERY_TIME_ID]), $context)
            ->firstId();

        if ($existingId === null) {
            $this->deliveryTimeRepository->create([
                [
                    'id' => self::DELIVERY_TIME_ID,
                    'name' => '15-30 minutes',
                    'min' => 1,
                    'max' => 1,
                    'unit' => DeliveryTimeEntity::DELIVERY_TIME_DAY,
                ],
            ], $context);
        }

        return self::DELIVERY_TIME_ID;
    }

    private function getAllCustomersRuleId(Context $context): ?string
    {
        return $this->ruleRepository
            ->searchIds(
                (new Criteria())->addFilter(new EqualsFilter('name', 'All customers'))->setLimit(1),
                $context
            )
            ->firstId();
    }
}
