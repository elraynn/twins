<?php

namespace Elrayn\Twins;

// Winnowing (Schleimer, Wilkerson & Aiken 2003, the algorithm behind MOSS):
// hash every run of K consecutive tokens, then keep the smallest hash in each
// window of W hashes. Two files sharing a run of at least K + W - 1 tokens are
// guaranteed to share a fingerprint, no matter where it sits in either file,
// which is what makes a 300-line copy recognizable inside an 870-line one.
final class Fingerprint
{
    const K = 20;
    const W = 8;

    const PRIME = 2147483647;
    const BASE = 31337;

    /**
     * @param string[] $tokens
     * @return array<int, true> fingerprint hashes as keys, for fast intersection
     */
    public static function of(array $tokens): array
    {
        $set = [];
        foreach (self::positions($tokens) as [$hash]) {
            $set[$hash] = true;
        }

        return $set;
    }

    /**
     * Like of(), but keeping where each fingerprint starts.
     *
     * @param string[] $tokens
     * @return array<int, array{0: int, 1: int}> [hash, index of its first token]
     */
    public static function positions(array $tokens): array
    {
        $n = count($tokens);
        if ($n < self::K) {
            return [];
        }

        // The same few thousand tokens ($this, ->, (, ...) make up most of
        // any codebase, so hash each distinct one only once.
        static $cache = [];
        $t = [];
        foreach ($tokens as $token) {
            $t[] = $cache[$token] ?? ($cache[$token] = crc32($token) % self::PRIME);
        }

        // Rolling hash over each K-token run.
        $pow = 1;
        for ($i = 1; $i < self::K; $i++) {
            $pow = $pow * self::BASE % self::PRIME;
        }

        $h = 0;
        for ($i = 0; $i < self::K; $i++) {
            $h = ($h * self::BASE + $t[$i]) % self::PRIME;
        }

        $hashes = [$h];
        for ($i = self::K; $i < $n; $i++) {
            $h = (($h - $t[$i - self::K] * $pow % self::PRIME + self::PRIME) * self::BASE + $t[$i]) % self::PRIME;
            $hashes[] = $h;
        }

        $count = count($hashes);
        if ($count <= self::W) {
            $at = array_keys($hashes, min($hashes))[0];
            return [[$hashes[$at], $at]];
        }

        // Slide the window, rescanning only when the current minimum falls
        // out of it. Rightmost minimum wins ties, as in the paper.
        $selected = [];
        $last = -1;
        $minAt = -1;
        for ($end = self::W - 1; $end < $count; $end++) {
            $start = $end - self::W + 1;
            if ($minAt < $start) {
                $minAt = $start;
                for ($j = $start + 1; $j <= $end; $j++) {
                    if ($hashes[$j] <= $hashes[$minAt]) {
                        $minAt = $j;
                    }
                }
            } elseif ($hashes[$end] <= $hashes[$minAt]) {
                $minAt = $end;
            }
            // Consecutive windows usually pick the same minimum; record it once.
            if ($minAt !== $last) {
                $selected[] = [$hashes[$minAt], $minAt];
                $last = $minAt;
            }
        }

        return $selected;
    }
}
