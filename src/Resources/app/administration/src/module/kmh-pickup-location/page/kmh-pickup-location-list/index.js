import template from './kmh-pickup-location-list.html.twig';
import './kmh-pickup-location-list.scss';

const { Mixin } = Shopware;
const { Criteria } = Shopware.Data;

export default {
    /**
     * The template for the pickup location list page.
     */
    template,

    /**
     * Injected services from Shopware's dependency injection container.
     */
    inject: [
        'repositoryFactory',
        'stateStyleDataProviderService',
        'acl',
        'filterFactory',
    ],

    /**
     * Mixins used for listing and notification functionality.
     */
    mixins: [
        Mixin.getByName('listing'),
        Mixin.getByName('notification'),
    ],

    /**
     * Sets the page title using Shopware's meta info system.
     */
    metaInfo() {
        return {
            title: this.$createTitle(),
        };
    },

    /**
     * Component data properties.
     */
    data() {
        return {
            isLoading: false,
            pickupLocations: null,
            searchConfigEntity: 'kmh_pickup_location',
            storeKey: 'grid.filter.kmh_pickup_location',
            sortBy: 'createdAt',
            sortDirection: 'DESC',
            term: '',
            total: 0,
            filterCriteria: [],
            activeFilterNumber: 0,
            showDeleteModal: false
        }
    },

    /**
     * Watches for changes in the search/filter criteria and reloads the list.
     */
    watch: {
        defaultCriteria: {
            handler() {
                this.getList();
            },
            deep: true,
        },
    },

    /**
     * Computed properties for columns, repository, and criteria.
     */
    computed: {
        /**
         * Returns the column definitions for the pickup location data grid.
         */
        getPickupLocationColumns() {
            return [
                {
                    property: 'name',
                    label: 'kmh-pickup-location.list.columnName',
                    routerLink: 'kmh.pickup.location.edit',
                    allowResize: true,
                    primary: true,
                    inlineEdit: 'string',
                },
                {
                    property: 'street',
                    label: 'kmh-pickup-location.list.columnStreet',
                    allowResize: true,
                    inlineEdit: 'string',
                },
                {
                    property: 'city',
                    label: 'kmh-pickup-location.list.columnCity',
                    allowResize: true,
                    inlineEdit: 'string',
                },
                {
                    property: 'email',
                    label: 'kmh-pickup-location.list.columnEmail',
                    allowResize: true,
                    inlineEdit: 'string',
                },
                {
                    property: 'active',
                    label: 'kmh-pickup-location.list.columnActive',
                    inlineEdit: 'boolean',
                }
            ];
        },

        /**
         * Returns the repository instance for pickup locations.
         */
        pickupLocationRepository() {
            return this.repositoryFactory.create('kmh_pickup_location');
        },

        /**
         * Returns the criteria object for searching and filtering pickup locations.
         */
        defaultCriteria() {
            const criteria = new Criteria(this.page, this.limit);

            criteria.setTerm(this.term);
            criteria.addAssociation('salesChannels');

            this.sortBy.split(',').forEach((sortBy) => {
                criteria.addSorting(Criteria.sort(sortBy, this.sortDirection));
            });

            this.filterCriteria.forEach((filter) => {
                criteria.addFilter(filter);
            });
            return criteria;
        },

        /**
         * Asset filter for formatting asset URLs.
         */
        assetFilter() {
            return Shopware.Filter.getByName('asset');
        },
    },

    /**
     * Methods for CRUD operations, inline editing, and UI actions.
     */
    methods: {
        /**
         * Opens the delete modal for a specific pickup location.
         * @param {string} id - The ID of the pickup location to delete.
         */
        onDelete(id) {
            this.showDeleteModal = id;
        },

        /**
         * Reloads the list when the language is changed.
         */
        onChangeLanguage() {
            this.getList();
        },

        /**
         * Loads the list of pickup locations with current filters and criteria.
         */
        async getList() {
            this.isLoading = true;

            let criteria = await Shopware.Service('filterService').mergeWithStoredFilters(this.storeKey, this.defaultCriteria);

            criteria = await this.addQueryScores(this.term, criteria);

            this.activeFilterNumber = criteria.filters.length;

            if (!this.entitySearchable) {
                this.isLoading = false;
                this.total = 0;

                return;
            }

            if (this.freshSearchTerm) {
                criteria.resetSorting();
            }

            try {
                const response = await this.pickupLocationRepository.search(criteria);

                this.total = response.total;
                this.orders = response;
                this.isLoading = false;
                this.pickupLocations = response;
            } catch {
                this.isLoading = false;
            }
        },

        /**
         * Handles saving of inline edits for a pickup location.
         * @param {Promise} promise - The save promise.
         * @param {Object} pickupLocation - The pickup location being edited.
         */
        onInlineEditSave(promise, pickupLocation) {
            const pickupLocationName = pickupLocation.name;

            return promise
                .then(() => {
                    this.createNotificationSuccess({
                        message: this.$tc('kmh-pickup-location.list.messageSaveSuccess', { name: pickupLocationName }, 0),
                    });
                })
                .catch(() => {
                    this.getList();
                    this.createNotificationError({
                        message: this.$tc('global.notification.notificationSaveErrorMessageRequiredFieldsInvalid'),
                    });
                });
        },

        /**
         * Cancels inline editing and discards changes.
         * @param {Object} pickupLocation - The pickup location being edited.
         */
        onInlineEditCancel(pickupLocation) {
            pickupLocation.discardChanges();
        },

        /**
         * Updates the total number of pickup locations.
         * @param {Object} param0 - Object containing the total.
         */
        updateTotal({ total }) {
            this.total = total;
        },

        /**
         * Closes the delete confirmation modal.
         */
        onCloseDeleteModal() {
            this.showDeleteModal = false;
        },

        /**
         * Confirms deletion of a pickup location and reloads the list.
         * @param {string} id - The ID of the pickup location to delete.
         */
        onConfirmDelete(id) {
            this.showDeleteModal = false;

            return this.pickupLocationRepository.delete(id).then(() => {
                this.getList();
            });
        },
    }
}