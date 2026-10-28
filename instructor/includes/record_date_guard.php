<?php
declare(strict_types=1);

/**
 * Recording dates: allow today and past calendar days; disallow future dates (server-local date).
 *
 * @param string $dateYmd Date or datetime string; only the Y-m-d part is compared.
 */
function record_date_is_not_future(string $dateYmd): bool
{
    $dateYmd = trim($dateYmd);
    if ($dateYmd === '') {
        return false;
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $dateYmd, $m)) {
        $dateYmd = $m[1];
    } else {
        $ts = strtotime($dateYmd);
        if ($ts === false) {
            return false;
        }
        $dateYmd = date('Y-m-d', $ts);
    }
    return $dateYmd <= date('Y-m-d');
}
