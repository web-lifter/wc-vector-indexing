<?php
/**
 * Chunker: deterministic, approximate token-based chunking with overlap.
 *
 * @package WCVec
 */

namespace WCVec;

defined('ABSPATH') || exit;

final class Chunker
{
    /**
     * Split text into deterministic chunks sized by an approximate token budget,
     * with sentence-aware packing and word-safe overlap between chunks.
     *
     * @param string $text
     * @param int    $target_tokens     Target tokens per chunk (default 800)
     * @param int    $overlap_tokens    Overlap tokens between consecutive chunks (default 100)
     * @param float  $avg_chars_per_tok Approx chars per token (default 4.0)
     * @return array<int, array{index:int,text:string,chars:int,approx_tokens:int}>
     */
    public static function chunk_text(
        string $text,
        int $target_tokens = 800,
        int $overlap_tokens = 100,
        float $avg_chars_per_tok = 4.0
    ): array {
        $text = self::normalize_text($text);
        if ($text === '') {
            return [];
        }

        $budget_chars  = max(1, (int) round($target_tokens * $avg_chars_per_tok));
        $overlap_chars = max(0, (int) round($overlap_tokens * $avg_chars_per_tok));

        // Flatten to "units": sentences + explicit paragraph delimiter units ("\n\n")
        $units = self::to_units($text, $budget_chars);

        $chunks = [];
        $buf    = '';

        foreach ($units as $u) {
            // Decide how to append the unit to $buf while preserving paragraph breaks deterministically.
            $candidate = self::append_unit($buf, $u);

            if (strlen($candidate) <= $budget_chars) {
                $buf = $candidate;
                continue;
            }

            // Need to flush $buf as a chunk, then carry overlap tail into the next buffer.
            if ($buf !== '') {
                $chunks[] = $buf;
                $tail     = self::word_safe_tail($buf, $overlap_chars);
                $buf      = $tail;
            }

            // If the single unit is still larger than budget (possible with very long sentences),
            // split it by words into sized fragments and push all but the last directly.
            if ($u !== "\n\n" && strlen(self::append_unit('', $u)) > $budget_chars) {
                foreach (self::split_long_unit($u, $budget_chars) as $frag) {
                    $candidate = self::append_unit($buf, $frag);
                    if (strlen($candidate) > $budget_chars && $buf !== '') {
                        $chunks[] = $buf;
                        $tail     = self::word_safe_tail($buf, $overlap_chars);
                        $buf      = $tail;
                        $candidate = self::append_unit($buf, $frag);
                    }
                    $buf = $candidate;
                }
                continue;
            }

            // Now try adding the original unit into the new buffer (with the carried overlap).
            $candidate = self::append_unit($buf, $u);
            if (strlen($candidate) > $budget_chars && $buf !== '') {
                // Extremely tight case: flush carried overlap alone (rare edge).
                $chunks[] = $buf;
                $buf = '';
                $candidate = self::append_unit($buf, $u);
            }
            $buf = $candidate;
        }

        if ($buf !== '') {
            $chunks[] = $buf;
        }

        // Build structured output
        $out = [];
        foreach ($chunks as $i => $c) {
            $chars = strlen($c);
            $out[] = [
                'index'         => $i,
                'text'          => $c,
                'chars'         => $chars,
                'approx_tokens' => (int) ceil($chars / max(1e-6, $avg_chars_per_tok)),
            ];
        }
        return $out;
    }

    // -----------------
    // Internals/helpers
    // -----------------

    private static function normalize_text(string $t): string
    {
        // Normalize newlines and whitespace, but keep paragraph breaks.
        $t = str_replace(["\r\n", "\r"], "\n", $t);
        $t = str_replace("\t", ' ', $t);
        // Collapse 3+ newlines to exactly two (paragraph delimiter).
        $t = preg_replace("/\n{3,}/u", "\n\n", $t);
        // Trim spaces around lines.
        $lines = array_map(static function ($line) {
            return trim((string) $line);
        }, explode("\n", $t));
        $t = implode("\n", $lines);
        $t = trim($t);
        return $t;
    }

