const { Component, Module, Application } = Shopware;

import './acl';

import defaultSearchConfiguration from './default-search-configuration';

// Register base form component for pickup locations
Component.register(
    'kmh-pickup-location-base-form',
    () => import('./component/kmh-pickup-location-base-form')
);

// Schedule editor (timezone, weekly opening intervals, special-date overrides)
Component.register(
    'kmh-pickup-location-schedule',
    () => import('./component/kmh-pickup-location-schedule')
);

// Register page components for listing and creating pickup locations
Component.register(
    'kmh-pickup-location-list',
    () => import('./page/kmh-pickup-location-list')
);
Component.register(
    'kmh-pickup-location-create',
    () => import('./page/kmh-pickup-location-create')
);

/**
 * Registers the kmh-pickup-location module in Shopware Administration.
 *
 * Features:
 * - List, create, and edit pickup locations
 * - Navigation entry under Content
 * - Access control via privileges
 *
 * Routes:
 * - index: List all pickup locations
 * - create: Create a new pickup location
 * - edit: Edit an existing pickup location by ID
 */
Module.register('kmh-pickup-location', {
    type: 'plugin',
    name: 'KommandhubPickupLocation',
    title: 'kmh-pickup-location.general.mainMenuItemGeneral',
    description: 'kmh-pickup-location.general.mainMenuItemGeneralDescription',
    version: '1.0.0',
    targetVersion: '1.0.0',
    color: '#ff3d58',
    icon: 'regular-map-marker',
    entity: 'kmh_pickup_location',

    routes: {
        index: {
            components: {
                default: 'kmh-pickup-location-list',
            },
            path: 'index',
            meta: {
                appSystem: {
                    view: 'list',
                },
            },
        },

        create: {
            component: 'kmh-pickup-location-create',
            path: 'create',
            meta: {
                parentPath: 'kmh.pickup.location.index',
            },
        },

        edit: {
            component: 'kmh-pickup-location-create',
            path: 'edit/:id',
            meta: {
                parentPath: 'kmh.pickup.location.index',
            },
            // Pass the pickupLocationId prop to the component based on the route param
            props: {
                default: ($route) => ({
                    pickupLocationId: $route.params.id.toLowerCase(),
                }),
            },
        },
    },

    navigation: [
        {
            id: 'kmh-pickup-location',
            label: 'kmh-pickup-location.general.mainMenuItemGeneral',
            color: '#ff3d58',
            path: 'kmh.pickup.location.index',
            icon: 'regular-map-marker',
            parent: 'sw-content',
            privilege: 'kmh_pickup_location.viewer',
            position: 3,
        },
    ],

    defaultSearchConfiguration
});

Application.addServiceProviderDecorator('searchTypeService', searchTypeService => {
    searchTypeService.upsertType('kmh_pickup_location', {
        entityName: 'kmh_pickup_location',
        placeholderSnippet: 'kmh-pickup-location.general.placeholderSearchBar',
        listingRoute: 'kmh.pickup.location.index',
        hideOnGlobalSearchBar: false,
    });

    return searchTypeService;
});