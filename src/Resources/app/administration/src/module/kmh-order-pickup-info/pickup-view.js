/**
 * Derives the read-only view state for the order Pickup Information tab from a
 * `kmh_order_pickup_location` record. Pure (no Shopware globals) so the
 * missing/deleted-location rule can be unit-tested in isolation.
 *
 * States:
 * - `empty`            — the order has no pickup record (not a pickup order).
 * - `located`          — the pickup location still exists and is loaded.
 * - `location-deleted` — the location was deleted (FK set null); the historical
 *                        appointment data (pickup time + comment) is still kept.
 *
 * @param {object|null|undefined} record
 * @returns {{state: string, location: object|null, pickupTime: *, comment: *, createdAt: *}}
 */
export function derivePickupView(record) {
    if (!record) {
        return { state: 'empty', location: null, pickupTime: null, comment: null, createdAt: null };
    }

    const location = record.pickupLocation ?? null;

    return {
        state: location ? 'located' : 'location-deleted',
        location,
        pickupTime: record.pickupTime ?? null,
        comment: record.comment ?? null,
        createdAt: record.createdAt ?? null,
    };
}