    /**
     * Convert text into sentence units with explicit paragraph delimiter ("\n\n").
     * Ensures any sentence longer than budget is split later by words.
     */
    private static function to_units(string $text, int $budget_chars): array
    {
        $paras = preg_split("/\n{2,}/u", $text) ?: [$text];
        $units = [];
        $pcount = count($paras);

        foreach ($paras as $pi => $p) {
            $p = trim($p);
            if ($p === '') {
                if ($pi < $pcount - 1) {
                    $units[] = "\n\n";
                }
                continue;
            }

            foreach (self::split_sentences($p) as $sent) {
                $sent = trim($sent);
                if ($sent === '') {
                    continue;
                }
                // If sentence still contains very long runs (no punctuation), it's okay; word split later if needed.
                $units[] = $sent;
            }

            if ($pi < $pcount - 1) {
                $units[] = "\n\n";
            }
        }
        return $units;
    }

    /**
     * Sentence splitter that respects common punctuation sets (., !, ?, …) and major Unicode variants.
     */
    private static function split_sentences(string $p): array
    {
        // Keep punctuation with the sentence. Split on punctuation + whitespace.
        $parts = preg_split('/(?<=[\.\!\?\u2026\u3002\uFF01\uFF1F])\s+/u', $p) ?: [$p];
        // Merge accidental empties
        $out = [];
        foreach ($parts as $s) {
            $s = trim($s);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return $out;
    }

    /**
     * Append a unit to a buffer with deterministic spacing.
     * - If unit is "\n\n": force paragraph break.
     * - Else: join with a single space, unless buffer ends with "\n\n" or is empty.
     */
    private static function append_unit(string $buf, string $unit): string
    {
        if ($unit === "\n\n") {
            return rtrim($buf, ' ') . "\n\n";
        }
        if ($buf === '' || substr($buf, -2) === "\n\n") {
            return $buf . $unit;
        }
        return $buf . ' ' . $unit;
    }

    /**
     * Split a single too-long unit by words within budget.
     */
    private static function split_long_unit(string $unit, int $budget_chars): array
    {
        $words = preg_split('/\s+/u', $unit) ?: [$unit];
        $frags = [];
        $buf   = '';

        foreach ($words as $w) {
            $w = trim($w);
            if ($w === '') { continue; }
            $candidate = ($buf === '') ? $w : ($buf . ' ' . $w);
            if (strlen($candidate) <= $budget_chars) {
                $buf = $candidate;
                continue;
            }
            if ($buf !== '') {
                $frags[] = $buf;
                $buf = $w;
                // If a single word is longer than budget, hard-split it.
                if (strlen($w) > $budget_chars) {
                    $frags = array_merge($frags, self::hard_split($w, $budget_chars));
                    $buf = '';
                }
            } else {
                // Word alone over budget → hard split.
                $frags = array_merge($frags, self::hard_split($w, $budget_chars));
                $buf = '';
            }
        }
        if ($buf !== '') {
            $frags[] = $buf;
        }
        return $frags;
    }

    /**
     * Hard split a very long token to fit the budget (rare edge).
     */
    private static function hard_split(string $w, int $budget_chars): array
    {
        $out = [];
        $len = strlen($w);
        for ($i = 0; $i < $len; $i += $budget_chars) {
            $out[] = substr($w, $i, $budget_chars);
        }
        return $out;
    }

    /**
     * Compute a word-safe overlap tail from the end of $text, up to $max_chars.
     */
    private static function word_safe_tail(string $text, int $max_chars): string
    {
        if ($max_chars <= 0 || $text === '') {
            return '';
        }
        $len = strlen($text);
        if ($len <= $max_chars) {
            return $text;
        }
        $start = $len - $max_chars;
        // Move start backward to previous whitespace to avoid mid-word cut.
        for ($i = $len - 1; $i >= $start; $i--) {
            if (preg_match('/\s/u', $text[$i])) {
                $start = $i + 1;
                break;
            }
        }
        $tail = substr($text, $start);
        // Trim leading spaces/newlines to avoid double spacing at next-chunk start.
        return ltrim($tail);
    }
}
