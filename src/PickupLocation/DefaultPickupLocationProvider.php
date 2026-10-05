<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\PickupLocation;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Decides whether customers may choose a pickup location in a sales channel,
 * and which location is used when they may not: the active location assigned
 * to the channel whose Default Sales Channel is that channel (first by name).
 */
readonly class DefaultPickupLocationProvider
{
    final public const CONFIG_ENABLE_SELECTION = 'KmhClickAndPickSW.config.enablePickupLocationSelection';

    public function __construct(
        private SystemConfigService $systemConfigService,
        private EntityRepository $kmhPickupLocationRepository,
    ) {
    }

    public function isSelectionEnabled(string $salesChannelId): bool
    {
        return $this->systemConfigService->getBool(self::CONFIG_ENABLE_SELECTION, $salesChannelId);
    }

    public function getDefaultLocationId(SalesChannelContext $context): ?string
    {
        $salesChannelId = $context->getSalesChannelId();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new EqualsFilter('salesChannels.id', $salesChannelId));
        $criteria->addFilter(new EqualsFilter('defaultSalesChannelId', $salesChannelId));
        $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));
        $criteria->setLimit(1);

        $id = $this->kmhPickupLocationRepository->searchIds($criteria, $context->getContext())->firstId();

        return \is_string($id) ? $id : null;
    }
}
