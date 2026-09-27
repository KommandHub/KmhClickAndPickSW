<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Tests\Unit\PickupLocation;

use Kommandhub\ClickAndPickSW\Checkout\PickupSelection\PickupContextKeys;
use Kommandhub\ClickAndPickSW\PickupLocation\PickupLocationValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Validation\EntityExists;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\DataValidator;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

#[CoversClass(PickupLocationValidator::class)]
class PickupLocationValidatorTest extends TestCase
{
    private const SALES_CHANNEL_ID = '11111111111111111111111111111111';
    private const LOCATION_ID = 'fedcba9876543210fedcba9876543210';

    private DataValidator&MockObject $validator;

    private PickupLocationValidator $pickupLocationValidator;

    protected function setUp(): void
    {
        $this->validator = $this->createMock(DataValidator::class);
        $this->pickupLocationValidator = new PickupLocationValidator($this->validator);
    }

    public function testValidatesActiveLocationInSalesChannel(): void
    {
        $context = $this->salesChannelContext();

        $this->validator
            ->expects(static::once())
            ->method('validate')
            ->with(
                [PickupContextKeys::LOCATION_ID => self::LOCATION_ID],
                static::callback(function (DataValidationDefinition $definition) use ($context): bool {
                    $constraint = $definition->getProperty(PickupContextKeys::LOCATION_ID)[0] ?? null;

                    if (!$constraint instanceof EntityExists) {
                        return false;
                    }

                    $multiFilter = $constraint->getCriteria()->getFilters()[0] ?? null;

                    if (!$multiFilter instanceof MultiFilter || $constraint->getCriteria()->getLimit() !== 1) {
                        return false;
                    }

                    $queries = $multiFilter->getQueries();

                    return $definition->getName() === 'kmh_click_and_pick.context_switch'
                        && $constraint->getEntity() === 'kmh_pickup_location'
                        && $constraint->getContext() === $context->getContext()
                        && $multiFilter->getOperator() === MultiFilter::CONNECTION_AND
                        && $queries[0] instanceof EqualsFilter
                        && $queries[0]->getField() === 'id'
                        && $queries[0]->getValue() === self::LOCATION_ID
                        && $queries[1] instanceof EqualsFilter
                        && $queries[1]->getField() === 'active'
                        && $queries[1]->getValue() === true
                        && $queries[2] instanceof EqualsFilter
                        && $queries[2]->getField() === 'salesChannels.id'
                        && $queries[2]->getValue() === self::SALES_CHANNEL_ID;
                })
            );

        $this->pickupLocationValidator->validate(self::LOCATION_ID, $context);
    }

    public function testPropagatesValidationFailure(): void
    {
        $this->validator->method('validate')->willThrowException(new \RuntimeException('invalid'));

        $this->expectException(\RuntimeException::class);

        $this->pickupLocationValidator->validate(self::LOCATION_ID, $this->salesChannelContext());
    }

    private function salesChannelContext(): SalesChannelContext&MockObject
    {
        $context = $this->getMockBuilder(SalesChannelContext::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getContext', 'getSalesChannelId'])
            ->getMock();

        $context->method('getContext')->willReturn(Context::createDefaultContext());
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);

        return $context;
    }
}
