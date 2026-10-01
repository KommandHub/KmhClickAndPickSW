<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW;

use Doctrine\DBAL\Connection;
use Kommandhub\ClickAndPickSW\Entity\OrderPickupLocation\OrderPickupLocationDefinition;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\Aggregate\PickupLocationOpeningHour\PickupLocationOpeningHourDefinition;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\Aggregate\PickupLocationSalesChannelMapping\PickupLocationSalesChannelMappingDefinition;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\Aggregate\PickupLocationSpecialHour\PickupLocationSpecialHourDefinition;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationDefinition;
use Kommandhub\ClickAndPickSW\Installer\PaymentMethodInstaller;
use Kommandhub\ClickAndPickSW\Installer\ShippingMethodInstaller;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;

class KmhClickAndPickSW extends Plugin
{
    final public const SHIPPING_METHOD_ID = '25b9d4e415428362abb32d0a7cba2a38';
    final public const STATE_READY_FOR_PICKUP_ID = '4c91ff4dbeda28ae3d001663d4638f21';

    /**
     * config.xml default-values are only shown in the admin form; they are not
     * returned by SystemConfigService::get() until saved. Persist them on install
     * so the storefront behaves as documented out of the box (e.g. the pickup
     * location selector renders).
     */
    private const CONFIG_DEFAULTS = [
        'enablePickupLocationSelection' => true,
        'showStreetNameInPickupLocationSelectionField' => false,
        'showContactDetailInPickupLocationInfo' => true,
    ];

    public function install(InstallContext $installContext): void
    {
        $this->installEntities($installContext->getContext());
        $this->ensureConfigDefaults();
    }

    /**
     * Re-run the installers on update so the payment/shipping methods are
     * migrated when their classes or ids move between versions. Each installer is
     * idempotent, so this is a no-op for an unchanged install.
     */
    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);

        $this->installEntities($updateContext->getContext());
        $this->ensureConfigDefaults();
    }

    public function activate(ActivateContext $activateContext): void
    {
        $context = $activateContext->getContext();

        $this->getPaymentMethodInstaller()->activate($context);
        $this->getShippingMethodInstaller()->activate($context);

        // Make the methods selectable: a payment/shipping method must be linked to
        // a sales channel to be offered. Idempotent (adds the mapping if missing).
        $this->getPaymentMethodInstaller()->assignToSalesChannels($context);
        $this->getShippingMethodInstaller()->assignToSalesChannels($context);

        parent::activate($activateContext);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        $context = $deactivateContext->getContext();

        $this->getPaymentMethodInstaller()->deactivate($context);
        $this->getShippingMethodInstaller()->deactivate($context);

        parent::deactivate($deactivateContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        $context = $uninstallContext->getContext();

        // Never delete the payment/shipping methods: historical orders reference
        // them and removal would break those orders. Deactivate instead.
        $this->getPaymentMethodInstaller()->deactivate($context);
        $this->getShippingMethodInstaller()->deactivate($context);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        // Drop child tables before the parent so foreign-key constraints don't
        // block the removal. The order pickup record, schedule aggregates and the
        // sales-channel mapping all reference kmh_pickup_location.
        $tables = [
            OrderPickupLocationDefinition::ENTITY_NAME,
            PickupLocationOpeningHourDefinition::ENTITY_NAME,
            PickupLocationSpecialHourDefinition::ENTITY_NAME,
            PickupLocationSalesChannelMappingDefinition::ENTITY_NAME,
            PickupLocationDefinition::ENTITY_NAME,
        ];

        $connection = $this->requireContainer()->get(Connection::class);

        if (!$connection instanceof Connection) {
            throw new \RuntimeException('Database connection service is not available.');
        }

        foreach ($tables as $table) {
            $connection->executeStatement("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    private function requireContainer(): ContainerInterface
    {
        if ($this->container === null) {
            throw new \RuntimeException('Container is not available.'); // @codeCoverageIgnore
        }

        return $this->container;
    }

    private function installEntities(Context $context): void
    {
        $this->getPaymentMethodInstaller()->install(static::class, $context);
        $this->getShippingMethodInstaller()->install($context);

        $this->getPaymentMethodInstaller()->assignToSalesChannels($context);
        $this->getShippingMethodInstaller()->assignToSalesChannels($context);
    }

    private function ensureConfigDefaults(): void
    {
        $systemConfigService = $this->requireContainer()->get(SystemConfigService::class);

        if (!$systemConfigService instanceof SystemConfigService) {
            return; // @codeCoverageIgnore
        }

        foreach (self::CONFIG_DEFAULTS as $key => $default) {
            $domainKey = 'KmhClickAndPickSW.config.' . $key;

            // Only seed untouched keys so a merchant's saved choice survives updates.
            if ($systemConfigService->get($domainKey) === null) {
                $systemConfigService->set($domainKey, $default);
            }
        }
    }

    private function getPaymentMethodInstaller(): PaymentMethodInstaller
    {
        $container = $this->requireContainer();

        /** @var EntityRepository<PaymentMethodCollection> $paymentMethodRepository */
        $paymentMethodRepository = $container->get('payment_method.repository');

        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $container->get(PluginIdProvider::class);

        /** @var EntityRepository $salesChannelRepository */
        $salesChannelRepository = $container->get('sales_channel.repository');

        return new PaymentMethodInstaller($paymentMethodRepository, $pluginIdProvider, $salesChannelRepository);
    }

    private function getShippingMethodInstaller(): ShippingMethodInstaller
    {
        $container = $this->requireContainer();

        /** @var EntityRepository $shippingMethodRepository */
        $shippingMethodRepository = $container->get('shipping_method.repository');

        /** @var EntityRepository $deliveryTimeRepository */
        $deliveryTimeRepository = $container->get('delivery_time.repository');

        /** @var EntityRepository $ruleRepository */
        $ruleRepository = $container->get('rule.repository');

        /** @var EntityRepository $salesChannelRepository */
        $salesChannelRepository = $container->get('sales_channel.repository');

        return new ShippingMethodInstaller(
            $shippingMethodRepository,
            $deliveryTimeRepository,
            $ruleRepository,
            $salesChannelRepository
        );
    }
}
