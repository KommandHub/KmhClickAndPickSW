<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Tests\Unit\Installer;

use Kommandhub\ClickAndPickSW\Installer\ShippingMethodInstaller;
use Kommandhub\ClickAndPickSW\KmhClickAndPickSW;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;

#[CoversClass(ShippingMethodInstaller::class)]
class ShippingMethodInstallerTest extends TestCase
{
    private const DELIVERY_TIME_ID = '2dcbf55b7c2a65548e8c3e7ea821f1b4';

    private EntityRepository&MockObject $shippingMethodRepository;

    private EntityRepository&MockObject $deliveryTimeRepository;

    private EntityRepository&MockObject $ruleRepository;

    private EntityRepository&MockObject $salesChannelRepository;

    private ShippingMethodInstaller $installer;

    private Context $context;

    protected function setUp(): void
    {
        $this->shippingMethodRepository = $this->createMock(EntityRepository::class);
        $this->deliveryTimeRepository = $this->createMock(EntityRepository::class);
        $this->ruleRepository = $this->createMock(EntityRepository::class);
        $this->salesChannelRepository = $this->createMock(EntityRepository::class);
        $this->installer = new ShippingMethodInstaller(
            $this->shippingMethodRepository,
            $this->deliveryTimeRepository,
            $this->ruleRepository,
            $this->salesChannelRepository
        );
        $this->context = Context::createDefaultContext();
    }

    public function testAssignToSalesChannelsLinksMethodToEverySalesChannel(): void
    {
        $this->shippingMethodRepository
            ->expects(static::once())
            ->method('searchIds')
            ->willReturn(IdSearchResult::fromIds([KmhClickAndPickSW::SHIPPING_METHOD_ID], new Criteria(), $this->context));
        $this->salesChannelRepository
            ->expects(static::once())
            ->method('searchIds')
            ->willReturn(IdSearchResult::fromIds(['sales-channel-1', 'sales-channel-2'], new Criteria(), $this->context));
        $this->salesChannelRepository
            ->expects(static::once())
            ->method('update')
            ->with(
                [
                    ['id' => 'sales-channel-1', 'shippingMethods' => [['id' => KmhClickAndPickSW::SHIPPING_METHOD_ID]]],
                    ['id' => 'sales-channel-2', 'shippingMethods' => [['id' => KmhClickAndPickSW::SHIPPING_METHOD_ID]]],
                ],
                $this->context
            );

        $this->installer->assignToSalesChannels($this->context);
    }

    public function testAssignToSalesChannelsDoesNothingWhenMethodMissing(): void
    {
        $this->shippingMethodRepository
            ->expects(static::once())
            ->method('searchIds')
            ->willReturn(IdSearchResult::fromIds([], new Criteria(), $this->context));
        $this->salesChannelRepository->expects(static::never())->method('update');

        $this->installer->assignToSalesChannels($this->context);
    }

    public function testInstallDoesNothingWhenShippingMethodAlreadyExists(): void
    {
        $this->shippingMethodRepository
            ->expects(static::once())
            ->method('searchIds')
            ->willReturn(IdSearchResult::fromIds([KmhClickAndPickSW::SHIPPING_METHOD_ID], new Criteria(), $this->context));
        $this->shippingMethodRepository->expects(static::never())->method('create');

        $this->installer->install($this->context);
    }

    public function testInstallCreatesShippingMethodWithFixedDeliveryTimeWhenMissing(): void
    {
        $this->shippingMethodRepository
            ->expects(static::once())
            ->method('searchIds')
            ->willReturn(IdSearchResult::fromIds([], new Criteria(), $this->context));
        // Dedicated pickup delivery time already present → reused, not recreated.
        $this->deliveryTimeRepository
            ->expects(static::once())
            ->method('searchIds')
            ->willReturn(IdSearchResult::fromIds([self::DELIVERY_TIME_ID], new Criteria(), $this->context));
        $this->deliveryTimeRepository->expects(static::never())->method('create');
        $this->ruleRepository
            ->expects(static::once())
            ->method('searchIds')
            ->willReturn(IdSearchResult::fromIds(['all-customers-rule-id'], new Criteria(), $this->context));
        $this->shippingMethodRepository
            ->expects(static::once())
            ->method('create')
            ->with(
                static::callback(function (array $payload): bool {
                    $shippingMethod = $payload[0] ?? null;
                    $price = $shippingMethod['prices'][0]['currencyPrice'][0] ?? null;

                    return $shippingMethod['id'] === KmhClickAndPickSW::SHIPPING_METHOD_ID
                        && $shippingMethod['technicalName'] === ShippingMethodInstaller::TECHNICAL_NAME
                        && $shippingMethod['deliveryTimeId'] === self::DELIVERY_TIME_ID
                        && $shippingMethod['availabilityRuleId'] === 'all-customers-rule-id'
                        && $price['currencyId'] === Defaults::CURRENCY
                        && $price['gross'] === 0
                        && $price['net'] === 0;
                }),
                $this->context
            );

        $this->installer->install($this->context);
    }

    public function testInstallCreatesDedicatedDeliveryTimeWhenMissing(): void
    {
        $this->shippingMethodRepository
            ->method('searchIds')
            ->willReturn(IdSearchResult::fromIds([], new Criteria(), $this->context));
        $this->deliveryTimeRepository
            ->method('searchIds')
            ->willReturn(IdSearchResult::fromIds([], new Criteria(), $this->context));
        $this->ruleRepository
            ->method('searchIds')
            ->willReturn(IdSearchResult::fromIds([], new Criteria(), $this->context));

        // Missing → the installer creates its own pickup delivery time (no throw).
        $this->deliveryTimeRepository
            ->expects(static::once())
            ->method('create')
            ->with(
                static::callback(static function (array $payload): bool {
                    $deliveryTime = $payload[0] ?? null;

                    return $deliveryTime['id'] === self::DELIVERY_TIME_ID
                        && $deliveryTime['name'] === '15-30 minutes';
                }),
                $this->context
            );
        $this->shippingMethodRepository->expects(static::once())->method('create');

        $this->installer->install($this->context);
    }

    public function testActivateUpdatesExistingShippingMethod(): void
    {
        $this->shippingMethodRepository
            ->expects(static::once())
            ->method('searchIds')
            ->willReturn(IdSearchResult::fromIds([KmhClickAndPickSW::SHIPPING_METHOD_ID], new Criteria(), $this->context));
        $this->shippingMethodRepository
            ->expects(static::once())
            ->method('update')
            ->with([[
                'id' => KmhClickAndPickSW::SHIPPING_METHOD_ID,
                'active' => true,
            ]], $this->context);

        $this->installer->activate($this->context);
    }

    public function testDeactivateUpdatesExistingShippingMethod(): void
    {
        $this->shippingMethodRepository
            ->expects(static::once())
            ->method('searchIds')
            ->willReturn(IdSearchResult::fromIds([KmhClickAndPickSW::SHIPPING_METHOD_ID], new Criteria(), $this->context));
        $this->shippingMethodRepository
            ->expects(static::once())
            ->method('update')
            ->with([[
                'id' => KmhClickAndPickSW::SHIPPING_METHOD_ID,
                'active' => false,
            ]], $this->context);

        $this->installer->deactivate($this->context);
    }

    public function testDeactivateDoesNothingWhenShippingMethodIsMissing(): void
    {
        $this->shippingMethodRepository
            ->expects(static::once())
            ->method('searchIds')
            ->willReturn(IdSearchResult::fromIds([], new Criteria(), $this->context));
        $this->shippingMethodRepository->expects(static::never())->method('update');

        $this->installer->deactivate($this->context);
    }
}
