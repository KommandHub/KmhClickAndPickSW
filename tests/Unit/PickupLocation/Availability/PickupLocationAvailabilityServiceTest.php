<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Tests\Unit\PickupLocation\Availability;

use Kommandhub\ClickAndPickSW\Entity\PickupLocation\Aggregate\PickupLocationOpeningHour\PickupLocationOpeningHourCollection;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\Aggregate\PickupLocationOpeningHour\PickupLocationOpeningHourEntity;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\Aggregate\PickupLocationSpecialHour\PickupLocationSpecialHourCollection;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\Aggregate\PickupLocationSpecialHour\PickupLocationSpecialHourEntity;
use Kommandhub\ClickAndPickSW\Entity\PickupLocation\PickupLocationEntity;
use Kommandhub\ClickAndPickSW\PickupLocation\Availability\PickupLocationAvailabilityService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PickupLocationAvailabilityService::class)]
class PickupLocationAvailabilityServiceTest extends TestCase
{
    private PickupLocationAvailabilityService $service;

    protected function setUp(): void
    {
        $this->service = new PickupLocationAvailabilityService();
    }

    /**
     * @param list<array{int, string, string}> $intervals weekday(ISO), open, close
     */
    #[DataProvider('weeklyScheduleProvider')]
    public function testWeeklyScheduleWithinTimezone(array $intervals, string $timezone, string $utcInstant, bool $expected): void
    {
        $location = $this->location($timezone, $this->openingHours($intervals), null);

        static::assertSame($expected, $this->service->isOpenAt($location, $this->at($utcInstant)));
    }

    /**
     * @return iterable<string, array{list<array{int, string, string}>, string, string, bool}>
     */
    public static function weeklyScheduleProvider(): iterable
    {
        // 2024-06-03 is a Monday. Berlin is UTC+2 in June, Lagos is UTC+1.
        $monday = [[1, '09:00', '17:00']];

        yield 'open mid-interval' => [$monday, 'Europe/Berlin', '2024-06-03 08:00:00', true]; // 10:00 local
        yield 'closed before open' => [$monday, 'Europe/Berlin', '2024-06-03 06:30:00', false]; // 08:30 local
        yield 'open exactly at open boundary' => [$monday, 'Europe/Berlin', '2024-06-03 07:00:00', true]; // 09:00 local
        yield 'closed exactly at close boundary' => [$monday, 'Europe/Berlin', '2024-06-03 15:00:00', false]; // 17:00 local
        yield 'open one minute before close' => [$monday, 'Europe/Berlin', '2024-06-03 14:59:00', true]; // 16:59 local

        // Timezone matters: same UTC instant, different zones.
        yield 'lagos still closed at 08:30 local' => [$monday, 'Africa/Lagos', '2024-06-03 07:30:00', false]; // 08:30 Lagos
        yield 'berlin already open at 09:30 local' => [$monday, 'Europe/Berlin', '2024-06-03 07:30:00', true]; // 09:30 Berlin

        // Wrong weekday.
        yield 'closed on a non-scheduled weekday' => [[[2, '09:00', '17:00']], 'Europe/Berlin', '2024-06-03 10:00:00', false];

        // Multiple intervals with a midday gap.
        $split = [[1, '09:00', '13:00'], [1, '14:00', '18:00']];
        yield 'open in first interval' => [$split, 'UTC', '2024-06-03 10:00:00', true];
        yield 'closed during the gap' => [$split, 'UTC', '2024-06-03 13:30:00', false];
        yield 'open in second interval' => [$split, 'UTC', '2024-06-03 15:00:00', true];

        // Overnight interval (wraps past midnight).
        $overnight = [[1, '22:00', '02:00']];
        yield 'overnight open late evening' => [$overnight, 'UTC', '2024-06-03 23:00:00', true];
        yield 'overnight closed mid-afternoon' => [$overnight, 'UTC', '2024-06-03 15:00:00', false];

        // Null timezone falls back to UTC.
        yield 'null timezone treated as UTC' => [$monday, '', '2024-06-03 10:00:00', true];
        // Invalid timezone falls back to UTC, no crash.
        yield 'invalid timezone treated as UTC' => [$monday, 'Not/AZone', '2024-06-03 10:00:00', true];
    }

