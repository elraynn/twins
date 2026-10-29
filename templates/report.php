<?php
/**
 * @var array $stats
 * @var array $groups
 * @var array $families
 * @var \Elrayn\Twins\Family[] $identical
 * @var string $root
 * @var callable $e
 */
$percent = function (?float $sim): string {
    return $sim === null ? '?' : round($sim * 100) . '%';
};
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Twins report</title>
<style>
:root {
  --bg: #f7f6f3; --surface: #ffffff; --text: #1f1e1c; --muted: #6d6a64; --line: #e4e1db;
  --accent: #2f6b5a; --bar-bg: #e9e6e0;
  --add-bg: #e7f5ec; --add-text: #17663a; --del-bg: #fcebea; --del-text: #a8261b; --hunk: #f0eee9;
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #161615; --surface: #1f1f1d; --text: #e8e6e1; --muted: #9a968e; --line: #33322f;
    --accent: #6cc2a6; --bar-bg: #33322f;
    --add-bg: #12301f; --add-text: #8fdcaa; --del-bg: #3a1714; --del-text: #f2a39b; --hunk: #262624;
  }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
main { max-width: 1180px; margin: 0 auto; padding: 32px 16px 80px; }
h1 { font-size: 22px; margin: 0 0 4px; }
h2 { font-size: 15px; margin: 40px 0 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); }
code, pre, .mono { font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; font-size: 12.5px; }
.muted { color: var(--muted); }
.stats { display: flex; flex-wrap: wrap; gap: 28px; margin: 20px 0 0; }
.stats div b { display: block; font-size: 22px; font-variant-numeric: tabular-nums; }
.stats div span { color: var(--muted); font-size: 12px; }
.lineages { display: grid; gap: 6px; }
.lineages div { background: var(--surface); border: 1px solid var(--line); border-radius: 6px; padding: 6px 10px; }
.lineages span + span::before { content: " · "; color: var(--muted); }
input[type=search] { width: 100%; padding: 9px 12px; border: 1px solid var(--line); border-radius: 6px; background: var(--surface); color: var(--text); font: inherit; margin-bottom: 12px; }
details.family { background: var(--surface); border: 1px solid var(--line); border-radius: 8px; margin-bottom: 8px; }
details.family > summary { cursor: pointer; padding: 10px 14px; display: flex; gap: 12px; align-items: baseline; flex-wrap: wrap; }
details.family > summary .rank { color: var(--muted); font-variant-numeric: tabular-nums; min-width: 32px; }
details.family > summary .name { font-weight: 600; }
details.family > summary .meta { color: var(--muted); font-size: 13px; }
details.family[open] > summary { border-bottom: 1px solid var(--line); }
.body { padding: 12px 14px 16px; overflow-x: auto; }
table.files { border-collapse: collapse; width: 100%; }
table.files td, table.files th { padding: 3px 10px 3px 0; text-align: left; vertical-align: middle; white-space: nowrap; }
table.files th { font-weight: 500; color: var(--muted); font-size: 12px; border-bottom: 1px solid var(--line); }
table.files td.num { text-align: right; font-variant-numeric: tabular-nums; }
.bar { display: inline-block; width: 70px; height: 6px; background: var(--bar-bg); border-radius: 3px; vertical-align: middle; margin-right: 6px; }
.bar i { display: block; height: 100%; background: var(--accent); border-radius: 3px; }
.tag { font-size: 11px; padding: 1px 6px; border-radius: 4px; background: var(--accent); color: var(--surface); margin-left: 6px; }
h3 { font-size: 13px; margin: 20px 0 8px; font-weight: 600; }
details.variant { border: 1px solid var(--line); border-radius: 6px; margin-bottom: 6px; }
details.variant > summary { cursor: pointer; padding: 5px 10px; }
.add { color: var(--add-text); }
.del { color: var(--del-text); }
pre.diff { margin: 0; overflow-x: auto; font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; font-size: 12.5px; }
pre.diff > span { display: block; padding-right: 8px; min-width: max-content; }
pre.diff i { display: inline-block; width: 5ch; text-align: right; margin-right: 1ch; color: var(--muted); font-style: normal; user-select: none; }
pre.diff .a { background: var(--add-bg); color: var(--add-text); }
pre.diff .d { background: var(--del-bg); color: var(--del-text); }
pre.diff .g { background: var(--hunk); color: var(--muted); padding-left: 12ch; }
.note { padding: 8px 10px; color: var(--muted); }
table.identical td { padding: 3px 14px 3px 0; white-space: nowrap; }
</style>
</head>
<body>
<main>

<h1>Twins report</h1>
<div class="muted mono"><?= $e($root) ?></div>

<div class="stats">
  <div><b><?= number_format($stats['files']) ?></b><span>files scanned</span></div>
  <div><b><?= number_format($stats['projects']) ?></b><span>projects</span></div>
  <div><b><?= number_format($stats['drifted']) ?></b><span>families of drifted copies</span></div>
  <div><b><?= number_format($stats['identical']) ?></b><span>families of identical copies</span></div>
