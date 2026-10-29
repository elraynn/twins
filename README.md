# twins

A command-line tool that finds code copied between your projects and shows how much each copy has changed.

I wrote this at work. We have dozens of CodeIgniter apps, and most of them started as a copy of an older one. Over the years the same modules (invoicing, journals, stock posting) ended up in dozens of projects, each edited a bit differently. When I fixed a bug in one of them, I had no good way to know which other projects had the same code.

Git doesn't help with this, because each project is a separate repo (some aren't in git at all). So twins compares the code itself. You give it the folder that contains all your projects, and it reports:

- projects that are basically copies of each other (`shop-a` and `shop-a-old`). These are grouped together so they don't fill the results with every shared file.
- files that were copied between unrelated projects and have changed since, with a similarity score for each copy and a diff against the copy that's closest to all the others.
- every place a piece of code appears, if you give it a snippet. This is the "I just found a bug, where else is it?" case.

It handles PHP and JavaScript. It was built against CodeIgniter apps but doesn't need any framework.

## Install

```
composer global require elrayn/twins
```

Or inside a project: `composer require --dev elrayn/twins`, then run `vendor/bin/twins`. Needs PHP 7.4+ with the tokenizer extension (enabled by default in most installs).

## Scanning

Each folder directly under the path you give it is treated as one project.

```
twins scan ~/projects --html=report.html
```

Running it on the sample projects in [tests/fixtures/agency](tests/fixtures/agency) prints:

```
8 files, 6 distinct, in 4 projects. Scan 0.0s, grouping 0.0s.
1 families of drifted copies, 0 families of identical copies (spanning 2+ lineages).

=== Projects that are copies of each other (1 lineages) ===
  shop-a, shop-a-old

=== Drifted copies (top 25) ===

#1  Invoice.php  —  3 lineages, 4 projects, 4 files, 3 variants
    shop-a-old            67   100%  application/controllers/Invoice.php   <- center
    shop-a                67   100%  application/controllers/Invoice.php   <- center
    shop-b                78    53%  application/controllers/Invoice.php
    clinic                57    34%  application/libraries/Billing.php
```

In the sample, clinic's `Billing.php` is a copy of `Invoice.php` that was renamed, moved to `libraries/` and partly rewritten. It still gets picked up.

The HTML report shows the same thing in a browser, with a search box and a diff for every copy. Keep in mind it includes your source code, so don't upload it anywhere public unless the code is public.

Options:

| Option | What it does |
|---|---|
| `--html=file` | Write an HTML report |
| `--only=application,app` | Only read these folders in each project. Skipping the framework makes scans much faster |
| `--exclude=*/views/auth/*,*/legacy/*` | Hide matching paths from the results |
| `--cache=file` | Save the scan to a file. Later runs with the same file skip reading and take seconds |
| `--jobs=4` | Read files with several processes |
| `--top=25`, `--rows=14` | Number of families, and files per family, to print |

Skipped by default: `vendor`, `node_modules`, `system` and `third_party` folders, `.min.js` files, files over 512 KB, backup copies like `controllers - Copy`, `invoice_100325.php` or `models_backup`, common bundled libraries (TCPDF, dompdf, PHPExcel, PHPMailer, etc.), and CodeIgniter's config, error and language files.

## Searching for a snippet

Put the lines you're looking for in a file (no `<?php` needed) and run:

```
twins find ~/projects bug.php
```

```
Found in 4 files in 4 projects:

   100%  clinic             application/libraries/Billing.php:33-38
   100%  shop-a             application/controllers/Invoice.php:37-42
   100%  shop-a-old         application/controllers/Invoice.php:37-42
   100%  shop-b             application/controllers/Invoice.php:41-46
```

The percentage is how much of the snippet was found in that file. Anything under 50% is hidden; use `--min=` to change it. Use `-` instead of a file name to paste the snippet into the terminal. Pass `--cache=` here too, otherwise every search reads all the files again.

The snippet needs to be at least a couple of lines long (20 tokens). Shorter than that, there's not enough to match.

## How it works

Comments and whitespace are stripped and each file becomes a list of tokens, so reformatting doesn't affect anything. twins hashes every sequence of 20 tokens and keeps a sample of those hashes as the file's fingerprints, using winnowing ([Schleimer, Wilkerson & Aiken, 2003](https://theory.stanford.edu/~aiken/publications/papers/sigmod03.pdf)), the same method the MOSS plagiarism checker uses. If two files share a run of 27 tokens or more, they're guaranteed to share at least one fingerprint, wherever that code is in each file.

Two files are put in the same family when at least half of the smaller file's fingerprints are also in the other one. Fingerprints that appear in a lot of files are ignored, because that's usually framework boilerplate like `$this->load->view(...)` and not actual copying.

Two projects are counted as copies of each other when at least 30% of the smaller one's files are identical in both. The "center" of a family is the copy that is most similar to all the others, which usually turns out to be the original.

## Limitations

- Renamed code is harder to match. If a copy changed `$this->` to `$this->CI->` on every line, or renamed most of its variables, it may fall below the threshold and not show up. A few renames are fine.
- Only PHP and JavaScript so far. JS comments are removed with a simple regex that also strips `//` inside strings. It's the same on both sides of a comparison, so it rarely causes problems.
- The first scan of a big folder takes a while, mainly because of disk reads. On Windows the antivirus checks every file that gets opened: my scan of about 50,000 files took 25 minutes. `--only`, `--jobs` and `--cache` help a lot.
- twins tells you where copies are and how they differ. Whether a difference is a bug or an intentional change, you still have to decide yourself.

## License

MIT