    public function testSpecialClosureOverridesWeeklySchedule(): void
    {
        $location = $this->location(
            'Europe/Berlin',
            $this->openingHours([[2, '09:00', '17:00']]), // Tuesday open
            $this->specialHours([['2024-12-24', true, null, null]]) // but closed on that Tuesday
        );

        // 2024-12-24 is a Tuesday, 10:00 Berlin.
        static::assertFalse($this->service->isOpenAt($location, $this->at('2024-12-24 09:00:00')));
    }

    public function testSpecialHoursOverrideWeeklySchedule(): void
    {
        $location = $this->location(
            'UTC',
            $this->openingHours([[2, '09:00', '17:00']]),
            $this->specialHours([['2024-12-24', false, '09:00', '13:00']])
        );

        static::assertTrue($this->service->isOpenAt($location, $this->at('2024-12-24 10:00:00')));
        // Weekly would be open at 15:00, but the special hours cap the day at 13:00.
        static::assertFalse($this->service->isOpenAt($location, $this->at('2024-12-24 15:00:00')));
    }

    public function testClosureRowWinsOverExceptionalHoursOnSameDate(): void
    {
        $location = $this->location(
            'UTC',
            $this->openingHours([]),
            $this->specialHours([
                ['2024-12-24', false, '09:00', '13:00'],
                ['2024-12-24', true, null, null],
            ])
        );

        static::assertFalse($this->service->isOpenAt($location, $this->at('2024-12-24 10:00:00')));
    }

    public function testIsOpenOnDateIsTrueBeforeAndAfterTheDailyWindow(): void
    {
        // Tuesday 07:00–09:00 only; the location is "open today" all Tuesday long
        // even outside the window, so it stays selectable.
        $location = $this->location('Africa/Lagos', $this->openingHours([[2, '07:00', '09:00']]), null);

        // 2024-06-04 is a Tuesday. 15:00 UTC = 16:00 Lagos (past the 09:00 close).
        static::assertFalse($this->service->isOpenAt($location, $this->at('2024-06-04 15:00:00')));
        static::assertTrue($this->service->isOpenOnDate($location, $this->at('2024-06-04 15:00:00')));
        // Wednesday has no interval → not open that date.
        static::assertFalse($this->service->isOpenOnDate($location, $this->at('2024-06-05 15:00:00')));
    }

    public function testIsOpenOnDateRespectsSpecialClosure(): void
    {
        $location = $this->location(
            'UTC',
            $this->openingHours([[2, '09:00', '17:00']]),
            $this->specialHours([['2024-12-24', true, null, null]])
        );

        static::assertFalse($this->service->isOpenOnDate($location, $this->at('2024-12-24 10:00:00')));
    }

    public function testIsOpenOnDateIsFalseForSpecialDateWithoutOpenWindow(): void
    {
        $location = $this->location(
            'UTC',
            $this->openingHours([[2, '09:00', '17:00']]),
            $this->specialHours([['2024-12-24', false, null, null]])
        );

        static::assertFalse($this->service->isOpenOnDate($location, $this->at('2024-12-24 10:00:00')));
        static::assertFalse($this->service->isOpenAt($location, $this->at('2024-12-24 10:00:00')));
    }

    public function testFilterOpenOnDateKeepsLocationsOpenLaterToday(): void
    {
        // Opens later today but closed right now — must remain selectable.
        $laterToday = $this->location('UTC', $this->openingHours([[1, '18:00', '20:00']]), null);
        $notToday = $this->location('UTC', $this->openingHours([[3, '09:00', '17:00']]), null);

        // 2024-06-03 is Monday, 12:00 (before the 18:00 open).
        $result = $this->service->filterOpenOnDate([$laterToday, $notToday], $this->at('2024-06-03 12:00:00'));

        static::assertSame([$laterToday], $result);
    }

    public function testFilterOpenWithinDaysKeepsLocationsClosedTodayButOpenLater(): void
    {
        // 2024-06-03 is a Monday.
        $wednesday = $this->location('UTC', $this->openingHours([[3, '09:00', '17:00']]), null);
        $holidayThenOpen = $this->location(
            'UTC',
            $this->openingHours([[1, '09:00', '17:00'], [2, '09:00', '17:00']]),
            $this->specialHours([['2024-06-03', true, null, null]])
        );
        $neverOpen = $this->location('UTC', $this->openingHours([]), null);

        $result = $this->service->filterOpenWithinDays(
            [$wednesday, $holidayThenOpen, $neverOpen],
            7,
            $this->at('2024-06-03 12:00:00')
        );

        static::assertSame([$wednesday, $holidayThenOpen], $result);
    }

