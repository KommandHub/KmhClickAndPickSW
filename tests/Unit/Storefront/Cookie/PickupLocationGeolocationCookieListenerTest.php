<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Tests\Unit\Storefront\Cookie;

use Kommandhub\ClickAndPickSW\Storefront\Cookie\PickupLocationGeolocationCookieListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Cookie\Event\CookieGroupCollectEvent;
use Shopware\Core\Content\Cookie\Struct\CookieGroup;
use Shopware\Core\Content\Cookie\Struct\CookieGroupCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(PickupLocationGeolocationCookieListener::class)]
class PickupLocationGeolocationCookieListenerTest extends TestCase
{
    public function testAddsOptionalGeolocationGroup(): void
    {
        $event = $this->createEvent();

        (new PickupLocationGeolocationCookieListener())->onCollectCookieGroups($event);

        static::assertCount(1, $event->cookieGroupCollection);
        $group = $event->cookieGroupCollection->get('kmh_pickup_location_geolocation');
        static::assertInstanceOf(CookieGroup::class, $group);
        static::assertFalse($group->isRequired);
        static::assertSame('general.kmh-click-and-pick.cookie.geolocationName', $group->name);
        static::assertSame('general.kmh-click-and-pick.cookie.geolocationDescription', $group->description);
        static::assertSame('kmh_pickup_location_geolocation', $group->getCookie());
        static::assertSame('1', $group->value);
        static::assertSame(30, $group->expiration);
    }

    public function testKeepsExistingGroupsAndAddsOnlyOnce(): void
    {
        $event = $this->createEvent(new CookieGroupCollection([new CookieGroup('cookie.groupRequired')]));
        $listener = new PickupLocationGeolocationCookieListener();

        $listener->onCollectCookieGroups($event);
        $listener->onCollectCookieGroups($event);

        static::assertCount(2, $event->cookieGroupCollection);
        static::assertNotNull($event->cookieGroupCollection->get('cookie.groupRequired'));
    }

    private function createEvent(?CookieGroupCollection $groups = null): CookieGroupCollectEvent
    {
        return new CookieGroupCollectEvent(
            $groups ?? new CookieGroupCollection(),
            new Request(),
            $this->createMock(SalesChannelContext::class),
        );
    }
}
