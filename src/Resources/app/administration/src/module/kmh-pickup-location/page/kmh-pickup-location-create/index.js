import template from './kmh-pickup-location-create.html.twig';

const { Mixin } = Shopware;
const { Criteria } = Shopware.Data;

/**
 * Vue component for creating or editing a pickup location in the administration.
 * Handles loading, saving, and updating pickup location data.
 */
export default {
    /**
     * The template for the pickup location create page.
     */
    template,

    // Injected services from Shopware's dependency injection
    inject: [
        'repositoryFactory',
        'systemConfigApiService',
    ],

    // Mixin for notification handling
    mixins: [
        Mixin.getByName('notification'),
    ],

    // Props received from parent component or router
    props: {
        /**
         * The ID of the pickup location to edit.
         * If null, a new pickup location will be created.
         */
        pickupLocationId: {
            type: String,
            required: false,
            default: null,
        },
    },

    // Component local state
    data() {
        return {
            pickupLocation: null,         // The pickup location entity
            isSaveSuccessful: false,      // Indicates if save was successful
            isLoading: false,             // Indicates if loading is in progress
        };
    },

    // Computed properties for repositories and criteria
    computed: {
        /**
         * Repository for pickup location entities.
         */
        pickupLocationRepository() {
            return this.repositoryFactory.create('kmh_pickup_location');
        },

        /**
         * Default criteria for loading pickup locations, including sales channel association.
         */
        defaultCriteria() {
            const criteria = new Criteria(1, 1);
            criteria.addAssociation('salesChannels');
            criteria.addAssociation('defaultSalesChannel');
            criteria.addAssociation('openingHoursSchedule');
            criteria.addAssociation('specialHours');
            return criteria;
        },

        cardLabel() {
            // pickupLocation loads asynchronously; treat "no id yet" as the
            // create case until the entity is available.
            return this.pickupLocation?.isNew?.() ?? !this.pickupLocationId
                ? this.$tc('kmh-pickup-location.create.title')
                : this.$tc('kmh-pickup-location.edit.title');
        }
    },

    // Watchers for reactive property changes
    watch: {
        /**
         * React to changes in pickupLocationId prop.
         * Immediately load or create the pickup location on component creation or prop change.
         */
        pickupLocationId: {
            immediate: true,
            handler() {
                this.createdComponent();
            },
        },
    },

    // Methods for component logic
    methods: {
        /**
         * Called on component creation or when pickupLocationId changes.
         * Loads existing pickup location or creates a new one.
         */
        async createdComponent() {
            if (!this.pickupLocationId) {
                // Create a new pickup location entity
                this.pickupLocation = this.pickupLocationRepository.create();
            } else {
                // Load existing pickup location
                await this.loadPickupLocation();
            }
        },

        /**
         * Called after a successful save to reset state and navigate to edit page.
         */
        saveFinish() {
            this.isSaveSuccessful = false;
            if (this.pickupLocation && this.pickupLocation.id) {
                this.$router.push({
                    name: 'kmh.pickup.location.edit',
                    params: { id: this.pickupLocation.id },
                });
            }
        },

        /**
         * Saves the current pickup location entity.
         * Shows notification on error.
         * @returns {Promise<Object>} The saved entity response.
         */
        async onSave() {
            this.isLoading = true;
            this.isSaveSuccessful = false;

            const context = Shopware.Context.api;

            try {
                const response = await this.pickupLocationRepository.save(this.pickupLocation, context);
                this.isLoading = false;
                this.isSaveSuccessful = true;
                return response;
            } catch (exception) {
                this.createNotificationError({
                    message: this.$tc('kmh-pickup-location.general.messageSaveError'),
                });
                this.isLoading = false;
                throw exception;
            }
        },

        /**
         * Loads the pickup location entity by ID.
         * Sets loading state during the operation.
         */
        async loadPickupLocation() {
            this.isLoading = true;
            try {
                this.pickupLocation = await this.pickupLocationRepository.get(
                    this.pickupLocationId,
                    Shopware.Context.api,
                    this.defaultCriteria
                );
            } finally {
                this.isLoading = false;
            }
        },

        /**
         * Updates the sales channel ID of the pickup location.
         * @param {Object} salesChannels - The new sales channel ID.
         */
        onChangeSalesChannel(salesChannels) {
            if (this.pickupLocation) {
                this.pickupLocation.salesChannels = salesChannels;
            }
        },
    },
}