    public function testFilterOpenWithinDaysOnlyLooksAheadTheGivenDays(): void
    {
        // Open only on Saturdays; from Monday a 3-day window misses it.
        $saturday = $this->location('UTC', $this->openingHours([[6, '09:00', '17:00']]), null);

        static::assertSame([], $this->service->filterOpenWithinDays([$saturday], 3, $this->at('2024-06-03 12:00:00')));
        static::assertSame([$saturday], $this->service->filterOpenWithinDays([$saturday], 6, $this->at('2024-06-03 12:00:00')));
    }

    public function testFilterOpenWithinDaysDefaultsToNow(): void
    {
        $everyDay = $this->location('UTC', $this->openingHours(array_map(static fn (int $d): array => [$d, '00:00', '23:59'], range(1, 7))), null);

        static::assertSame([$everyDay], $this->service->filterOpenWithinDays([$everyDay], 1));
    }

    public function testFilterOpenReturnsOnlyOpenLocations(): void
    {
        $open = $this->location('UTC', $this->openingHours([[1, '09:00', '17:00']]), null);
        $closed = $this->location('UTC', $this->openingHours([[1, '09:00', '10:00']]), null);

        $result = $this->service->filterOpen([$open, $closed], $this->at('2024-06-03 12:00:00'));

        static::assertSame([$open], $result);
    }

    public function testNoScheduleMeansClosed(): void
    {
        $location = $this->location('UTC', $this->openingHours([]), $this->specialHours([]));

        static::assertFalse($this->service->isOpenAt($location, $this->at('2024-06-03 12:00:00')));
    }

    public function testZeroLengthAndInvalidIntervalsAreTreatedAsClosed(): void
    {
        $zeroLength = $this->location('UTC', $this->openingHours([[1, '12:00', '12:00']]), null);
        $invalid = $this->location('UTC', $this->openingHours([[1, 'nope', '17:00']]), null);

        static::assertFalse($this->service->isOpenAt($zeroLength, $this->at('2024-06-03 12:00:00')));
        static::assertFalse($this->service->isOpenAt($invalid, $this->at('2024-06-03 12:00:00')));
    }

    public function testPrivateHelpersHandleExpectedEdgeCases(): void
    {
        $withinInterval = new \ReflectionMethod($this->service, 'withinInterval');
        $toMinutes = new \ReflectionMethod($this->service, 'toMinutes');
        $resolveTimezone = new \ReflectionMethod($this->service, 'resolveTimezone');

        static::assertTrue($withinInterval->invoke($this->service, '22:00', '02:00', 60));
        static::assertFalse($withinInterval->invoke($this->service, '09:00', '09:00', 540));
        static::assertSame(570, $toMinutes->invoke($this->service, '09:30'));
        static::assertNull($toMinutes->invoke($this->service, 'invalid'));
        $defaultTimezone = $resolveTimezone->invoke($this->service, null);
        $invalidTimezone = $resolveTimezone->invoke($this->service, 'Not/AZone');
        $validTimezone = $resolveTimezone->invoke($this->service, 'Europe/Berlin');

        static::assertInstanceOf(\DateTimeZone::class, $defaultTimezone);
        static::assertInstanceOf(\DateTimeZone::class, $invalidTimezone);
        static::assertInstanceOf(\DateTimeZone::class, $validTimezone);
        static::assertSame('UTC', $defaultTimezone->getName());
        static::assertSame('UTC', $invalidTimezone->getName());
        static::assertSame('Europe/Berlin', $validTimezone->getName());
    }

    public function testGetOpenIntervalsForDateReturnsWeeklyRanges(): void
    {
        $location = $this->location('UTC', $this->openingHours([[1, '09:00', '17:00']]), null);

        $intervals = $this->service->getOpenIntervalsForDate($location, $this->at('2024-06-03 00:00:00'));

        static::assertCount(1, $intervals);
        static::assertSame('2024-06-03 09:00', $intervals[0]['start']->format('Y-m-d H:i'));
        static::assertSame('2024-06-03 17:00', $intervals[0]['end']->format('Y-m-d H:i'));
    }

