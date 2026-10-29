<?php

namespace Elrayn\Twins;

// Line diff (Myers 1986, the algorithm behind git diff), for showing how a
// copy drifted from its family's center.
final class Diff
{
    // Past this many edits the two files share little beyond a name, and the
    // trace Myers keeps grows with the square of it.
    const MAX_EDITS = 1500;

    /**
     * @param string[] $a
     * @param string[] $b
     * @return array<int, array{0: string, 1: string}>|null [' ' | '-' | '+', line], null when too different
     */
    public static function lines(array $a, array $b): ?array
    {
        // Copies mostly differ in the middle; trimming the common ends keeps
        // the expensive part small.
        $n = count($a);
        $m = count($b);
        $start = 0;
        while ($start < $n && $start < $m && $a[$start] === $b[$start]) {
            $start++;
        }
        $endA = $n;
        $endB = $m;
        while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) {
            $endA--;
            $endB--;
        }

        $middle = self::myers(array_slice($a, $start, $endA - $start), array_slice($b, $start, $endB - $start));
        if ($middle === null) {
            return null;
        }

        $ops = [];
        for ($i = 0; $i < $start; $i++) {
            $ops[] = [' ', $a[$i]];
        }
        array_push($ops, ...$middle);
        for ($i = $endA; $i < $n; $i++) {
            $ops[] = [' ', $a[$i]];
        }

        return $ops;
    }

    /**
     * Groups the changes into hunks with some unchanged lines around them,
     * like a unified diff.
     *
     * @param array<int, array{0: string, 1: string}> $ops
     * @return array<int, array<int, array{0: string, 1: string, 2: ?int, 3: ?int}>> [type, line, old number, new number]
     */
    public static function hunks(array $ops, int $context = 3): array
    {
        $numbered = [];
        $old = $new = 1;
        foreach ($ops as [$type, $text]) {
            $numbered[] = [$type, $text, $type === '+' ? null : $old, $type === '-' ? null : $new];
            if ($type !== '+') {
                $old++;
            }
            if ($type !== '-') {
                $new++;
            }
        }

        $ranges = [];
        $count = count($numbered);
        foreach ($numbered as $i => [$type]) {
            if ($type === ' ') {
                continue;
            }
            $from = max(0, $i - $context);
            $to = min($count - 1, $i + $context);
            $last = count($ranges) - 1;
            if ($last >= 0 && $from <= $ranges[$last][1] + 1) {
                $ranges[$last][1] = $to;
            } else {
                $ranges[] = [$from, $to];
            }
        }

        return array_map(function ($range) use ($numbered) {
            return array_slice($numbered, $range[0], $range[1] - $range[0] + 1);
        }, $ranges);
    }

    /** @return array<int, array{0: string, 1: string}>|null */
    private static function myers(array $a, array $b): ?array
    {
        $n = count($a);
        $m = count($b);
        $v = [1 => 0];
        $trace = [];

        for ($d = 0; $d <= $n + $m; $d++) {
            if ($d > self::MAX_EDITS) {
                return null;
            }
            $trace[] = $v;
            for ($k = -$d; $k <= $d; $k += 2) {
                $x = ($k === -$d || ($k !== $d && $v[$k - 1] < $v[$k + 1])) ? $v[$k + 1] : $v[$k - 1] + 1;
                $y = $x - $k;
                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    $x++;
                    $y++;
                }
                $v[$k] = $x;
                if ($x >= $n && $y >= $m) {
                    return self::backtrack($trace, $a, $b);
                }
            }
        }

        return [];
    }

    private static function backtrack(array $trace, array $a, array $b): array
    {
        $x = count($a);
        $y = count($b);
        $ops = [];

        for ($d = count($trace) - 1; $d >= 0; $d--) {
            $v = $trace[$d];
            $k = $x - $y;
            $prevK = ($k === -$d || ($k !== $d && $v[$k - 1] < $v[$k + 1])) ? $k + 1 : $k - 1;
            $prevX = $v[$prevK];
            $prevY = $prevX - $prevK;

            while ($x > $prevX && $y > $prevY) {
                $ops[] = [' ', $a[--$x]];
                $y--;
            }
            if ($d > 0) {
                $ops[] = $x === $prevX ? ['+', $b[$prevY]] : ['-', $a[$prevX]];
            }
            $x = $prevX;
            $y = $prevY;
        }

        return array_reverse($ops);
    }
}
