<?php

namespace Elrayn\Twins\Tests;

use Elrayn\Twins\Tokenizer;
use PHPUnit\Framework\TestCase;

class TokenizerTest extends TestCase
{
    public function testFormattingAndCommentsDoNotMatter(): void
    {
        $a = "<?php\n\$total = \$qty * \$price; // per line\n";
        $b = "<?php\n/* total */\n\$total   =   \$qty\n    * \$price;\n";

        $this->assertSame(Tokenizer::tokens('a.php', $a), Tokenizer::tokens('b.php', $b));
    }

    public function testTracksLinesOfPhpTokens(): void
    {
        [$tokens, $lines] = Tokenizer::withLines('a.php', "<?php\n\$a = 1;\n\n/* x\n y */\nfoo(\$a);\n");

        $this->assertSame(['$a', '=', '1', ';', 'foo', '(', '$a', ')', ';'], $tokens);
        $this->assertSame([2, 2, 2, 2, 6, 6, 6, 6, 6], $lines);
    }

    public function testSplitsInlineHtmlIntoWords(): void
    {
        [$tokens, $lines] = Tokenizer::withLines('view.php', "<table>\n<tr><td><?= \$name ?></td></tr>\n</table>\n");

        $this->assertSame(['<', 'table', '>', '<', 'tr', '>', '<', 'td', '>'], array_slice($tokens, 0, 9));
        $this->assertSame([1, 1, 1, 2, 2, 2, 2, 2, 2], array_slice($lines, 0, 9));
    }

    public function testJavascriptKeepsLineNumbersAcrossBlockComments(): void
    {
        [$tokens, $lines] = Tokenizer::withLines('app.js', "/* one\n two */\nvar a = 1; // note\nlet b;\n");

        $this->assertSame(['var', 'a', '=', '1', ';', 'let', 'b', ';'], $tokens);
        $this->assertSame([3, 3, 3, 3, 3, 4, 4, 4], $lines);
    }
}
