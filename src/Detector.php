<?php

namespace Elrayn\Twins;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

// Every first-level folder under the root is treated as one project.
class Detector
{
    // Third-party code, framework cores and runtime output. Copies of these
    // across projects are expected and not interesting.
    const SKIP_DIRS = ['.git', '.svn', 'vendor', 'node_modules', 'bower_components', 'system', 'third_party', 'logs', 'cache', 'writable', 'uploads', 'storage'];

    // Hand-made backups inside a project: "views_170325", "controllers - Copy",
    // "invoice_100325.php". They're copies of the project's own code and
    // would only drown out copies between projects.
    const BACKUP_DIR = '/(^\.|[_-]\d{6,}$|\s-\s*copy(\s*\(\d+\))?$|backup|bcp|^bak$)/i';
    const BACKUP_FILE = '/([_-]\d{6,}|\s-\s*copy(\s*\(\d+\))?)\.\w+$/i';

    // Projects sharing at least this share of their files are treated as
    // copies of each other (shop and shop_old), see lineages().
    const MIN_LINEAGE_OVERLAP = 0.3;

    // Third-party libraries that tend to get dropped into application/libraries.
    const THIRD_PARTY_DIR = '/^(fpdf|tcpdf|dompdf|mpdf|phpexcel|phpspreadsheet|phpmailer|phpqrcode|qrcode|barcode|excel_reader)/i';

    const EXTENSIONS = ['php', 'js'];

    /** @var string[] when set, only these top-level folders of each project are scanned */
    public array $only = [];

    /** @var string[] when set, only these projects are scanned (used to split work across processes) */
    public array $projects = [];
    const MAX_BYTES = 512 * 1024;

    // A fingerprint that shows up in more distinct files than this is
    // boilerplate ($this->load->view('header') and friends), not evidence
    // of copying.
    const MAX_FILES_PER_FINGERPRINT = 40;

    // Framework scaffolding that every project carries (CodeIgniter's error
    // views, default config, language files). Scanned, but never reported.
    const IGNORE_PATH = '#(^|/)(config|errors|language)/#i';

    // Third-party CodeIgniter libraries that live alongside the app's own
    // code, so the folder rules above don't catch them.
    const KNOWN_LIBRARIES = '#(^|/)(tank_auth/|tank_auth\.php$|phpass[^/]*/|subquery\.php$|recaptcha_helper\.php$)#i';

    /** @var string[] extra paths to leave out of the results, as wildcards ("*\/legacy/*") */
    public array $exclude = [];

    const MIN_SHARED = 10;
    const MIN_SCORE = 0.5;

    /** @var array<int, array{project: string, path: string, lines: int, content: int}> */
    public array $files = [];

    /** @var array<int, array<int, true>> content id => fingerprints */
    public array $fingerprints = [];

    /** @var array<int, int[]> content id => file ids */
    public array $contentFiles = [];

    /** @var array<string, int> */
    protected array $contentIds = [];

    protected ?array $lineages = null;

    public function scan(string $root, ?callable $progress = null): void
    {
        $root = rtrim(str_replace('\\', '/', realpath($root)), '/');

        foreach ($this->walk($root) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            $relative = substr($path, strlen($root) + 1);
            $project = strstr($relative, '/', true);

            if ($project === false) {
                continue;
            }

            $code = (string) file_get_contents($path);
            $tokens = Tokenizer::tokens($path, $code);
            $key = md5(implode("\x1f", $tokens));

            if (!isset($this->contentIds[$key])) {
                $id = count($this->contentIds);
                $this->contentIds[$key] = $id;
                $this->fingerprints[$id] = Fingerprint::of($tokens);
                $this->contentFiles[$id] = [];
            }

            $fileId = count($this->files);
            $this->files[$fileId] = [
                'project' => $project,
                'path' => substr($relative, strlen($project) + 1),
                'lines' => substr_count($code, "\n") + 1,
                'content' => $this->contentIds[$key],
            ];
            $this->contentFiles[$this->contentIds[$key]][] = $fileId;

            if ($progress !== null && $fileId % 2000 === 0) {
                $progress($fileId, $relative);
            }
        }
    }

    // Folds in the result of another scan (of other projects), so the
    // reading can be split across processes.
    public function merge(Detector $other): void
    {
        $map = [];
        foreach ($other->contentIds as $key => $id) {
            if (!isset($this->contentIds[$key])) {
                $this->contentIds[$key] = count($this->contentIds);
                $this->fingerprints[$this->contentIds[$key]] = $other->fingerprints[$id];
                $this->contentFiles[$this->contentIds[$key]] = [];
            }
            $map[$id] = $this->contentIds[$key];
        }

        foreach ($other->files as $file) {
            $file['content'] = $map[$file['content']];
            $fileId = count($this->files);
            $this->files[$fileId] = $file;
            $this->contentFiles[$file['content']][] = $fileId;
        }

        $this->lineages = null;
    }

