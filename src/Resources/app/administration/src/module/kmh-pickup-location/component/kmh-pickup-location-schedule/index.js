import template from './kmh-pickup-location-schedule.html.twig';
import './kmh-pickup-location-schedule.scss';

const { Data, Context } = Shopware;
const { EntityCollection } = Data;

// ISO-8601 weekdays (1 = Monday … 7 = Sunday) — matches the DAL day_of_week.
const WEEKDAYS = [
    { iso: 1, key: 'monday' },
    { iso: 2, key: 'tuesday' },
    { iso: 3, key: 'wednesday' },
    { iso: 4, key: 'thursday' },
    { iso: 5, key: 'friday' },
    { iso: 6, key: 'saturday' },
    { iso: 7, key: 'sunday' },
];

// A pragmatic curated set; the field also accepts any typed IANA id.
const COMMON_TIMEZONES = [
    'UTC', 'Europe/London', 'Europe/Berlin', 'Europe/Paris', 'Europe/Madrid',
    'Africa/Lagos', 'Africa/Cairo', 'Africa/Johannesburg',
    'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'America/Sao_Paulo',
    'Asia/Dubai', 'Asia/Kolkata', 'Asia/Shanghai', 'Asia/Tokyo', 'Australia/Sydney',
];

const TIME_PATTERN = /^([01]\d|2[0-3]):[0-5]\d$/;

/**
 * Schedule editor for a pickup location: timezone, per-weekday opening intervals
 * (multiple allowed) and special-date overrides. Mutates the entity's
 * `openingHoursSchedule` and `specialHours` DAL associations directly, so the
 * page's repository.save() persists them.
 */