    public function testGetOpenIntervalsForDateWrapsOvernightIntervalToNextDay(): void
    {
        $location = $this->location('UTC', $this->openingHours([[1, '22:00', '02:00']]), null);

        $intervals = $this->service->getOpenIntervalsForDate($location, $this->at('2024-06-03 00:00:00'));

        static::assertCount(1, $intervals);
        static::assertSame('2024-06-03 22:00', $intervals[0]['start']->format('Y-m-d H:i'));
        static::assertSame('2024-06-04 02:00', $intervals[0]['end']->format('Y-m-d H:i'));
    }

    public function testGetOpenIntervalsForDateReturnsEmptyOnSpecialClosure(): void
    {
        $location = $this->location(
            'UTC',
            $this->openingHours([[2, '09:00', '17:00']]),
            $this->specialHours([['2024-12-24', true, null, null]])
        );

        static::assertSame([], $this->service->getOpenIntervalsForDate($location, $this->at('2024-12-24 00:00:00')));
    }

    public function testGetOpenIntervalsForDateSpecialOverrideWinsOverWeekly(): void
    {
        $location = $this->location(
            'UTC',
            $this->openingHours([[2, '09:00', '17:00']]),
            $this->specialHours([['2024-12-24', false, '10:00', '14:00']])
        );

        $intervals = $this->service->getOpenIntervalsForDate($location, $this->at('2024-12-24 00:00:00'));

        static::assertCount(1, $intervals);
        static::assertSame('2024-12-24 10:00', $intervals[0]['start']->format('Y-m-d H:i'));
        static::assertSame('2024-12-24 14:00', $intervals[0]['end']->format('Y-m-d H:i'));
    }

    public function testGetOpenIntervalsForDateSkipsSpecialRowsWithoutTimes(): void
    {
        // Not a closure, but no usable open/close → buildRange yields no interval.
        $location = $this->location(
            'UTC',
            $this->openingHours([[2, '09:00', '17:00']]),
            $this->specialHours([['2024-12-24', false, null, null]])
        );

        static::assertSame([], $this->service->getOpenIntervalsForDate($location, $this->at('2024-12-24 00:00:00')));
    }

    private function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    private function location(
        string $timezone,
        PickupLocationOpeningHourCollection $openingHours,
        ?PickupLocationSpecialHourCollection $specialHours
    ): PickupLocationEntity {
        $location = new PickupLocationEntity();
        $location->setId(bin2hex(random_bytes(16)));
        $location->setTimezone($timezone === '' ? null : $timezone);
        $location->setOpeningHoursSchedule($openingHours);
        $location->setSpecialHours($specialHours);

        return $location;
    }

    /**
     * @param list<array{int, string, string}> $intervals
     */
    private function openingHours(array $intervals): PickupLocationOpeningHourCollection
    {
        $entities = [];

        foreach ($intervals as [$dayOfWeek, $openTime, $closeTime]) {
            $entity = new PickupLocationOpeningHourEntity();
            $entity->setId(bin2hex(random_bytes(16)));
            $entity->setPickupLocationId('00000000000000000000000000000000');
            $entity->setDayOfWeek($dayOfWeek);
            $entity->setOpenTime($openTime);
            $entity->setCloseTime($closeTime);
            $entities[] = $entity;
        }

        return new PickupLocationOpeningHourCollection($entities);
    }

    /**
     * @param list<array{string, bool, string|null, string|null}> $rows
     */
    private function specialHours(array $rows): PickupLocationSpecialHourCollection
    {
        $entities = [];

        foreach ($rows as [$date, $closed, $openTime, $closeTime]) {
            $entity = new PickupLocationSpecialHourEntity();
            $entity->setId(bin2hex(random_bytes(16)));
            $entity->setPickupLocationId('00000000000000000000000000000000');
            $entity->setDate(new \DateTimeImmutable($date));
            $entity->setClosed($closed);
            $entity->setOpenTime($openTime);
            $entity->setCloseTime($closeTime);
            $entities[] = $entity;
        }

        return new PickupLocationSpecialHourCollection($entities);
    }
}
