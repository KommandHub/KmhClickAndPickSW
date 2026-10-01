const { PluginBaseClass } = window;

export default class SalesChannelPickupLocationPlugin extends PluginBaseClass {
    /**
     * Plugin for fetching and populating pickup locations in a select field.
     */
    static options = {
        /**
         * The URL to fetch pickup locations from.
         * @type {string|null}
         */
        url: null,

        /**
         * CSS selector for the select element to populate.
         * @type {string}
         */
        selectorSelectOptions: '#pickup-location-location-field-id',

        /**
         * The currently selected pickup location ID.
         * @type {string|null}
         */
        selectedPickupLocationId: null,

        /**
         * Whether the "Use my location" button may be offered (plugin config
         * `enableGeolocationSorting` for the sales channel).
         * @type {boolean}
         */
        geolocationEnabled: false,

        /**
         * Consent cookie set when the customer accepts the geolocation group.
         * @type {string}
         */
        geolocationCookieName: 'kmh_pickup_location_geolocation',

        /**
         * @type {string}
         */
        selectorGeolocationButton: '[data-pickup-location-geolocation]',

        /**
         * @type {string}
         */
        selectorGeolocationNotice: '[data-pickup-location-geolocation-notice]',
    };

    /**
     * Initializes the plugin by fetching pickup locations.
     */
    init() {
        this._fetchPickupLocations();
        this._registerEvents();
        this._initGeolocation();
    }

    /**
     * Wires the "Use my location" button. It is only shown with config enabled,
     * browser support and an accepted consent cookie, and re-evaluated when the
     * customer changes their cookie settings without a reload.
     * @private
     */
    _initGeolocation() {
        this._geolocationButton = this.el.querySelector(this.options.selectorGeolocationButton);
        this._geolocationNotice = this.el.querySelector(this.options.selectorGeolocationNotice);

        if (!this.options.geolocationEnabled || !this._geolocationButton || !('geolocation' in navigator)) {
            return;
        }

        this._geolocationButton.addEventListener('click', this._onUseMyLocation.bind(this));
        document.$emitter.subscribe('CookieConfiguration_Update', this._toggleGeolocationButton.bind(this));
        this._toggleGeolocationButton();
    }

    /**
     * @private
     */
    _toggleGeolocationButton() {
        this._geolocationButton.hidden = !this._hasGeolocationConsent();
    }

    /**
     * @returns {boolean}
     * @private
     */
    _hasGeolocationConsent() {
        return document.cookie.split('; ').includes(`${this.options.geolocationCookieName}=1`);
    }

    /**
     * Asks the browser for the position (only ever on this click) and reloads
     * the options nearest-first. Coordinates are rounded to 2 decimals (~1.1 km)
     * and live only in this call — never stored.
     * @private
     */
    _onUseMyLocation() {
        if (!this._hasGeolocationConsent()) {
            this._toggleGeolocationButton();
            return;
        }

        if (this._geolocationNotice) {
            this._geolocationNotice.hidden = true;
        }

        navigator.geolocation.getCurrentPosition(
            ({ coords }) => this._fetchPickupLocations({
                lat: coords.latitude.toFixed(2),
                lon: coords.longitude.toFixed(2),
            }),
            () => {
                if (this._geolocationNotice) {
                    this._geolocationNotice.hidden = false;
                }
            },
            { timeout: 10000, maximumAge: 300000 }
        );
    }

    /**
     * Registers events for the plugin.
     * @private
     */
    _registerEvents() {
        const select = this.el.querySelector(this.options.selectorSelectOptions);
        if (select) {
            select.addEventListener('change', this._onLocationChange.bind(this));
        }
    }

    /**
     * Handles the change event of the pickup location select field.
     * Persists the selected location via AJAX.
     *
     * @param {Event} event
     * @private
     */
    _onLocationChange(event) {
        const selectedLocationId = event.target.value;

        // Persist the selection to the context payload via Shopware's standard
        // context switch endpoint. This ensures the choice survives page reloads
        // and cart refreshes.
        const data = {
            pickupLocationId: selectedLocationId || null
        };

        const httpClient = new window.HttpClient();
        httpClient.post('/checkout/configure', JSON.stringify(data), (response) => {
            // After successful persistence, reload the page to refresh the cart
            // and the shipping form (e.g. to show time slots for the new location).
            window.location.reload();
        });
    }

    /**
     * Fetches pickup location options HTML and injects it into the select element.
     * After injection, it refreshes the rendered location info.
     *
     * @param {{lat: string, lon: string}|null} coordinates rounded customer position for nearest-first ordering
     * @returns {Promise<void>}
     * @private
     */
    async _fetchPickupLocations(coordinates = null) {
        if (!this.options.url) {
            console.error('SalesChannelPickupLocationPlugin: URL option is not set.');
            return;
        }

        let url = this.options.url;
        if (coordinates) {
            const separator = url.includes('?') ? '&' : '?';
            url += `${separator}lat=${encodeURIComponent(coordinates.lat)}&lon=${encodeURIComponent(coordinates.lon)}`;
        }

        try {
            const response = await fetch(url);
            if (!response.ok) {
                throw new Error(`Network response was not ok: ${response.statusText}`);
            }
            const data = await response.text();

            const container = this.el.querySelector(this.options.selectorSelectOptions);

            if (!container) {
                console.error(`SalesChannelPickupLocationPlugin: Container with selector ${this.options.selectorSelectOptions} not found.`);
                return;
            }

            container.innerHTML = data;
            this._updateSelectedPickupLocationOptionData(container);
        } catch (error) {
            console.error('There was a problem with the fetch operation:', error);
        }
    }

    /**
     * Re-applies the previously selected pickup location to the freshly injected
     * option list, if that option is still present.
     *
     * @param {HTMLSelectElement} select
     * @private
     */
    _updateSelectedPickupLocationOptionData(select) {
        const selectedId = this.options.selectedPickupLocationId;

        if (!selectedId || !select) {
            return;
        }

        const hasOption = Array.from(select.options).some((option) => option.value === selectedId);

        if (hasOption) {
            select.value = selectedId;
        }
    }
}
