<?php

namespace Elrayn\Twins;

// Renders a scan as one self-contained HTML file: every family with its
// copies, and a diff of each variant against the family's center.
class HtmlReport
{
    // Diffs past this many variants per family add little and bloat the file.
    const MAX_DIFFS = 20;

    // A diff longer than this is a rewrite; the counts tell the story and the
    // rest is better read side by side in an editor.
    const MAX_DIFF_LINES = 150;

    protected Detector $detector;
    protected string $root;

    public function __construct(Detector $detector, string $root)
    {
        $this->detector = $detector;
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    /**
     * @param Family[] $drifted
     * @param Family[] $identical
     */
    public function render(array $drifted, array $identical, array $stats): string
    {
        $groups = [];
        foreach ($this->detector->lineages() as $project => $lineage) {
            $groups[$lineage][] = $project;
        }
        $groups = array_values(array_filter($groups, function ($projects) {
            return count($projects) > 1;
        }));
        foreach ($groups as &$projects) {
            sort($projects);
        }
        unset($projects);

        $families = array_map([$this, 'family'], $drifted);
        $root = $this->root;

        $e = function ($text): string {
            return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        ob_start();
        require __DIR__ . '/../templates/report.php';
        return ob_get_clean();
    }

    protected function family(Family $family): array
    {
        $similarity = $family->similarityToMedoid();
        $medoid = $family->medoid();

        $files = $family->files();
        uasort($files, function ($a, $b) use ($similarity) {
            return ($similarity[$b['content']] ?? 0) <=> ($similarity[$a['content']] ?? 0);
        });

        $center = $this->firstFile($medoid);
        $centerLines = $this->read($center);

        $variants = [];
        foreach ($family->contents() as $content) {
            if ($content !== $medoid) {
                $variants[] = ['file' => $this->firstFile($content), 'similarity' => $similarity[$content] ?? null];
            }
        }
        usort($variants, function ($a, $b) {
            return ($b['similarity'] ?? 0) <=> ($a['similarity'] ?? 0);
        });

        foreach (array_slice(array_keys($variants), 0, self::MAX_DIFFS) as $i) {
            $lines = $this->read($variants[$i]['file']);
            $ops = ($centerLines === null || $lines === null) ? null : Diff::lines($centerLines, $lines);
            $variants[$i]['hunks'] = $ops === null ? null : $this->truncate(Diff::hunks($ops));
            $variants[$i]['added'] = $ops === null ? 0 : count(array_filter($ops, function ($op) {
                return $op[0] === '+';
            }));
            $variants[$i]['removed'] = $ops === null ? 0 : count(array_filter($ops, function ($op) {
                return $op[0] === '-';
            }));
        }

        return [
            'name' => $family->name(),
            'lineages' => count($family->lineages()),
            'projects' => $family->projects(),
            'files' => $files,
            'variants' => $variants,
            'similarity' => $similarity,
            'medoid' => $medoid,
            'center' => $center,
        ];
    }

    // Keeps whole hunks up to MAX_DIFF_LINES; 'cut' counts the lines left out.
    protected function truncate(array $hunks): array
    {
        $kept = [];
        $lines = 0;
        $cut = 0;
        foreach ($hunks as $hunk) {
            if ($lines + count($hunk) > self::MAX_DIFF_LINES && $kept !== []) {
                $cut += count($hunk);
                continue;
            }
            $kept[] = array_slice($hunk, 0, self::MAX_DIFF_LINES);
            $cut += count($hunk) - count(end($kept));
            $lines += count(end($kept));
        }

        return ['list' => $kept, 'cut' => $cut];
    }

    protected function firstFile(int $content): array
    {
        return $this->detector->files[$this->detector->contentFiles[$content][0]];
    }

    /** @return string[]|null null when the file is gone since the scan */
    protected function read(array $file): ?array
    {
        $code = @file_get_contents($this->root . '/' . $file['project'] . '/' . $file['path']);
        if ($code === false) {
            return null;
        }

        return array_map('rtrim', preg_split('/\r\n|\r|\n/', $code));
    }
}
