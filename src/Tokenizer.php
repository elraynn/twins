<?php

namespace Elrayn\Twins;

// Turns source into a token stream with comments and whitespace removed, so
// two copies that only differ in formatting or comments look the same.
final class Tokenizer
{
    /** @return string[] */
    public static function tokens(string $path, string $code): array
    {
        return self::withLines($path, $code)[0];
    }

    /**
     * The tokens plus the line each one starts on, to point at where a match
     * sits in a file.
     *
     * @return array{0: string[], 1: int[]}
     */
    public static function withLines(string $path, string $code): array
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'js' ? self::js($code) : self::php($code);
    }

    protected static function php(string $code): array
    {
        $tokens = [];
        $lines = [];
        $line = 1;

        foreach (token_get_all($code) as $token) {
            if (is_string($token)) {
                $tokens[] = $token;
                $lines[] = $line;
                continue;
            }

            [$id, $text, $line] = $token;

            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true)) {
                // Lone string tokens carry no line, so keep counting here.
                $line += substr_count($text, "\n");
                continue;
            }

            // Views are mostly HTML; split it into words so it still counts.
            if ($id === T_INLINE_HTML) {
                [$words, $wordLines] = self::words($text, $line);
                array_push($tokens, ...$words);
                array_push($lines, ...$wordLines);
            } else {
                $tokens[] = $text;
                $lines[] = $line;
            }
            $line += substr_count($text, "\n");
        }

        return [$tokens, $lines];
    }

    protected static function js(string $code): array
    {
        // Naive: also eats "//" inside strings (URLs). Good enough for
        // similarity, it's the same on both sides of a comparison. Block
        // comments keep their newlines so line numbers stay right.
        $code = preg_replace_callback('#/\*.*?\*/#s', function ($m) {
            return str_repeat("\n", substr_count($m[0], "\n"));
        }, $code);
        $code = preg_replace('#//[^\n]*#', '', $code);

        return self::words($code, 1);
    }

    protected static function words(string $text, int $line): array
    {
        preg_match_all('/[A-Za-z_$][\w$]*|\d+(?:\.\d+)?|[^\s\w]/', $text, $m, PREG_OFFSET_CAPTURE);

        $words = [];
        $lines = [];
        $at = 0;
        foreach ($m[0] as [$word, $offset]) {
            $line += substr_count($text, "\n", $at, $offset - $at);
            $at = $offset;
            $words[] = $word;
            $lines[] = $line;
        }

        return [$words, $lines];
    }
}
