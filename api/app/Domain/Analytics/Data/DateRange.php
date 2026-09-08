<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Data;

use Carbon\CarbonImmutable;

/**
 * The window every analytics read is asked for.
 *
 * UTC DAYS, inclusive at both ends, because that is what the rollups are keyed
 * on — see the rollup migration. Anything else would have to convert on the
 * fly and would stop matching the stored rows.
 *
 * The length is CAPPED. A dashboard that can ask for all of history is a
 * dashboard that can ask for a table scan, and nobody means to.
 */
final class DateRange
{
    public const MAX_DAYS = 366;

    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    public static function make(?string $from, ?string $to, int $defaultDays = 30): self
    {
        $end = $to !== null && $to !== ''
            ? CarbonImmutable::parse($to, 'UTC')->startOfDay()
            : CarbonImmutable::now('UTC')->startOfDay();

        $start = $from !== null && $from !== ''
            ? CarbonImmutable::parse($from, 'UTC')->startOfDay()
            : $end->subDays($defaultDays - 1);

        // A reversed range is a typo, not a request for nothing.
        if ($start->isAfter($end)) {
            [$start, $end] = [$end, $start];
        }

        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS - 1);
        }

        return new self($start, $end);
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * The window immediately before this one, of the same length — what a
     * "vs. previous period" figure compares against.
     */
    public function previous(): self
    {
        $days = $this->days();

        return new self($this->from->subDays($days), $this->from->subDay());
    }

    /**
     * Every date in the range, as strings.
     *
     * Used to DENSIFY a series: a day with no rollup row must come back as a
     * zero rather than be missing, or a chart draws a straight line across the
     * gap and reports activity that never happened.
     *
     * @return list<string>
     */
    public function dates(): array
    {
        $dates = [];

        for ($day = $this->from; $day->lessThanOrEqualTo($this->to); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            // Said out loud rather than left for a reader to assume, because
            // "yesterday" means different things in Dhaka and Denver.
            'timezone' => 'UTC',
        ];
    }
}
