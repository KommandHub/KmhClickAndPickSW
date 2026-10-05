<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Storefront\Cookie;

use Shopware\Core\Content\Cookie\Event\CookieGroupCollectEvent;
use Shopware\Core\Content\Cookie\Struct\CookieGroup;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Registers the optional consent group that gates the checkout "Use my location"
 * button. The group is its own cookie: accepting it sets
 * `kmh_pickup_location_geolocation=1`, which the storefront JS plugin reads.
 * Uses the 6.7 event instead of the deprecated CookieProviderInterface.
 */
readonly class PickupLocationGeolocationCookieListener
{
    final public const COOKIE_NAME = 'kmh_pickup_location_geolocation';
    final public const SNIPPET_NAME = 'general.kmh-click-and-pick.cookie.geolocationName';
    final public const SNIPPET_DESCRIPTION = 'general.kmh-click-and-pick.cookie.geolocationDescription';

    #[AsEventListener(event: CookieGroupCollectEvent::class)]
    public function onCollectCookieGroups(CookieGroupCollectEvent $event): void
    {
        $group = new CookieGroup(self::COOKIE_NAME);
        $group->name = self::SNIPPET_NAME;
        $group->description = self::SNIPPET_DESCRIPTION;
        $group->setCookie(self::COOKIE_NAME);
        $group->value = '1';
        $group->expiration = 30;

        $event->cookieGroupCollection->add($group);
    }
}