</div>

<h2>Projects that are copies of each other</h2>
<p class="muted">These share so many files they are counted as one lineage. A file copied between them is not reported; a file copied out of them is.</p>
<div class="lineages">
<?php foreach ($groups as $projects): ?>
  <div class="mono"><?php foreach ($projects as $project): ?><span><?= $e($project) ?></span><?php endforeach ?></div>
<?php endforeach ?>
</div>

<h2>Drifted copies</h2>
<p class="muted">Files that started as the same code and have changed since. The center is the copy closest to all the others: the best guess at the original. Similarity is measured against it.</p>
<input type="search" id="filter" placeholder="Filter by file or project name…" autocomplete="off">

<?php foreach ($families as $n => $family): ?>
<details class="family" data-search="<?= $e(strtolower($family['name'] . ' ' . implode(' ', $family['projects']) . ' ' . implode(' ', array_column($family['files'], 'path')))) ?>">
  <summary>
    <span class="rank">#<?= $n + 1 ?></span>
    <span class="name mono"><?= $e($family['name']) ?></span>
    <span class="meta"><?= $family['lineages'] ?> lineages · <?= count($family['projects']) ?> projects · <?= count($family['files']) ?> files · <?= count($family['variants']) + 1 ?> variants</span>
  </summary>
  <div class="body">
    <table class="files">
      <tr><th>Project</th><th>Lines</th><th>Similarity</th><th>Path</th></tr>
<?php foreach ($family['files'] as $file): $sim = $family['similarity'][$file['content']] ?? null; ?>
      <tr>
        <td class="mono"><?= $e($file['project']) ?></td>
        <td class="num mono"><?= $file['lines'] ?></td>
        <td class="mono"><span class="bar"><i style="width:<?= $sim === null ? 0 : round($sim * 100) ?>%"></i></span><?= $percent($sim) ?></td>
        <td class="mono"><?= $e($file['path']) ?><?php if ($file['content'] === $family['medoid']): ?><span class="tag">center</span><?php endif ?></td>
      </tr>
<?php endforeach ?>
    </table>

    <h3>Each variant compared to the center <span class="muted mono">(<?= $e($family['center']['project'] . '/' . $family['center']['path']) ?>)</span></h3>
<?php foreach ($family['variants'] as $variant): ?>
<?php if (!array_key_exists('hunks', $variant)): ?>
    <div class="note">… <?= count($family['variants']) - \Elrayn\Twins\HtmlReport::MAX_DIFFS ?> more variants not shown.</div>
<?php break; endif ?>
    <details class="variant">
      <summary class="mono">
        <?= $e($variant['file']['project'] . '/' . $variant['file']['path']) ?>
        <span class="muted">· <?= $percent($variant['similarity']) ?></span>
<?php if ($variant['hunks'] !== null): ?>
        <span class="add">+<?= $variant['added'] ?></span> <span class="del">−<?= $variant['removed'] ?></span>
<?php endif ?>
      </summary>
<?php if ($variant['hunks'] === null): ?>
      <div class="note">Too different to show a useful diff (or the file is gone since the scan).</div>
<?php else: ?>
<pre class="diff"><?php
        foreach ($variant['hunks']['list'] as $h => $hunk) {
            if ($h > 0) {
                echo "<span class=g>⋯</span>";
            }
            foreach ($hunk as [$type, $text, $old, $new]) {
                echo $type === '+' ? '<span class=a>' : ($type === '-' ? '<span class=d>' : '<span>'),
                    '<i>', $old, '</i><i>', $new, '</i>', $type === ' ' ? ' ' : ($type === '+' ? '+' : '−'), ' ', $e($text), "</span>";
            }
        }
        if ($variant['hunks']['cut'] > 0) {
            echo '<span class=g>⋯ ', $variant['hunks']['cut'], ' more lines of diff not shown. Compare the two files in an editor.</span>';
        }
?></pre>
<?php endif ?>
    </details>
<?php endforeach ?>
  </div>
</details>
<?php endforeach ?>

<h2>Identical copies</h2>
<p class="muted">Exactly the same code (ignoring comments and whitespace) in several lineages. Nothing has drifted yet, but a fix still has to be made in every place.</p>
<table class="identical mono">
<?php foreach (array_slice($identical, 0, 100) as $family): $files = $family->files(); ?>
  <tr><td><?= $e($family->name()) ?></td><td class="muted"><?= count($family->lineages()) ?> lineages</td><td class="muted"><?= $e(implode(', ', $family->projects())) ?></td></tr>
<?php endforeach ?>
</table>

</main>
<script>
document.getElementById('filter').addEventListener('input', function () {
  var q = this.value.trim().toLowerCase();
  document.querySelectorAll('details.family').forEach(function (el) {
    el.style.display = !q || el.dataset.search.indexOf(q) !== -1 ? '' : 'none';
  });
});
</script>
</body>
</html>
