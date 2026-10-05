<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\PickupLocation;

use Kommandhub\ClickAndPickSW\Checkout\PickupSelection\PickupContextKeys;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Validation\EntityExists;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\DataValidator;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Validates that a pickup-location id refers to an active location assigned to
 * the current sales channel. Throws a
 * {@see \Shopware\Core\Framework\Validation\Exception\ConstraintViolationException}
 * on failure, so an attempt to select a stale, foreign or inactive location
 * aborts the context switch rather than silently persisting a bad selection.
 */
readonly class PickupLocationValidator
{
    public function __construct(
        private DataValidator $validator,
    ) {
    }

    public function validate(string $pickupLocationId, SalesChannelContext $context): void
    {
        $definition = new DataValidationDefinition('kmh_click_and_pick.context_switch');
        $definition->add(
            PickupContextKeys::LOCATION_ID,
            new EntityExists([
                'entity' => PickupLocationDefinition::ENTITY_NAME,
                'context' => $context->getContext(),
                'criteria' => $this->createCriteria($pickupLocationId, $context->getSalesChannelId()),
            ])
        );

        $this->validator->validate([PickupContextKeys::LOCATION_ID => $pickupLocationId], $definition);
    }

    private function createCriteria(string $pickupLocationId, string $salesChannelId): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_AND, [
            new EqualsFilter('id', $pickupLocationId),
            new EqualsFilter('active', true),
            new EqualsFilter('salesChannels.id', $salesChannelId),
        ]));
        $criteria->setLimit(1);

        return $criteria;
    }
}
