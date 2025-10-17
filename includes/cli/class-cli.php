<?php
/**
 * WP-CLI commands for WC Vector Indexing
 *
 * @package WCVec
 */

namespace WCVec;

use WP_CLI;
use WP_CLI\Utils;

defined('ABSPATH') || exit;

final class CLI
{
    /**
     * Normalize & chunk a product’s text using current selection and chunking settings.
     *
     * ## OPTIONS
     *
     * --product=<id>
     * : The product ID to process.
     *
     * [--size=<n>]
     * : Target tokens per chunk (default from settings, fallback 800).
     *
     * [--overlap=<n>]
     * : Overlap tokens between chunks (default from settings, fallback 100).
     *
     * [--show-text]
     * : Output the full normalized text.
     *
     * [--show-chunks]
     * : Output each chunk’s text as well.
     *
     * ## EXAMPLES
     *     wp wcvec chunk --product=123
     *     wp wcvec chunk --product=123 --size=600 --overlap=80 --show-chunks
     *
     * @when after_wp_load
     */
    public function chunk($args, $assoc_args)
    {
        $product_id = isset($assoc_args['product']) ? (int) $assoc_args['product'] : 0;
        if ($product_id <= 0) {
            WP_CLI::error('Please provide --product=<id>.');
        }

        if (!function_exists('wc_get_product') || !wc_get_product($product_id)) {
            WP_CLI::error("Product {$product_id} not found.");
        }

        $selection = Options::get_selected_fields();
        $size     = isset($assoc_args['size']) ? (int) $assoc_args['size'] : (int) ($selection['chunking']['size'] ?? 800);
        $overlap  = isset($assoc_args['overlap']) ? (int) $assoc_args['overlap'] : (int) ($selection['chunking']['overlap'] ?? 100);

        $preview = Field_Normalizer::build_preview($product_id, $selection);
        if (empty($preview['ok'])) {
            WP_CLI::error(!empty($preview['message']) ? $preview['message'] : 'Failed to build preview.');
        }

        $text = (string) ($preview['text'] ?? '');
        $model = Options::get_model();
        $dim   = (int) Options::get_dimension();

        $product_sha = Fingerprint::sha_product($text, $selection, ['size'=>$size,'overlap'=>$overlap], $model, $dim);
        $chunks = Chunker::chunk_text($text, $size, $overlap, 4.0);

        WP_CLI::line("Product: {$product_id}");
        WP_CLI::line("Model: {$model} ({$dim} dims)");
        WP_CLI::line("Chunking: size={$size}, overlap={$overlap}");
        WP_CLI::line("Product SHA: {$product_sha}");
        WP_CLI::line("Normalized chars: " . strlen($text));
        WP_CLI::line("Chunks: " . count($chunks));

        // Summarize chunks
        if (!empty($chunks)) {
            $rows = [];
            foreach ($chunks as $row) {
                $sha = Fingerprint::sha_chunk($row['text'], $product_sha, (int) $row['index']);
                $rows[] = [
                    'index' => $row['index'],
                    'chars' => $row['chars'],
                    'approx_tokens' => $row['approx_tokens'],
                    'sha8'  => substr($sha, 0, 8),
                ];
            }
            Utils\format_items('table', $rows, ['index','chars','approx_tokens','sha8']);
        }

        if (!empty($assoc_args['show-text'])) {
            WP_CLI::line("--- Normalized Text ---");
            WP_CLI::line($text);
        }
        if (!empty($assoc_args['show-chunks'])) {
            foreach ($chunks as $row) {
                $sha = Fingerprint::sha_chunk($row['text'], $product_sha, (int) $row['index']);
                WP_CLI::line("--- Chunk #{$row['index']} ({$row['chars']} chars; ~{$row['approx_tokens']} tok) sha={$sha} ---");
                WP_CLI::line($row['text']);
            }
        }

        WP_CLI::success('Chunking complete.');
    }

    /**
     * Request embeddings for a text or file using the configured model.
     *
     * ## OPTIONS
     *
     * [--text=<text>]
     * : Text to embed. Provide either --text or --file.
     *
     * [--file=<path>]
     * : Path to a UTF-8 text file to embed as one input.
     *
     * ## EXAMPLES
     *     wp wcvec embed --text="hello world"
     *     wp wcvec embed --file=/tmp/sample.txt
     *
     * @when after_wp_load
     */
    public function embed($args, $assoc_args)
    {
        $text = isset($assoc_args['text']) ? (string) $assoc_args['text'] : '';
        $file = isset($assoc_args['file']) ? (string) $assoc_args['file'] : '';

        if ($text === '' && $file === '') {
            WP_CLI::error('Provide --text="<content>" or --file=/path/to/file');
        }

        if ($file !== '') {
            if (!file_exists($file) || !is_readable($file)) {
                WP_CLI::error("File not readable: {$file}");
            }
            $text = (string) file_get_contents($file);
        }

        $text = trim($text);
        if ($text === '') {
            WP_CLI::error('No content to embed.');
        }

        $valid = Embeddings::validate_settings();
        if (is_wp_error($valid)) {
            WP_CLI::error($valid->get_error_message());
        }

        $vecs = Embeddings::embed_texts([$text]);
        if (is_wp_error($vecs)) {
            WP_CLI::error($vecs->get_error_message());
        }

        $model = Options::get_model();
        $dim   = (int) Options::get_dimension();

        WP_CLI::line("Model: {$model} ({$dim} dims)");
        WP_CLI::line('Vectors: ' . count($vecs));
        WP_CLI::line('Length[0]: ' . count($vecs[0]));
        // Show first few values for sanity
        $preview = array_slice($vecs[0], 0, 6);
        WP_CLI::line('Head: [' . implode(', ', array_map(static fn($v) => sprintf('%.6f', $v), $preview)) . ']');

        WP_CLI::success('Embedding complete.');
    }
}
