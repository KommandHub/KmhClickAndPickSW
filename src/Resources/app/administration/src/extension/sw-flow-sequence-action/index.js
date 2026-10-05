import { ACTION, GROUP } from '../../constant/kmh-pickup-notify-action.constant';

const { Component, Application } = Shopware;

/**
 * Register the Click & Pick actions with the Flow Builder service so their group
 * is known to the flow module (actionGroups is derived from
 * flowBuilderService.getGroups()). Titles/icons/modals are provided by the
 * component override below.
 */
Application.addServiceProviderDecorator('flowBuilderService', (flowBuilderService) => {
    flowBuilderService.addActionNames({
        PICKUP_NOTIFY_ADMIN: ACTION.PICKUP_NOTIFY_ADMIN,
        PICKUP_NOTIFY_SMS: ACTION.PICKUP_NOTIFY_SMS,
    });
    flowBuilderService.addGroups({ KOMMANDHUB_CLICK_AND_PICK: GROUP });
    flowBuilderService.addActionGroupMapping({
        [ACTION.PICKUP_NOTIFY_ADMIN]: GROUP,
        [ACTION.PICKUP_NOTIFY_SMS]: GROUP,
    });

    return flowBuilderService;
});

/**
 * Give the actions their title, icon, group, configuration modal and description
 * in the Flow Builder sequence editor. Everything else falls through to the core
 * implementation via $super. The SMS action has no configuration, so no modal.
 */
Component.override('sw-flow-sequence-action', {
    computed: {
        modalName() {
            if (this.selectedAction === ACTION.PICKUP_NOTIFY_ADMIN) {
                return 'sw-flow-pickup-notify-modal';
            }

            return this.$super('modalName');
        },
    },

    methods: {
        getActionTitle(actionName) {
            if (actionName === ACTION.PICKUP_NOTIFY_ADMIN) {
                return {
                    value: actionName,
                    icon: 'regular-envelope',
                    label: this.$tc('kmh-pickup-notify-action.titleSendNotification'),
                    group: GROUP,
                };
            }

            if (actionName === ACTION.PICKUP_NOTIFY_SMS) {
                return {
                    value: actionName,
                    icon: 'regular-comments',
                    label: this.$tc('kmh-pickup-sms-action.titleSendSms'),
                    group: GROUP,
                };
            }

            return this.$super('getActionTitle', actionName);
        },

        getActionDescriptions(sequence) {
            if (sequence.actionName === ACTION.PICKUP_NOTIFY_ADMIN) {
                return this.$tc('kmh-pickup-notify-action.description');
            }

            if (sequence.actionName === ACTION.PICKUP_NOTIFY_SMS) {
                return this.$tc('kmh-pickup-sms-action.description');
            }

            return this.$super('getActionDescriptions', sequence);
        },
    },
});
