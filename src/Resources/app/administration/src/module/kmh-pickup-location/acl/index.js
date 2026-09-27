Shopware.Service('privileges')
    .addPrivilegeMappingEntry({
        category: 'permissions',
        parent: 'content',
        key: 'kmh_pickup_location',
        roles: {
            viewer: {
                privileges: [
                    'kmh_pickup_location:read',
                ],
                dependencies: [
                    'sales_channel.viewer'
                ]
            },
            editor: {
                privileges: [
                    'kmh_pickup_location:update',
                ],
                dependencies: [
                    'kmh_pickup_location.viewer'
                ]
            },
            creator: {
                privileges: [
                    'kmh_pickup_location:create',
                ],
                dependencies: [
                    'kmh_pickup_location.viewer',
                    'kmh_pickup_location.editor'
                ]
            },
            deleter: {
                privileges: [
                    'kmh_pickup_location:delete',
                ],
                dependencies: [
                    'kmh_pickup_location.viewer'
                ]
            }
        }
    });