    /**
     * Groups contents that are similar enough into families, keeping only
     * families that span at least two projects.
     *
     * @return Family[]
     */
    public function families(): array
    {
        $ignored = [];
        foreach ($this->contentFiles as $content => $files) {
            $ignored[$content] = true;
            foreach ($files as $id) {
                if (!$this->ignored($this->files[$id]['path'])) {
                    unset($ignored[$content]);
                    break;
                }
            }
        }

        $index = [];
        foreach ($this->fingerprints as $content => $hashes) {
            if (isset($ignored[$content])) {
                continue;
            }
            foreach ($hashes as $hash => $_) {
                $index[$hash][] = $content;
            }
        }

        $shared = [];
        foreach ($index as $contents) {
            $count = count($contents);
            if ($count < 2 || $count > self::MAX_FILES_PER_FINGERPRINT) {
                continue;
            }
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $key = $contents[$i] . ':' . $contents[$j];
                    $shared[$key] = ($shared[$key] ?? 0) + 1;
                }
            }
        }
        unset($index);

        $parent = [];
        // Union-find with path halving.
        $find = function (int $x) use (&$parent): int {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }
            return $x;
        };

        foreach ($shared as $key => $count) {
            if ($count < self::MIN_SHARED) {
                continue;
            }
            [$a, $b] = array_map('intval', explode(':', $key));
            $smaller = min(count($this->fingerprints[$a]), count($this->fingerprints[$b]));

            if ($count / $smaller >= self::MIN_SCORE) {
                $parent[$a] = $parent[$a] ?? $a;
                $parent[$b] = $parent[$b] ?? $b;
                $parent[$find($a)] = $find($b);
            }
        }

        $groups = [];
        foreach (array_keys($parent) as $content) {
            $groups[$find($content)][] = $content;
        }

        // Identical copies never become pairs (they share one content id),
        // so add the single-content families separately.
        foreach ($this->contentFiles as $content => $files) {
            if (!isset($parent[$content]) && !isset($ignored[$content]) && count($files) > 1) {
                $groups['same:' . $content] = [$content];
            }
        }

        $families = [];
        foreach ($groups as $contents) {
            $family = new Family($this, $contents);
            if (count($family->lineages()) >= 2) {
                $families[] = $family;
            }
        }

        return $families;
    }

    /**
     * Projects that are wholesale copies of each other (shop and shop_old,
     * crm and crm_new) end up in the same lineage. Without this every file
     * of a copied project shows up as its own family and buries the
     * interesting ones: a module copied into otherwise unrelated projects.
     *
     * Evidence is files that are byte-for-byte identical (after removing
     * comments and whitespace) in both projects. Files found in more than 10
     * projects are left out: those are shared libraries, not a sign that
     * two projects are the same project.
     *
     * @return array<string, string> project => lineage id (its first project)
     */
    public function lineages(): array
    {
        if ($this->lineages !== null) {
            return $this->lineages;
        }

        $sizes = [];
        foreach ($this->files as $file) {
            $sizes[$file['project']][$file['content']] = true;
        }
        $sizes = array_map('count', $sizes);

        $shared = [];
        foreach ($this->contentFiles as $files) {
            $projects = [];
            foreach ($files as $id) {
                $projects[$this->files[$id]['project']] = true;
            }
            $projects = array_keys($projects);
            $count = count($projects);
            if ($count < 2 || $count > 10) {
                continue;
            }
            sort($projects);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $key = $projects[$i] . "\0" . $projects[$j];
                    $shared[$key] = ($shared[$key] ?? 0) + 1;
                }
            }
        }

        $parent = [];
        foreach (array_keys($sizes) as $project) {
            $parent[$project] = $project;
        }
        $find = function (string $x) use (&$parent): string {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }
            return $x;
        };

        foreach ($shared as $key => $count) {
            [$a, $b] = explode("\0", $key);
            if ($count / min($sizes[$a], $sizes[$b]) >= self::MIN_LINEAGE_OVERLAP) {
                $parent[$find($a)] = $find($b);
            }
        }

        $lineages = [];
        foreach (array_keys($parent) as $project) {
            $lineages[$project] = $find($project);
        }

        return $this->lineages = $lineages;
    }

    // Scanned but never reported. Checked when grouping rather than while
    // reading, so changing the rules doesn't need a new scan of a cache.
    protected function ignored(string $path): bool
    {
        if (preg_match(self::IGNORE_PATH, $path) || preg_match(self::KNOWN_LIBRARIES, $path)) {
            return true;
        }
        foreach (array_slice(explode('/', $path), 0, -1) as $dir) {
            if (preg_match(self::BACKUP_DIR, $dir)) {
                return true;
            }
        }
        foreach ($this->exclude as $pattern) {
            if (fnmatch(strtolower($pattern), strtolower($path))) {
                return true;
            }
        }

        return false;
    }

    /** @return iterable<SplFileInfo> */
    protected function walk(string $root): iterable
    {
        $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator($directory, function (SplFileInfo $file) use ($root) {
            $depth = substr_count(substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1), '/');

            if ($depth === 0 && $this->projects !== []) {
                return $file->isDir() && in_array($file->getFilename(), $this->projects, true);
            }

            // Directly inside a project: apply --only before descending.
            if ($depth === 1 && $this->only !== []) {
                return $file->isDir() && in_array($file->getFilename(), $this->only, true);
            }

            if ($file->isDir()) {
                return !in_array(strtolower($file->getFilename()), self::SKIP_DIRS, true)
                    && !preg_match(self::BACKUP_DIR, $file->getFilename())
                    && !preg_match(self::THIRD_PARTY_DIR, $file->getFilename());
            }

            return in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)
                && substr($file->getFilename(), -7) !== '.min.js'
                && !preg_match(self::BACKUP_FILE, $file->getFilename())
                && $file->getSize() <= self::MAX_BYTES;
        });

        return new RecursiveIteratorIterator($filter);
    }
}
