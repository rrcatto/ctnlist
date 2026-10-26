<?php

declare(strict_types=1);

namespace App\Util;

/**
 * A period of time, written the one way every period setting is written (the owner, 2026-10-26, the convention of
 * their cattolms): years, weeks, days, hours, minutes and seconds, in that order, each optional, at least one present
 * and each a whole number from 1 up: 1y1w1d1h1m1s, 120d, 1h, 1m30s. There are no months: `m` is minutes. The letters
 * are lower case only, so that no one writes `M` for months and gets minutes.
 *
 * As a length (a timeout, a lifetime, a window) a year is 365 days. Applied to a moment (a retention cutoff, before()),
 * years are calendar years in the moment's time zone, so 3y may include a leap day; a 29 February the target year does
 * not have becomes 28 February. Weeks, days, hours, minutes and seconds are exact. The text is kept as written and
 * shown in words ("120 days", "1 minute 30 seconds").
 */
final class Duration
{
    /** How to write one, for messages and documentation. */
    public const FORMAT = 'years, weeks, days, hours, minutes and seconds in that order, such as 1y1w1d1h1m1s, 120d or 1m30s';

    private const PATTERN = '/^(?:(\d{1,4})y)?(?:(\d{1,6})w)?(?:(\d{1,7})d)?(?:(\d{1,8})h)?(?:(\d{1,9})m)?(?:(\d{1,10})s)?$/';
    /** Each unit's length in seconds; a year as a length is 365 days. */
    private const UNITS = ['year' => 31536000, 'week' => 604800, 'day' => 86400, 'hour' => 3600, 'minute' => 60, 'second' => 1];

    /** @param array<string, int> $parts each unit of UNITS and its count (0 when absent) */
    private function __construct(public readonly string $text, private readonly array $parts)
    {
    }

    /** @throws \InvalidArgumentException saying how to write a period */
    public static function parse(string $text): self
    {
        $text = trim($text);
        if ($text === '' || preg_match(self::PATTERN, $text, $match) !== 1) {
            throw new \InvalidArgumentException('Write a period as ' . self::FORMAT . '.');
        }
        $parts = [];
        foreach (array_keys(self::UNITS) as $index => $unit) {
            $count = $match[$index + 1] ?? '';
            if ($count !== '' && (int) $count < 1) {
                throw new \InvalidArgumentException('Each part of a period is a whole number from 1 up, such as 120d or 1m30s.');
            }
            $parts[$unit] = (int) $count;
        }
        return new self($text, $parts);
    }

    public static function tryParse(?string $text): ?self
    {
        try {
            return self::parse((string) $text);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * A period setting read from .env: its value when that is a period, otherwise $default; never shorter than
     * $shortest (a shorter value gives $shortest).
     */
    public static function setting(?string $value, string $default, string $shortest): self
    {
        $period = self::tryParse($value) ?? self::parse($default);
        $minimum = self::parse($shortest);
        return $period->seconds() < $minimum->seconds() ? $minimum : $period;
    }

    /** The length in seconds, a year as 365 days. */
    public function seconds(): int
    {
        $seconds = 0;
        foreach (self::UNITS as $unit => $length) {
            $seconds += $this->parts[$unit] * $length;
        }
        return $seconds;
    }

    /** "120 days", "1 year 2 weeks", "1 minute 30 seconds". */
    public function words(): string
    {
        $words = [];
        foreach ($this->parts as $unit => $count) {
            if ($count > 0) {
                $words[] = $count . ' ' . $unit . ($count === 1 ? '' : 's');
            }
        }
        return implode(' ', $words);
    }

    /** The moment this long before $at, its years calendar years in $at's time zone. */
    public function before(\DateTimeImmutable $at): \DateTimeImmutable
    {
        $years = $this->parts['year'];
        $result = $at->modify('-' . ($this->seconds() - $years * self::UNITS['year']) . ' seconds');
        if ($years === 0) {
            return $result;
        }
        $year = (int) $result->format('Y') - $years;
        $month = (int) $result->format('n');
        $lastDay = (int) $result->setDate($year, $month, 1)->format('t');
        return $result->setDate($year, $month, min((int) $result->format('j'), $lastDay));
    }
}
