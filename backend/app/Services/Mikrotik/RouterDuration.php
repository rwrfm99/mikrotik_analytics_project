<?php

namespace App\Services\Mikrotik;

class RouterDuration
{
    public static function seconds(string $value): int
    {
        if (preg_match('/^(?:(\d+)w)?(?:(\d+)d)?(\d+):(\d{2}):(\d{2})$/D', $value, $m)) {
            if ((int) $m[4] >= 60 || (int) $m[5] >= 60) {
                throw new ApiException('invalid_snapshot');
            }

            return (int) ($m[1] ?: 0) * 604800 + (int) ($m[2] ?: 0) * 86400 + (int) $m[3] * 3600 + (int) $m[4] * 60 + (int) $m[5];
        }
        if ($value !== '' && preg_match('/^(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/D', $value, $m)) {
            return (int) ($m[1] ?? 0) * 604800 + (int) ($m[2] ?? 0) * 86400 + (int) ($m[3] ?? 0) * 3600 + (int) ($m[4] ?? 0) * 60 + (int) ($m[5] ?? 0);
        }
        throw new ApiException('invalid_snapshot');
    }
}
