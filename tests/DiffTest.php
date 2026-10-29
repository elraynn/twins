<?php

namespace Elrayn\Twins\Tests;

use Elrayn\Twins\Diff;
use PHPUnit\Framework\TestCase;

class DiffTest extends TestCase
{
    public function testSmallestEdit(): void
    {
        $ops = Diff::lines(['a', 'b', 'c', 'd'], ['a', 'x', 'c', 'd', 'e']);

        $this->assertSame([[' ', 'a'], ['-', 'b'], ['+', 'x'], [' ', 'c'], [' ', 'd'], ['+', 'e']], $ops);
    }

    public function testBothSidesCanBeRebuilt(): void
    {
        mt_srand(42);
        for ($run = 0; $run < 300; $run++) {
            $a = $this->randomLines();
            $b = $this->randomLines();

            $old = $new = [];
            foreach (Diff::lines($a, $b) as [$type, $line]) {
                if ($type !== '+') {
                    $old[] = $line;
                }
                if ($type !== '-') {
                    $new[] = $line;
                }
            }

            $this->assertSame($a, $old);
            $this->assertSame($b, $new);
        }
    }

    public function testHunksKeepContextAndLineNumbers(): void
    {
        $a = array_map('strval', range(1, 40));
        $b = $a;
        $b[9] = 'changed';
        $b[29] = 'changed too';

        $hunks = Diff::hunks(Diff::lines($a, $b), 2);

        $this->assertCount(2, $hunks);
        $this->assertSame([' ', '8', 8, 8], $hunks[0][0]);
        $this->assertSame(['-', '10', 10, null], $hunks[0][2]);
        $this->assertSame(['+', 'changed', null, 10], $hunks[0][3]);
    }

    private function randomLines(): array
    {
        $lines = [];
        for ($i = mt_rand(0, 25); $i > 0; $i--) {
            $lines[] = chr(97 + mt_rand(0, 4));
        }
        return $lines;
    }
}
