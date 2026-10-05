const { PluginBaseClass } = window;

export default class SalesChannelPickupTimePlugin extends PluginBaseClass {
    /**
     * Populates the pickup-time <select> with the slots available for the chosen
     * date. Times come from the server, which limits them to the location's
     * opening hours (the same schedule the cart validator enforces on submit).
     */
    static options = {
        /**
         * Slots endpoint for the currently selected location. A `date=YYYY-MM-DD`
         * query is appended per fetch.
         * @type {string|null}
         */
        url: null,

        /**
         * Previously chosen pickup time (ISO-8601), re-selected once its date's
         * slots are loaded.
         * @type {string|null}
         */
        selectedPickupTime: null,

        selectorDate: '[data-pickup-time-date]',
        selectorSelect: '[data-pickup-time-select]',
    };

    init() {
        this.dateInput = this.el.querySelector(this.options.selectorDate);
        this.timeSelect = this.el.querySelector(this.options.selectorSelect);

        if (!this.dateInput || !this.timeSelect) {
            return;
        }

        this._registerEvents();
        this._restoreSelection();
    }

    _registerEvents() {
        // The date input is a helper only; stop its change from bubbling to the
        // shipping form's auto-submit, which would reload and wipe the slots.
        this.dateInput.addEventListener('change', (event) => {
            event.stopPropagation();
            this._fetchSlots(this.dateInput.value);
        });
    }

    /**
     * On load, default the date to the previously chosen day (or today) and load
     * that day's slots so the time field is immediately usable.
     * @private
     */
    _restoreSelection() {
        const isoDate = (this.options.selectedPickupTime || '').slice(0, 10);
        this.dateInput.value = /^\d{4}-\d{2}-\d{2}$/.test(isoDate)
            ? isoDate
            : this._localToday();

        this.dateInput.min = this._localToday();
        this._fetchSlots(this.dateInput.value);
    }

    /**
     * @param {string} date YYYY-MM-DD
     * @returns {Promise<void>}
     * @private
     */
    /**
     * Today's date in the customer's local calendar (YYYY-MM-DD). toISOString()
     * alone gives the UTC date, which is "yesterday" just after midnight east
     * of UTC.
     */
    _localToday() {
        const now = new Date();

        return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    }

    async _fetchSlots(date) {
        if (!this.options.url || !/^\d{4}-\d{2}-\d{2}$/.test(date)) {
            return;
        }

        const separator = this.options.url.includes('?') ? '&' : '?';

        try {
            const response = await fetch(`${this.options.url}${separator}date=${encodeURIComponent(date)}`);
            if (!response.ok) {
                throw new Error(`Network response was not ok: ${response.statusText}`);
            }

            this.timeSelect.innerHTML = await response.text();
            this._reselect();
        } catch (error) {
            console.error('SalesChannelPickupTimePlugin: failed to load pickup slots.', error);
        }
    }

    /**
     * Re-applies the previously chosen time if it is still offered for the loaded
     * date, then fires a change so the shipping form persists the selection.
     * @private
     */
    _reselect() {
        const selected = this.options.selectedPickupTime;

        if (!selected) {
            return;
        }

        const hasOption = Array.from(this.timeSelect.options).some((option) => option.value === selected);

        if (hasOption) {
            this.timeSelect.value = selected;
        }
    }
}
