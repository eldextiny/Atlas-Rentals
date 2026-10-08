<?php
declare(strict_types=1);

/** Display only: calendar components never pass through timezone conversion.
 * Invalid/absent text retains the existing safe presentation fallback.
 * DateTime values retain their caller-selected timezone (issue/validity dates).
 */
function atlasRentalsFormatDate(string|DateTimeInterface|null $value): string
{
    $calendar = $value instanceof DateTimeInterface ? $value->format('Y-m-d') : ($value ?? '');
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $calendar, $parts) !== 1
        || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) return $calendar;
    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return $months[(int)$parts[2] - 1] . ' ' . (int)$parts[3] . ', ' . $parts[1];
}
