<?php

declare(strict_types=1);

namespace Kommandhub\ClickAndPickSW\Twig;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

#[AutoconfigureTag('twig_extension')]
class FormattedCalendarDays extends AbstractExtension
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * @var array<string, int>
     */
    private const WEEKDAY_INDEX = [
        'monday' => 0,
        'tuesday' => 1,
        'wednesday' => 2,
        'thursday' => 3,
        'friday' => 4,
        'saturday' => 5,
        'sunday' => 6,
    ];

    public function getFunctions(): array
    {
        return [
            new TwigFunction('format_calendar_days', [$this, 'formatCalendarDays']),
        ];
    }

    public function formatCalendarDays(?array $calendarDays): string
    {
        $days = $this->normalizeAndSortDays($calendarDays);

        if ($days === []) {
            return '';
        }

        $translatedDays = array_map(
            fn (string $day): string => $this->translator->trans('general.kmh-click-and-pick.daysOfWeek.' . $day),
            $days
        );

        if (count($days) > 1 && $this->isContinuousWeekRange($days)) {
            return $translatedDays[0] . ' - ' . $translatedDays[array_key_last($translatedDays)];
        }

        return implode(', ', $translatedDays);
    }

    /**
     * @param array<int, mixed>|null $calendarDays
     *
     * @return array<int, string>
     */
    private function normalizeAndSortDays(?array $calendarDays): array
    {
        if ($calendarDays === null || $calendarDays === []) {
            return [];
        }

        $uniqueDays = [];

        foreach ($calendarDays as $day) {
            if (!\is_scalar($day) && !$day instanceof \Stringable) {
                continue;
            }

            $normalizedDay = strtolower(trim((string)$day));

            if ($normalizedDay === '' || !isset(self::WEEKDAY_INDEX[$normalizedDay])) {
                continue;
            }

            $uniqueDays[$normalizedDay] = true;
        }

        $days = array_keys($uniqueDays);
        usort(
            $days,
            static fn (string $left, string $right): int => self::WEEKDAY_INDEX[$left] <=> self::WEEKDAY_INDEX[$right]
        );

        return $days;
    }

    /**
     * Evaluates continuity within a Monday-to-Sunday index sequence.
     * Wrap-around sets such as Sat, Sun, Mon are treated as non-continuous.
     *
     * @param array<int, string> $days
     */
    private function isContinuousWeekRange(array $days): bool
    {
        for ($index = 1, $length = count($days); $index < $length; $index++) {
            if (self::WEEKDAY_INDEX[$days[$index]] !== self::WEEKDAY_INDEX[$days[$index - 1]] + 1) {
                return false;
            }
        }

        return true;
    }
}
