import { derivePickupView } from './pickup-view';

describe('module/kmh-order-pickup-info/pickup-view', () => {
    it('reports the empty state when the order has no pickup record', () => {
        expect(derivePickupView(null).state).toBe('empty');
        expect(derivePickupView(undefined).state).toBe('empty');
    });

    it('reports the located state and exposes the loaded location', () => {
        const location = { name: 'Downtown Store', city: 'Lagos' };
        const view = derivePickupView({
            pickupLocation: location,
            pickupTime: '2024-06-03T09:00:00+01:00',
            comment: 'Ring the bell',
            createdAt: '2024-06-01T10:00:00+00:00',
        });

        expect(view.state).toBe('located');
        expect(view.location).toBe(location);
        expect(view.pickupTime).toBe('2024-06-03T09:00:00+01:00');
        expect(view.comment).toBe('Ring the bell');
        expect(view.createdAt).toBe('2024-06-01T10:00:00+00:00');
    });

    it('reports location-deleted but keeps the historical appointment data', () => {
        // Location deleted → FK set null, pickupLocation association resolves null.
        const view = derivePickupView({
            pickupLocation: null,
            pickupTime: '2024-06-03T09:00:00+01:00',
            comment: 'Leave at reception',
        });

        expect(view.state).toBe('location-deleted');
        expect(view.location).toBeNull();
        expect(view.pickupTime).toBe('2024-06-03T09:00:00+01:00');
        expect(view.comment).toBe('Leave at reception');
    });

    it('normalises missing appointment fields to null', () => {
        const view = derivePickupView({ pickupLocation: { name: 'X' } });

        expect(view.pickupTime).toBeNull();
        expect(view.comment).toBeNull();
        expect(view.createdAt).toBeNull();
    });
});
