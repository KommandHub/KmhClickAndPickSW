import template from './kmh-order-pickup-info-tab.html.twig';
import './kmh-order-pickup-info-tab.scss';
import { derivePickupView } from '../../pickup-view';

const { Criteria } = Shopware.Data;

/**
 * Read-only "Pickup information" tab on the order detail page. Loads the order's
 * {@link kmh_order_pickup_location} record (the pickup data architecture's
 * single source of truth) through its own entity — never order custom fields.
 *
 * The linked pickup location is loaded via association; when it has been deleted
 * the FK is null (ON DELETE SET NULL) yet the historical appointment data stored
 * with the order (pickup time + customer note) is still shown.
 */
export default {
    template,

    inject: ['repositoryFactory'],

    props: {
        orderId: {
            type: String,
            required: true,
        },
    },

    data() {
        return {
            pickupRecord: null,
            isLoading: true,
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('kmh_order_pickup_location');
        },

        pickupView() {
            return derivePickupView(this.pickupRecord);
        },

        dateFilter() {
            return Shopware.Filter.getByName('date');
        },
    },

    created() {
        this.loadPickupData();
    },

    watch: {
        orderId() {
            this.loadPickupData();
        },
    },

    methods: {
        async loadPickupData() {
            this.isLoading = true;

            try {
                const criteria = new Criteria(1, 1);
                criteria.addFilter(Criteria.equals('orderId', this.orderId));
                criteria.addAssociation('pickupLocation');

                const result = await this.repository.search(criteria, Shopware.Context.api);
                this.pickupRecord = result.first() ?? null;
            } finally {
                this.isLoading = false;
            }
        },

        formatDateTime(value, timeZone) {
            if (!value) {
                return '';
            }

            return this.dateFilter(value, {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                // Pickup time belongs to the location's timezone, not the admin
                // user's. Falls back to the user tz when the location was deleted.
                ...(timeZone ? { timeZone } : {}),
            });
        },
    },
};
