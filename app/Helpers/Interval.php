<?php

namespace App\Helpers;

class Interval {

    private static function clip(array $window, array $exclusion): ?array
    {
        // No overlap at all — exclusion is entirely before or after the window
        if ($exclusion['end'] <= $window['start'] || $exclusion['start'] >= $window['end']) {
            return null;
        }

        return [
            'start' => $exclusion['start']->max($window['start']),
            'end' => $exclusion['end']->min($window['end']),
        ];
    }


    public static function mergeExclusions(array $range, array $exclusions): array
    {
        $clipped = array_values(array_filter(
            array_map(fn($exclusion) => self::clip($range, $exclusion), $exclusions)
        ));

        usort($clipped, fn($a, $b) => $a['start'] <=> $b['start']);

        $merged = [];
        foreach ($clipped as $exclusion) {
            if (empty($merged)) {
                $merged[] = $exclusion;
                continue;
            }

            $last = &$merged[count($merged) - 1];

            if ($exclusion['start'] <= $last['end']) {
                $last['end'] = $exclusion['end']->max($last['end']);
            } else {
                $merged[] = $exclusion;
            }

            unset($last);
        }

        return $merged;
    }
}
