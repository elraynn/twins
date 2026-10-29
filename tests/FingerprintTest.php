<?php

namespace Elrayn\Twins\Tests;

use Elrayn\Twins\Fingerprint;
use PHPUnit\Framework\TestCase;

class FingerprintTest extends TestCase
{
    private function words(int $count, string $prefix): array
    {
        $words = [];
        for ($i = 0; $i < $count; $i++) {
            $words[] = $prefix . ($i * 7919 % 1000);
        }
        return $words;
    }

    public function testTooShortHasNoFingerprints(): void
    {
        $this->assertSame([], Fingerprint::of($this->words(Fingerprint::K - 1, 'x')));
    }

    public function testSharedRunIsFoundWhereverItSits(): void
    {
        $shared = $this->words(Fingerprint::K + Fingerprint::W - 1, 's');

        $a = array_merge($this->words(50, 'a'), $shared, $this->words(10, 'b'));
        $b = array_merge($this->words(5, 'c'), $shared, $this->words(80, 'd'));

        $this->assertNotEmpty(array_intersect_key(Fingerprint::of($a), Fingerprint::of($b)));
    }

    public function testUnrelatedCodeSharesNothing(): void
    {
        $this->assertEmpty(array_intersect_key(
            Fingerprint::of($this->words(200, 'a')),
            Fingerprint::of($this->words(200, 'b'))
        ));
    }

    public function testPositionsPointAtTheHashedTokens(): void
    {
        $tokens = $this->words(100, 't');
        $positions = Fingerprint::positions($tokens);

        $this->assertSame(array_keys(Fingerprint::of($tokens)), array_values(array_unique(array_column($positions, 0))));
        foreach ($positions as [$hash, $at]) {
            $this->assertSame([$hash => true], Fingerprint::of(array_slice($tokens, $at, Fingerprint::K)));
        }
    }
}
