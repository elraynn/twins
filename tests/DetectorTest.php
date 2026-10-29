<?php

namespace Elrayn\Twins\Tests;

use Elrayn\Twins\Detector;
use Elrayn\Twins\Family;
use Elrayn\Twins\Finder;
use PHPUnit\Framework\TestCase;

// tests/fixtures/agency: shop-a, shop-a-old (a full copy of shop-a), shop-b
// (Invoice changed: discount, 12% VAT, print page) and clinic (Invoice
// renamed to libraries/Billing.php and reworked for patients).
class DetectorTest extends TestCase
{
    const ROOT = __DIR__ . '/fixtures/agency';

    private static ?Detector $detector = null;

    private function detector(): Detector
    {
        if (self::$detector === null) {
            self::$detector = new Detector();
            self::$detector->scan(self::ROOT);
        }
        return self::$detector;
    }

    public function testWholeProjectCopiesShareALineage(): void
    {
        $lineages = $this->detector()->lineages();

        $this->assertSame($lineages['shop-a'], $lineages['shop-a-old']);
        $this->assertNotSame($lineages['shop-a'], $lineages['shop-b']);
        $this->assertNotSame($lineages['shop-a'], $lineages['clinic']);
    }

    public function testFindsTheDriftedFamilyIncludingTheRenamedCopy(): void
    {
        $families = $this->detector()->families();
        $this->assertCount(1, $families);

        $family = $families[0];
        $this->assertSame('Invoice.php', $family->name());
        $this->assertFalse($family->identical());
        $this->assertCount(3, $family->lineages());
        $this->assertContains('application/libraries/Billing.php', array_column($family->files(), 'path'));
    }

    public function testCenterIsTheOriginal(): void
    {
        $family = $this->detector()->families()[0];
        $center = $this->detector()->files[$this->detector()->contentFiles[$family->medoid()][0]];
        $this->assertStringStartsWith('shop-a', $center['project']);

        $similarity = $family->similarityToMedoid();
        $byPath = [];
        foreach ($family->files() as $file) {
            $byPath[$file['project']] = $similarity[$file['content']];
        }
        $this->assertSame(1.0, $byPath['shop-a']);
        $this->assertGreaterThan($byPath['clinic'], $byPath['shop-b']);
    }

    public function testExcludedPathsAreNotReported(): void
    {
        $detector = new Detector();
        $detector->scan(self::ROOT);
        $detector->exclude = ['*/libraries/*'];

        $paths = array_column($detector->families()[0]->files(), 'path');
        $this->assertNotContains('application/libraries/Billing.php', $paths);
    }

    public function testFindPointsAtTheLines(): void
    {
        // The discount lines exist only in shop-b.
        $snippet = Finder::fingerprint(implode("\n", [
            "\$discount = (float) \$this->input->post('discount');",
            "\$subtotal = \$subtotal - \$discount;",
            "\$tax = round(\$subtotal * 0.12);",
            "",
            "\$id = \$this->invoice_model->insert([",
            "    'customer_id' => \$this->input->post('customer_id'),",
        ]));

        $found = (new Finder($this->detector(), self::ROOT))->find($snippet);

        $this->assertCount(1, $found);
        $this->assertSame('shop-b', $found[0]['file']['project']);
        $this->assertSame(1.0, $found[0]['coverage']);
        $this->assertGreaterThanOrEqual(28, $found[0]['from']);
        $this->assertLessThanOrEqual(33, $found[0]['to']);
    }

    public function testFindReachesEveryCopyOfSharedCode(): void
    {
        $snippet = Finder::fingerprint(implode("\n", [
            "foreach (\$lines as \$line) {",
            "    \$this->invoice_model->insert_line(\$id, [",
            "        'product_id' => \$line['product_id'],",
            "        'qty' => \$line['qty'],",
            "        'price' => \$line['price'],",
            "    ]);",
            "}",
        ]));

        $found = (new Finder($this->detector(), self::ROOT))->find($snippet);

        $this->assertEqualsCanonicalizing(
            ['shop-a', 'shop-a-old', 'shop-b', 'clinic'],
            array_map(function ($hit) {
                return $hit['file']['project'];
            }, $found)
        );
    }
}
