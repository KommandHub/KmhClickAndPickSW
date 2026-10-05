import template from './sw-order-detail.html.twig';

const { Component } = Shopware;

/**
 * Adds the "Pickup information" tab item to the order detail tab bar. Only the
 * template is overridden — the tab item is injected into the core
 * `sw_order_detail_content_tabs_extension` block, everything else falls through
 * to the core component. The matching route is registered by this module's
 * routeMiddleware (see ../../index.js).
 */
Component.override('sw-order-detail', {
    template,
});
