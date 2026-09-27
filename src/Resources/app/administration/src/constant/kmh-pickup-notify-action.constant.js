/**
 * Frontend constants for the Click & Pick Flow Builder actions. Each value must
 * match the backend action technical name (…Action::ACTION_NAME).
 */
export const ACTION = Object.freeze({
    PICKUP_NOTIFY_ADMIN: 'action.kmh.pickup.notify_admin',
    PICKUP_NOTIFY_SMS: 'action.kmh.pickup.notify_sms',
});

export const GROUP = 'kmhClickAndPick';

export default { ACTION, GROUP };
