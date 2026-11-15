<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The last N calendar months in the app's timezone, ending with the current one: for zero-filled monthly reports.
 */
final readonly class MonthWindow
{
    private function __construct(
        public Carbon $start,
        public Carbon $end,
        private Carbon $thisMonth,
        private int $months,
        private string $timezone,
    ) {}

    public static function lastMonths(int $months): self
    {
        $timezone = (string) config('app.timezone');
        $thisMonth = Carbon::now($timezone)->startOfMonth();

        return new self(
            start: $thisMonth->copy()->subMonthsNoOverflow($months - 1),
            end: $thisMonth->copy()->addMonthNoOverflow(),
            thisMonth: $thisMonth,
            months: $months,
            timezone: $timezone,
        );
    }

    /**
     * The months as `Y-m`, newest first.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];

        for ($i = 0; $i < $this->months; $i++) {
            $keys[] = $this->thisMonth->copy()->subMonthsNoOverflow($i)->format('Y-m');
        }

        return $keys;
    }

    /** The `Y-m` of the month a moment falls in, in the app's timezone. */
    public function keyOf(CarbonInterface $moment): string
    {
        return $moment->copy()->setTimezone($this->timezone)->format('Y-m');
    }
}
