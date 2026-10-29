<?php

namespace Elrayn\Twins;

// A set of files that descend from the same original.
class Family
{
    // Beyond this, pairwise similarity gets too slow for a prototype, and a
    // family that big is usually boilerplate chaining together anyway.
    const MAX_COMPARED = 80;

    protected Detector $detector;
    /** @var int[] */
    protected array $contents;
    protected ?int $medoid = null;
    protected ?array $similarity = null;

    /** @param int[] $contents */
    public function __construct(Detector $detector, array $contents)
    {
        $this->detector = $detector;
        $this->contents = $contents;
    }

    /** @return int[] content ids */
    public function contents(): array
    {
        return $this->contents;
    }

    // Every copy in this family has exactly the same code.
    public function identical(): bool
    {
        return count($this->contents) === 1;
    }

    /** @return array<int, array{project: string, path: string, lines: int, content: int}> */
    public function files(): array
    {
        $files = [];
        foreach ($this->contents as $content) {
            foreach ($this->detector->contentFiles[$content] as $id) {
                $files[$id] = $this->detector->files[$id];
            }
        }
        return $files;
    }

    /** @return string[] */
    public function projects(): array
    {
        return array_values(array_unique(array_column($this->files(), 'project')));
    }

    /** @return string[] distinct lineages, see Detector::lineages() */
    public function lineages(): array
    {
        $lineages = $this->detector->lineages();

        return array_values(array_unique(array_map(function ($project) use ($lineages) {
            return $lineages[$project];
        }, $this->projects())));
    }

    // The most common file name, as a label.
    public function name(): string
    {
        $names = array_count_values(array_map(function ($file) {
            return basename($file['path']);
        }, $this->files()));
        arsort($names);

        return (string) key($names);
    }

    /**
     * Similarity (shared fingerprints / all fingerprints) of each content to
     * the medoid: the copy most similar to all the others, which is the best
     * guess at "the original" without any history to go on.
     *
     * @return array<int, float> content id => 0..1
     */
    public function similarityToMedoid(): array
    {
        if ($this->similarity !== null) {
            return $this->similarity;
        }

        $contents = array_slice($this->contents, 0, self::MAX_COMPARED);
        $best = null;
        $bestTotal = -1.0;
        $matrix = [];

        foreach ($contents as $a) {
            $total = 0.0;
            foreach ($contents as $b) {
                $matrix[$a][$b] = $matrix[$b][$a] ?? $this->jaccard($a, $b);
                $total += $matrix[$a][$b];
            }
            if ($total > $bestTotal) {
                $bestTotal = $total;
                $best = $a;
            }
        }

        $this->medoid = $best;

        return $this->similarity = $matrix[$best] ?? [$best => 1.0];
    }

    public function medoid(): int
    {
        $this->similarityToMedoid();
        return $this->medoid;
    }

    protected function jaccard(int $a, int $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        $fa = $this->detector->fingerprints[$a];
        $fb = $this->detector->fingerprints[$b];
        $shared = count(array_intersect_key($fa, $fb));
        $union = count($fa) + count($fb) - $shared;

        return $union === 0 ? 0.0 : $shared / $union;
    }
}
