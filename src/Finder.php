<?php

namespace Elrayn\Twins;

// "Where else does this code live?" Takes a snippet, finds every scanned file
// containing something like it, and points at the lines.
class Finder
{
    // Matches closer than this many tokens are treated as one stretch of
    // copied code, see locate().
    const MAX_GAP = 60;

    protected Detector $detector;
    protected string $root;

    public function __construct(Detector $detector, string $root)
    {
        $this->detector = $detector;
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    /**
     * Fingerprints of a snippet. A snippet without "<?php" is read as PHP
     * code anyway, since that's what gets copied out of an editor.
     *
     * @return array<int, true>
     */
    public static function fingerprint(string $snippet, string $language = 'php'): array
    {
        if ($language !== 'js' && strpos($snippet, '<?') === false) {
            $snippet = "<?php\n" . $snippet;
        }

        return Fingerprint::of(Tokenizer::tokens('snippet.' . $language, $snippet));
    }

    /**
     * @param array<int, true> $snippet fingerprints, see fingerprint()
     * @return array<int, array{file: array, coverage: float, from: ?int, to: ?int}> best first
     */
    public function find(array $snippet, float $minCoverage = 0.5): array
    {
        $found = [];
        foreach ($this->detector->fingerprints as $content => $hashes) {
            $shared = 0;
            foreach ($snippet as $hash => $_) {
                if (isset($hashes[$hash])) {
                    $shared++;
                }
            }
            $coverage = (float) $shared / count($snippet);
            if ($coverage < $minCoverage) {
                continue;
            }
            foreach ($this->detector->contentFiles[$content] as $id) {
                $file = $this->detector->files[$id];
                [$from, $to] = $this->locate($file, $snippet);
                $found[] = ['file' => $file, 'coverage' => $coverage, 'from' => $from, 'to' => $to];
            }
        }

        usort($found, function ($a, $b) {
            return [$b['coverage'], $a['file']['project'], $a['file']['path']]
                <=> [$a['coverage'], $b['file']['project'], $b['file']['path']];
        });

        return $found;
    }

    /**
     * The line range of the snippet inside a file: the densest run of
     * matching fingerprints, since bits of a snippet (a common query, a
     * loop header) can also turn up elsewhere in the file.
     *
     * @return array{0: ?int, 1: ?int}
     */
    protected function locate(array $file, array $snippet): array
    {
        $path = $this->root . '/' . $file['project'] . '/' . $file['path'];
        $code = @file_get_contents($path);
        if ($code === false) {
            return [null, null];
        }

        [$tokens, $lines] = Tokenizer::withLines($path, $code);
        $hits = [];
        foreach (Fingerprint::positions($tokens) as [$hash, $at]) {
            if (isset($snippet[$hash])) {
                $hits[] = [$hash, $at];
            }
        }
        if ($hits === []) {
            return [null, null];
        }

        $best = null;
        $run = [];
        foreach ($hits as $i => $hit) {
            if ($run !== [] && $hit[1] - $hits[$i - 1][1] > self::MAX_GAP) {
                $best = $this->denser($best, $run);
                $run = [];
            }
            $run[] = $hit;
        }
        $best = $this->denser($best, $run);

        $last = end($best)[1] + Fingerprint::K - 1;

        return [$lines[$best[0][1]], $lines[min($last, count($lines) - 1)]];
    }

    // The run covering more distinct parts of the snippet.
    protected function denser(?array $a, array $b): array
    {
        if ($a === null) {
            return $b;
        }

        return count(array_unique(array_column($b, 0))) > count(array_unique(array_column($a, 0))) ? $b : $a;
    }
}