export default {
    template,

    inject: ['repositoryFactory'],

    props: {
        pickupLocation: {
            type: Object,
            required: true,
        },
    },

    data() {
        return {
            expandedDays: [],
            expandedSpecial: false,
        };
    },

    computed: {
        weekdays() {
            return WEEKDAYS.map((day) => ({
                iso: day.iso,
                key: day.key,
                label: this.$tc(`kmh-pickup-location.baseForm.${day.key}`),
            }));
        },

        scheduleSummary() {
            if (!this.pickupLocation.openingHoursSchedule || this.pickupLocation.openingHoursSchedule.length === 0) {
                return this.$tc('kmh-pickup-location.schedule.summaryEmpty');
            }

            // Group days by their intervals to find identical schedules
            const scheduleGroups = [];
            WEEKDAYS.forEach((day) => {
                const intervals = this.intervalsForDay(day.iso);
                if (intervals.length === 0) return;

                const intervalString = intervals
                    .map(i => `${i.openTime}-${i.closeTime}`)
                    .join(', ');

                const group = scheduleGroups.find(g => g.intervalString === intervalString);
                if (group) {
                    group.days.push(day);
                } else {
                    scheduleGroups.push({
                        intervalString,
                        days: [day],
                    });
                }
            });

            if (scheduleGroups.length === 0) {
                return this.$tc('kmh-pickup-location.schedule.summaryClosed');
            }

            return scheduleGroups.map(group => {
                const dayLabels = group.days.map(d => this.$tc(`kmh-pickup-location.baseForm.${d.key}`).substring(0, 3));
                let daysText = dayLabels.join(', ');

                // Try to simplify consecutive days (e.g., Mon, Tue, Wed -> Mon-Wed)
                if (group.days.length > 2) {
                    const isConsecutive = group.days.every((d, i) => i === 0 || d.iso === group.days[i - 1].iso + 1);
                    if (isConsecutive) {
                        daysText = `${dayLabels[0]}-${dayLabels[dayLabels.length - 1]}`;
                    }
                }

                return `${daysText}: ${group.intervalString}`;
            }).join(' | ');
        },

        timezoneOptions() {
            const configured = this.pickupLocation.timezone;
            const values = COMMON_TIMEZONES.includes(configured) || !configured
                ? COMMON_TIMEZONES
                : [configured, ...COMMON_TIMEZONES];

            return values.map((tz) => ({ value: tz, label: tz }));
        },

        openingHourRepository() {
            return this.repositoryFactory.create('kmh_pickup_location_opening_hour');
        },

        specialHourRepository() {
            return this.repositoryFactory.create('kmh_pickup_location_special_hour');
        },
    },

    created() {
        this.ensureCollections();
        this.applyDefaultsForNewLocation();
    },

    methods: {
        ensureCollections() {
            if (!this.pickupLocation.openingHoursSchedule) {
                this.pickupLocation.openingHoursSchedule = this.createCollection('kmh_pickup_location_opening_hour');
            }

            if (!this.pickupLocation.specialHours) {
                this.pickupLocation.specialHours = this.createCollection('kmh_pickup_location_special_hour');
            }
        },

        // Sensible defaults for a brand-new location: a timezone and a Mon–Fri
        // 09:00–17:00 week. Never touches an existing location's saved schedule.
        applyDefaultsForNewLocation() {
            const isNew = typeof this.pickupLocation.isNew === 'function' && this.pickupLocation.isNew();

            if (!isNew) {
                return;
            }

            if (!this.pickupLocation.timezone) {
                this.pickupLocation.timezone = 'UTC';
            }

            if (this.pickupLocation.openingHoursSchedule.length === 0) {
                [1, 2, 3, 4, 5].forEach((iso) => this.addInterval(iso));
            }
        },

        createCollection(entityName) {
            const repository = this.repositoryFactory.create(entityName);

            return new EntityCollection(repository.route, repository.entityName, Context.api);
        },

        intervalsForDay(iso) {
            return (this.pickupLocation.openingHoursSchedule ?? [])
                .filter((interval) => interval.dayOfWeek === iso)
                .sort((a, b) => (a.openTime ?? '').localeCompare(b.openTime ?? ''));
        },

        isDayEnabled(iso) {
            return this.intervalsForDay(iso).length > 0;
        },

        toggleDay(iso, enabled) {
            if (enabled) {
                if (!this.isDayEnabled(iso)) {
                    this.addInterval(iso);
                }
                return;
            }

            this.intervalsForDay(iso).forEach((interval) => {
                this.pickupLocation.openingHoursSchedule.remove(interval.id);
            });
        },

        addInterval(iso) {
            const interval = this.openingHourRepository.create(Context.api);
            interval.pickupLocationId = this.pickupLocation.id;
            interval.dayOfWeek = iso;
            interval.openTime = '09:00';
            interval.closeTime = '17:00';

            this.pickupLocation.openingHoursSchedule.add(interval);
        },

        removeInterval(interval) {
            this.pickupLocation.openingHoursSchedule.remove(interval.id);
        },

        addSpecialHour() {
            const special = this.specialHourRepository.create(Context.api);
            special.pickupLocationId = this.pickupLocation.id;
            special.date = new Date().toISOString().slice(0, 10);
            special.closed = false;
            special.openTime = '09:00';
            special.closeTime = '13:00';

            this.pickupLocation.specialHours.add(special);
        },

        removeSpecialHour(special) {
            this.pickupLocation.specialHours.remove(special.id);
        },

        isDayExpanded(iso) {
            return this.expandedDays.includes(iso);
        },

        toggleDayExpansion(iso) {
            if (this.isDayExpanded(iso)) {
                this.expandedDays = this.expandedDays.filter(day => day !== iso);
            } else {
                this.expandedDays.push(iso);
            }
        },

        // --- validation -----------------------------------------------------

        isValidTime(value) {
            return typeof value === 'string' && TIME_PATTERN.test(value);
        },

        intervalError(interval) {
            if (!this.isValidTime(interval.openTime) || !this.isValidTime(interval.closeTime)) {
                return this.$tc('kmh-pickup-location.schedule.errorInvalidTime');
            }

            // Same-day interval must be ordered; equal endpoints are empty.
            if (interval.openTime >= interval.closeTime) {
                return this.$tc('kmh-pickup-location.schedule.errorOrder');
            }

            if (this.hasOverlap(interval)) {
                return this.$tc('kmh-pickup-location.schedule.errorOverlap');
            }

            return null;
        },

        hasOverlap(interval) {
            if (!this.isValidTime(interval.openTime) || !this.isValidTime(interval.closeTime)) {
                return false;
            }

            return this.intervalsForDay(interval.dayOfWeek).some((other) => {
                if (other === interval || !this.isValidTime(other.openTime) || !this.isValidTime(other.closeTime)) {
                    return false;
                }

                return interval.openTime < other.closeTime && other.openTime < interval.closeTime;
            });
        },

        specialError(special) {
            if (special.closed) {
                return null;
            }

            if (!this.isValidTime(special.openTime) || !this.isValidTime(special.closeTime)) {
                return this.$tc('kmh-pickup-location.schedule.errorInvalidTime');
            }

            if (special.openTime >= special.closeTime) {
                return this.$tc('kmh-pickup-location.schedule.errorOrder');
            }

            return null;
        },
    },
};
