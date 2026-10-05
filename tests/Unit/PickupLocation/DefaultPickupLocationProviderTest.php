<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Tests\Unit\PickupLocation;

use Kommandhub\ClickAndPickSW\PickupLocation\DefaultPickupLocationProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[CoversClass(DefaultPickupLocationProvider::class)]
class DefaultPickupLocationProviderTest extends TestCase
{
    private const SALES_CHANNEL_ID = 'sales-channel-id';

    public function testReadsSelectionSettingPerSalesChannel(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->expects(static::once())
            ->method('getBool')
            ->with(DefaultPickupLocationProvider::CONFIG_ENABLE_SELECTION, self::SALES_CHANNEL_ID)
            ->willReturn(false);

        $provider = new DefaultPickupLocationProvider($config, $this->createMock(EntityRepository::class));

        static::assertFalse($provider->isSelectionEnabled(self::SALES_CHANNEL_ID));
    }

    public function testFindsActiveAssignedDefaultLocationForTheChannel(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(static::once())
            ->method('searchIds')
            ->with(static::callback(static function (Criteria $criteria): bool {
                $filters = array_map(
                    static fn (EqualsFilter $f): array => [$f->getField(), $f->getValue()],
                    array_filter($criteria->getFilters(), static fn ($f): bool => $f instanceof EqualsFilter)
                );

                return \in_array(['active', true], $filters, true)
                    && \in_array(['salesChannels.id', self::SALES_CHANNEL_ID], $filters, true)
                    && \in_array(['defaultSalesChannelId', self::SALES_CHANNEL_ID], $filters, true)
                    && $criteria->getLimit() === 1
                    && $criteria->getSorting()[0]->getField() === 'name';
            }))
            ->willReturn($this->ids(['abc']));

        $provider = new DefaultPickupLocationProvider($this->createMock(SystemConfigService::class), $repository);

        static::assertSame('abc', $provider->getDefaultLocationId($this->context()));
    }

    public function testReturnsNullWithoutDefaultLocation(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('searchIds')->willReturn($this->ids([]));

        $provider = new DefaultPickupLocationProvider($this->createMock(SystemConfigService::class), $repository);

        static::assertNull($provider->getDefaultLocationId($this->context()));
    }

    /**
     * @param list<string> $ids
     */
    private function ids(array $ids): IdSearchResult
    {
        return new IdSearchResult(
            \count($ids),
            array_map(static fn (string $id): array => ['primaryKey' => $id, 'data' => []], $ids),
            new Criteria(),
            Context::createDefaultContext()
        );
    }

    private function context(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }
}
