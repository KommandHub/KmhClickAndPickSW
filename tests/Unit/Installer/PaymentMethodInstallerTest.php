<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Tests\Unit\Installer;

use Kommandhub\ClickAndPickSW\Checkout\Payment\PayOnPickupPaymentHandler;
use Kommandhub\ClickAndPickSW\Installer\PaymentMethodInstaller;
use Kommandhub\ClickAndPickSW\KmhClickAndPickSW;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Rule\ShippingMethodRule;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\Framework\Rule\Rule;

#[CoversClass(PaymentMethodInstaller::class)]
class PaymentMethodInstallerTest extends TestCase
{
    private EntityRepository&MockObject $paymentMethodRepository;

    private PluginIdProvider&MockObject $pluginIdProvider;

    private EntityRepository&MockObject $salesChannelRepository;

    private PaymentMethodInstaller $installer;

    private Context $context;

    protected function setUp(): void
    {
        $this->paymentMethodRepository = $this->createMock(EntityRepository::class);
        $this->pluginIdProvider = $this->createMock(PluginIdProvider::class);
        $this->salesChannelRepository = $this->createMock(EntityRepository::class);
        $this->installer = new PaymentMethodInstaller(
            $this->paymentMethodRepository,
            $this->pluginIdProvider,
            $this->salesChannelRepository
        );
        $this->context = Context::createDefaultContext();
    }

    public function testAssignToSalesChannelsLinksMethodToEverySalesChannel(): void
    {
        $this->paymentMethodRepository
            ->expects(static::once())
            ->method('searchIds')
            ->willReturn(IdSearchResult::fromIds(['payment-id'], new Criteria(), $this->context));
        $this->salesChannelRepository
            ->expects(static::once())
            ->method('searchIds')
            ->willReturn(IdSearchResult::fromIds(['sales-channel-1'], new Criteria(), $this->context));
        $this->salesChannelRepository
            ->expects(static::once())
            ->method('update')
            ->with(
                [['id' => 'sales-channel-1', 'paymentMethods' => [['id' => 'payment-id']]]],
                $this->context
            );

        $this->installer->assignToSalesChannels($this->context);
    }

    public function testAssignToSalesChannelsDoesNothingWhenMethodMissing(): void
    {
        $this->paymentMethodRepository
            ->expects(static::exactly(2))
            ->method('searchIds')
            ->willReturnOnConsecutiveCalls(
                IdSearchResult::fromIds([], new Criteria(), $this->context),
                IdSearchResult::fromIds([], new Criteria(), $this->context)
            );
        $this->salesChannelRepository->expects(static::never())->method('update');

        $this->installer->assignToSalesChannels($this->context);
    }

    public function testInstallUpdatesExistingPaymentMethodByTechnicalName(): void
    {
        $this->paymentMethodRepository
            ->expects(static::once())
            ->method('searchIds')
            ->willReturn(IdSearchResult::fromIds(['payment-id'], new Criteria(), $this->context));
        $this->paymentMethodRepository
            ->expects(static::once())
            ->method('update')
            ->with([[
                'id' => 'payment-id',
                'handlerIdentifier' => PayOnPickupPaymentHandler::class,
            ]], $this->context);
        $this->paymentMethodRepository->expects(static::never())->method('create');
        $this->pluginIdProvider->expects(static::never())->method('getPluginIdByBaseClass');

        $this->installer->install(KmhClickAndPickSW::class, $this->context);
    }

    public function testInstallFallsBackToHandlerLookupBeforeCreating(): void
    {
        $this->paymentMethodRepository
            ->expects(static::exactly(2))
            ->method('searchIds')
            ->willReturnOnConsecutiveCalls(
                IdSearchResult::fromIds([], new Criteria(), $this->context),
                IdSearchResult::fromIds(['handler-payment-id'], new Criteria(), $this->context)
            );
        $this->paymentMethodRepository
            ->expects(static::once())
            ->method('update')
            ->with([[
                'id' => 'handler-payment-id',
                'handlerIdentifier' => PayOnPickupPaymentHandler::class,
            ]], $this->context);
        $this->paymentMethodRepository->expects(static::never())->method('create');
        $this->pluginIdProvider->expects(static::never())->method('getPluginIdByBaseClass');

        $this->installer->install(KmhClickAndPickSW::class, $this->context);
    }

    public function testInstallCreatesPaymentMethodWhenMissing(): void
    {
        $this->paymentMethodRepository
            ->expects(static::exactly(2))
            ->method('searchIds')
            ->willReturnOnConsecutiveCalls(
                IdSearchResult::fromIds([], new Criteria(), $this->context),
                IdSearchResult::fromIds([], new Criteria(), $this->context)
            );
        $this->pluginIdProvider
            ->expects(static::once())
            ->method('getPluginIdByBaseClass')
            ->with(KmhClickAndPickSW::class, $this->context)
            ->willReturn('plugin-id');
        $this->paymentMethodRepository
            ->expects(static::once())
            ->method('create')
            ->with(
                static::callback(function (array $payload): bool {
                    $payment = $payload[0] ?? null;
                    $condition = $payment['availabilityRule']['conditions'][0] ?? null;

                    return $payment['id'] === PaymentMethodInstaller::PAYMENT_METHOD_ID
                        && $payment['handlerIdentifier'] === PayOnPickupPaymentHandler::class
                        && $payment['pluginId'] === 'plugin-id'
                        && $payment['technicalName'] === PaymentMethodInstaller::TECHNICAL_NAME
                        && $payment['availabilityRule']['id'] === PaymentMethodInstaller::AVAILABILITY_RULE_ID
                        && $condition['type'] === ShippingMethodRule::RULE_NAME
                        && $condition['value']['operator'] === Rule::OPERATOR_EQ
                        && $condition['value']['shippingMethodIds'] === [KmhClickAndPickSW::SHIPPING_METHOD_ID];
                }),
                $this->context
            );

        $this->installer->install(KmhClickAndPickSW::class, $this->context);
    }

    public function testActivateUpdatesExistingPaymentMethod(): void
    {
        $this->paymentMethodRepository
            ->expects(static::once())
            ->method('searchIds')
            ->willReturn(IdSearchResult::fromIds(['payment-id'], new Criteria(), $this->context));
        $this->paymentMethodRepository
            ->expects(static::once())
            ->method('update')
            ->with([[
                'id' => 'payment-id',
                'active' => true,
            ]], $this->context);

        $this->installer->activate($this->context);
    }

    public function testDeactivateUpdatesExistingPaymentMethod(): void
    {
        $this->paymentMethodRepository
            ->expects(static::once())
            ->method('searchIds')
            ->willReturn(IdSearchResult::fromIds(['payment-id'], new Criteria(), $this->context));
        $this->paymentMethodRepository
            ->expects(static::once())
            ->method('update')
            ->with([[
                'id' => 'payment-id',
                'active' => false,
            ]], $this->context);

        $this->installer->deactivate($this->context);
    }

    public function testDeactivateDoesNothingWhenPaymentMethodIsMissing(): void
    {
        $this->paymentMethodRepository
            ->expects(static::exactly(2))
            ->method('searchIds')
            ->willReturnOnConsecutiveCalls(
                IdSearchResult::fromIds([], new Criteria(), $this->context),
                IdSearchResult::fromIds([], new Criteria(), $this->context)
            );
        $this->paymentMethodRepository->expects(static::never())->method('update');

        $this->installer->deactivate($this->context);
    }
}
