<?php

declare(strict_types=1);

/** Dates shared by the Telegram and MAX publication series menus. */
final class PostedPublicationPlan
{
    public const MAX_SLOTS = 366;

    public static function one(
        string $text,
        DateTimeZone $localTz,
        DateTimeZone $storageTz,
        ?DateTimeImmutable $now = null
    ): string {
        $date = self::localDateTime($text, $localTz);
        self::assertFuture($date, $now);
        return self::stored($date, $storageTz);
    }

    /** @return list<string> Dates in the application's storage timezone. */
    public static function dates(
        string $text,
        DateTimeZone $localTz,
        DateTimeZone $storageTz,
        ?DateTimeImmutable $now = null
    ): array {
        $parts = preg_split('/[;,\r\n]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts) || $parts === []) {
            throw new InvalidArgumentException('Укажите хотя бы одну дату и время публикации.');
        }
        if (count($parts) > self::MAX_SLOTS) {
            throw new InvalidArgumentException('В одной серии может быть не больше 366 публикаций.');
        }
        $now ??= new DateTimeImmutable('now');
        $slots = [];
        foreach ($parts as $part) {
            $at = self::one(trim($part), $localTz, $storageTz, $now);
            if (isset($slots[$at])) {
                throw new InvalidArgumentException('Одинаковая дата и время указаны дважды. Уберите повтор.');
            }
            $slots[$at] = true;
        }
        $result = array_keys($slots);
        sort($result, SORT_STRING);
        return $result;
    }

    /**
     * Advance local calendar days, keeping the chosen clock time through DST.
     * The end date is inclusive, including when it is also the start date.
     *
     * @return list<string>
     */
    public static function period(
        string $startText,
        string $endText,
        int $stepDays,
        DateTimeZone $localTz,
        DateTimeZone $storageTz,
        ?DateTimeImmutable $now = null
    ): array {
        if ($stepDays < 1 || $stepDays > 366) {
            throw new InvalidArgumentException('Интервал должен быть от 1 до 366 дней.');
        }
        $start = self::localDateTime($startText, $localTz);
        self::assertFuture($start, $now);
        $endDate = self::dateParts(trim($endText));
        $endIso = sprintf('%04d-%02d-%02d', $endDate[0], $endDate[1], $endDate[2]);
        if ($endIso < $start->format('Y-m-d')) {
            throw new InvalidArgumentException('Дата окончания должна быть не раньше первой публикации.');
        }
        $clock = $start->format('H:i');
        $date = $start->format('Y-m-d');
        $result = [];
        while ($date <= $endIso) {
            if (count($result) >= self::MAX_SLOTS) {
                throw new InvalidArgumentException('В одной серии может быть не больше 366 публикаций. Сократите период или увеличьте интервал.');
            }
            $local = self::localDateTime($date . ' ' . $clock, $localTz);
            $result[] = self::stored($local, $storageTz);
            // Noon avoids midnight timezone changes when stepping dates.
            $next = new DateTimeImmutable($date . ' 12:00:00', $localTz);
            $next = $next->modify('+' . $stepDays . ' days');
            if ((int) $next->format('Y') > 9999) {
                break;
            }
            $date = $next->format('Y-m-d');
        }
        return $result;
    }

    private static function assertFuture(DateTimeImmutable $date, ?DateTimeImmutable $now): void
    {
        if ($date <= ($now ?? new DateTimeImmutable('now'))) {
            throw new InvalidArgumentException('Это время уже прошло. Укажите будущую дату и время.');
        }
    }

    private static function stored(DateTimeImmutable $date, DateTimeZone $timezone): string
    {
        $stored = $date->setTimezone($timezone);
        $year = (int) $stored->format('Y');
        if ($year < 1000 || $year > 9999) {
            throw new InvalidArgumentException('Дата публикации выходит за допустимый диапазон.');
        }
        return $stored->format('Y-m-d H:i:s');
    }

    /** @return array{int,int,int} Year, month, day. */
    private static function dateParts(string $text): array
    {
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/D', $text, $m) === 1) {
            [$year, $month, $day] = [(int) $m[3], (int) $m[2], (int) $m[1]];
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $text, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            throw new InvalidArgumentException('Укажите дату в формате ДД.ММ.ГГГГ.');
        }
        if ($year < 1000 || !checkdate($month, $day, $year)) {
            throw new InvalidArgumentException('Такой даты не существует. Проверьте день, месяц и год.');
        }
        return [$year, $month, $day];
    }

    private static function localDateTime(string $text, DateTimeZone $timezone): DateTimeImmutable
    {
        $text = trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $text));
        if (preg_match('/^(.+) (\d{1,2}):(\d{2})$/D', $text, $m) !== 1) {
            throw new InvalidArgumentException('Укажите дату и время в формате ДД.ММ.ГГГГ ЧЧ:ММ.');
        }
        [$year, $month, $day] = self::dateParts($m[1]);
        $hour = (int) $m[2];
        $minute = (int) $m[3];
        if ($hour > 23 || $minute > 59) {
            throw new InvalidArgumentException('Время должно быть от 00:00 до 23:59.');
        }
        $value = sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $month, $day, $hour, $minute);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('Это местное время не существует из-за перевода часов. Выберите другое время.');
        }
        // Reject the repeated clock hour rather than silently choose an offset.
        $transitions = $timezone->getTransitions($date->getTimestamp() - 86400, $date->getTimestamp() + 86400);
        if (is_array($transitions)) {
            $previousOffset = null;
            $wallTime = $date->getTimestamp() + $date->getOffset();
            foreach ($transitions as $transition) {
                $offset = (int) $transition['offset'];
                if ($previousOffset !== null && $offset < $previousOffset) {
                    $from = (int) $transition['ts'] + $offset;
                    $until = (int) $transition['ts'] + $previousOffset;
                    if ($wallTime >= $from && $wallTime < $until) {
                        throw new InvalidArgumentException('Это время встречается дважды из-за перевода часов. Выберите другое время.');
                    }
                }
                $previousOffset = $offset;
            }
        }
        return $date;
    }
}
