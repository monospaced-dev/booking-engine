<?php

namespace App\Helpers;

use Carbon\CarbonInterface;

class BookingRange
{
    public static function literal(CarbonInterface $start, CarbonInterface $end): string
    {
        return sprintf('[%s,%s)', $start->format('Y-m-d H:i:sP'), $end->format('Y-m-d H:i:sP'));
    }
}
