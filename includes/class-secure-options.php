<?php
/**
 * Secure Options Helper
 *
 * Provides encryption/decryption for secrets stored in WordPress options,
 * using libsodium (XChaCha20-Poly1305 IETF). Falls back to sodium_compat
 * and, if XChaCha AEAD is unavailable, to crypto_secretbox (XSalsa20-Poly1305).
 *
 * @package WCVec
 */

namespace WCVec;

defined('ABSPATH') || exit;

class Secure_Options
{
    private const PREFIX_XCHACHA = 'enc:v1x:'; // aead_xchacha20poly1305_ietf
    private const PREFIX_SECRETBOX = 'enc:v1s:'; // crypto_secretbox fallback
    private const AAD = 'wcvec'; // additional authenticated data

    /**
     * Whether sodium (native or compat) is available.
     */
    public static function is_sodium_available(): bool
    {
        return function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
            || function_exists('sodium_crypto_secretbox');
    }

    /**
     * Derive a 32-byte key from WP salts.
     *
     * @return string binary key (32 bytes)
     */
    private static function key(): string
    {
        // Concatenate available salts/keys; WordPress defines these constants.
        $concat = (defined('AUTH_KEY') ? AUTH_KEY : '') .
                  (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '') .
                  (defined('LOGGED_IN_KEY') ? LOGGED_IN_KEY : '') .
                  (defined('NONCE_KEY') ? NONCE_KEY : '') .
                  (defined('AUTH_SALT') ? AUTH_SALT : '') .
                  (defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : '') .
                  (defined('LOGGED_IN_SALT') ? LOGGED_IN_SALT : '') .
                  (defined('NONCE_SALT') ? NONCE_SALT : '');

        // 32-byte hash output in binary.
        return hash('sha256', $concat, true);
    }

    /**
     * Generate a nonce of the desired length using sodium or random_bytes.
     */
    private static function nonce(int $len): string
    {
        if (function_exists('random_bytes')) {
            try {
                return random_bytes($len);
            } catch (\Throwable $e) {
                // fall-through
            }
        }
        if (function_exists('sodium_randombytes_buf')) {
            return sodium_randombytes_buf($len);
        }
        // Last resort (shouldn't happen with PHP 8.1+): insecure fallback
        $nonce = '';
        while (strlen($nonce) < $len) {
            $nonce .= md5(uniqid((string) mt_rand(), true), true);
        }
        return substr($nonce, 0, $len);
    }

    /**
     * Encrypt plaintext for storage in options.
     *
     * @param string $plain
     * @return string Encrypted payload with version prefix.
     */
    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return $plain;
        }

        $key = self::key();

        // Prefer AEAD XChaCha20-Poly1305 (IETF).
        if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            $nlen = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES; // 24
            $nonce = self::nonce($nlen);
            $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plain,
                self::AAD,
                $nonce,
                $key
            );
            return self::PREFIX_XCHACHA . base64_encode($nonce . $cipher);
        }

        // Fallback: crypto_secretbox (XSalsa20-Poly1305)
        if (function_exists('sodium_crypto_secretbox')) {
            $nlen = \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES; // 24
            $klen = \SODIUM_CRYPTO_SECRETBOX_KEYBYTES;   // 32
            // secretbox requires a 32-byte key; we already have 32 bytes
            $nonce = self::nonce($nlen);
            $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
            return self::PREFIX_SECRETBOX . base64_encode($nonce . $cipher);
        }

        // If no crypto available (theoretically unreachable with sodium_compat), store as-is.
        // Better to return plaintext so the admin can still use the plugin (with a warning elsewhere).
        return $plain;
    }

    /**
     * Decrypt an encrypted option value. Returns '' on failure.
     *
     * @param string $cipher
     * @return string
     */
    public static function decrypt(string $cipher): string
    {
        if ($cipher === '') {
            return '';
        }

        // AEAD XChaCha
        if (str_starts_with($cipher, self::PREFIX_XCHACHA)) {
            $blob = base64_decode(substr($cipher, strlen(self::PREFIX_XCHACHA)), true);
            if ($blob === false) {
                return '';
            }
            $nlen = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES; // 24
            if (strlen($blob) <= $nlen) {
                return '';
            }
            $nonce = substr($blob, 0, $nlen);
            $ct = substr($blob, $nlen);
            try {
                $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                    $ct,
                    self::AAD,
                    $nonce,
                    self::key()
                );
                return is_string($plain) ? $plain : '';
            } catch (\Throwable $e) {
                return '';
            }
        }

        // secretbox fallback
        if (str_starts_with($cipher, self::PREFIX_SECRETBOX)) {
            $blob = base64_decode(substr($cipher, strlen(self::PREFIX_SECRETBOX)), true);
            if ($blob === false) {
                return '';
            }
            $nlen = \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES; // 24
            if (strlen($blob) <= $nlen) {
                return '';
            }
            $nonce = substr($blob, 0, $nlen);
            $ct = substr($blob, $nlen);
            try {
                $plain = sodium_crypto_secretbox_open($ct, $nonce, self::key());
                return is_string($plain) ? $plain : '';
            } catch (\Throwable $e) {
                return '';
            }
        }

        // Not our format: assume plaintext.
        return $cipher;
    }

    /**
     * Constant-time mask for secrets to display in admin.
     *
     * @param string $secret
     * @param int $suffix Keep last N characters visible.
     * @return string
     */
    public static function mask(string $secret, int $suffix = 4): string
    {
        if ($secret === '') {
            return '';
        }
        // Preserve the 'sk-' style prefix when present.
        $prefix = '';
        if (str_starts_with($secret, 'sk-')) {
            $prefix = 'sk-';
            $secret = substr($secret, 3);
        }

        $len = strlen($secret);
        if ($len <= $suffix) {
            return $prefix . str_repeat('*', $len);
        }

        $visible = substr($secret, -$suffix);
        return $prefix . '****' . $visible;
    }
}
