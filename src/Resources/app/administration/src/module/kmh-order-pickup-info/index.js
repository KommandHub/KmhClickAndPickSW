import './extension/sw-order-detail';

const { Component, Module } = Shopware;

Component.register(
    'kmh-order-pickup-info-tab',
    () => import('./component/kmh-order-pickup-info-tab')
);

/**
 * Registers the "Pickup information" order-detail tab.
 *
 * A tab on an existing module cannot be declared as a static route, so the child
 * route is injected into the core `sw.order.detail` route via `routeMiddleware`
 * — Shopware runs every module's middleware against every route while building
 * the router (module.factory getModuleRoutes → middlewareHelper.go). The tab
 * item itself is added by the sw-order-detail template override.
 */
Module.register('kmh-order-pickup-info', {
    type: 'plugin',
    name: 'KommandhubOrderPickupInfo',
    title: 'kmh-order-pickup-info.tab.title',

    routeMiddleware(next, currentRoute) {
        if (currentRoute.name === 'sw.order.detail') {
            const alreadyRegistered = currentRoute.children.some(
                (child) => child.name === 'sw.order.detail.pickup'
            );

            if (!alreadyRegistered) {
                currentRoute.children.push({
                    name: 'sw.order.detail.pickup',
                    path: '/sw/order/detail/:id/pickup',
                    component: 'kmh-order-pickup-info-tab',
                    meta: {
                        parentPath: 'sw.order.index',
                        privilege: 'order.viewer',
                    },
                });
            }
        }

        next(currentRoute);
    },
});
