# twins

Find the code you copy-pasted between projects, and see how far each copy has drifted.

If you build software for many clients, you probably know how this goes. Client B wants roughly what client A has, so you copy A's project and adjust it. A year later there are forty projects. The invoice module exists in thirty of them, each copy a little different. Somebody fixes a rounding bug in one, and nobody knows which of the others still have it.

Git can't help here. Every project is its own repository, or no repository at all, and nothing records that `clinic/libraries/Billing.php` started out as `shop-a/controllers/Invoice.php`. twins works that out from the code itself.

Point it at the folder that holds all your projects. It tells you:

- **which projects are copies of each other.** `shop-a` and `shop-a-old` count as one lineage, so their shared files don't flood the results.
- **which files were copied across lineages and have drifted since.** You get every copy, how similar each one still is, and a diff against the center copy: the one closest to all the others, usually the original.
- **where a given piece of code lives.** Paste the lines with the bug and get every file that has them, with line numbers.

It reads PHP and JavaScript, and was built on a few dozen CodeIgniter apps, but it doesn't depend on any framework.

## Install

```
composer global require elrayn/twins
```

or per project with `composer require --dev elrayn/twins`, then run `vendor/bin/twins`. It needs PHP 7.4 or later and the `tokenizer` extension, which nearly every PHP install has.

## Scan

Every folder directly inside the one you scan is treated as one project:

```
twins scan ~/projects --html=report.html
```

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

That's the sample in [tests/fixtures/agency](tests/fixtures/agency). `Billing.php` is found even though it was renamed, moved and partly rewritten.

`report.html` is a single file you open in a browser. It has the same results with a filter box, and a diff of every variant against the center. The report contains your code, so treat it like your code: don't publish it if the projects aren't public.

Options:

| Option | |
|---|---|
| `--html=file` | Write the HTML report |
| `--only=application,app` | Only read these folders inside each project. Skipping framework code saves a lot of time |
| `--exclude=*/views/auth/*,*/legacy/*` | Leave matching paths out of the results |
| `--cache=file` | Save the scan. The next run with the same cache skips reading and only regroups, which takes seconds |
| `--jobs=4` | Read with several processes |
| `--top=25`, `--rows=14` | How many families, and files per family, to print |

Some things are skipped without being asked: `vendor`, `node_modules`, `system` and `third_party` folders, minified JS, files over 512 KB, hand-made backups (`controllers - Copy`, `invoice_100325.php`, `models_backup`), a handful of bundled libraries (TCPDF, dompdf, PHPExcel, PHPMailer and friends), and CodeIgniter's own config, error and language files.

## Find

```
twins find ~/projects bug.php
```

`bug.php` holds the lines you're looking for. It doesn't need an opening `<?php`. Pass `-` instead of a file name to paste the lines into the terminal.

```
Found in 4 files in 4 projects:

   100%  clinic             application/libraries/Billing.php:33-38
   100%  shop-a             application/controllers/Invoice.php:37-42
   100%  shop-a-old         application/controllers/Invoice.php:37-42
   100%  shop-b             application/controllers/Invoice.php:41-46
```

The percentage is how much of your snippet the file contains. Matches below 50% are left out; change that with `--min=`. Use `--cache=` here too, or every search reads all the files again.

Give it at least a few lines. Under 20 tokens (a line or two) there's nothing to match on, and twins will say so.

## How it works

Each file is turned into a stream of tokens with comments and whitespace removed, so reformatting a file doesn't change anything. Every run of 20 tokens is hashed, and a sample of those hashes is kept as the file's fingerprints, using the winnowing scheme from [Schleimer, Wilkerson and Aiken (2003)](https://theory.stanford.edu/~aiken/publications/papers/sigmod03.pdf), the paper behind the MOSS plagiarism checker. Any stretch of 27 or more tokens two files have in common is guaranteed to produce a shared fingerprint, wherever it sits in either file.

Two files go into the same family when at least half the fingerprints of the smaller one also appear in the other. Fingerprints found in too many files, like `$this->load->view(...)` boilerplate, are ignored, since they are common rather than copied.

Projects become one lineage when at least 30% of the smaller project's files are identical in both. The center of a family is the copy with the highest total similarity to all the other copies.

## Limits

- **Renaming breaks matches.** twins compares the code as written. A copy where `$this->` became `$this->CI->` on every line, or where all the variables were renamed, can drop below the threshold and go unnoticed. Partial renames are fine.
- **Only PHP and JavaScript** for now. JavaScript comments are stripped with a simple pattern that also eats `//` inside strings. Both sides of a comparison get the same treatment, so it rarely matters.
- **The first scan can be slow** on large folders, mostly from disk reads. On Windows, antivirus scanning of every opened file dominates: about 50,000 files took 25 minutes. Use `--only`, `--jobs` and `--cache`.
- **It finds copies, not bugs.** Similarity says where to look; reading the diff is still up to you.

## License

MIT
