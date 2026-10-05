import template from './kmh-pickup-location-base-form.html.twig';

const { mapPropertyErrors } = Shopware.Component.getComponentHelper();

export default {
    template,

    emits: [
        'sales-channel-change',
    ],

    inject: [
        'repositoryFactory',
    ],

    props: {
        pickupLocation: {
            type: Object,
            required: true,
        },
    },

    computed: {
        ...mapPropertyErrors('pickupLocation', ['name', 'street', 'city', 'email', 'postalCode', 'salesChannelIds', 'defaultSalesChannelId']),

        salesChannelRepository() {
            return this.repositoryFactory.create('sales_channel');
        },
    },

    methods: {
        onSalesChannelChange(salesChannel) {
            this.$emit('sales-channel-change', salesChannel);
        },
    },
};
