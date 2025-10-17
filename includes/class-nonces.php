<?php
/**
 * Nonces helper for AJAX actions.
 *
 * @package WCVec
 */

namespace WCVec;

defined('ABSPATH') || exit;

class Nonces {

    public static function action(string $name): string
    {
        return 'wcvec_' . sanitize_key($name);
    }

    public static function create(string $name): string
    {
        return wp_create_nonce(self::action($name));
    }

    public static function verify(string $name, string $nonce): bool
    {
        return (bool) wp_verify_nonce($nonce, self::action($name));
    }
}
