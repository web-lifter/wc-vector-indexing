<?php
/**
 * Minimal HTTP wrapper for remote requests.
 *
 * @package WCVec
 */

namespace WCVec;

defined('ABSPATH') || exit;

class Http {

    /**
     * @param string $url
     * @param array{method?:string,headers?:array,body?:string|array,timeout?:int} $args
     * @return array{code:int, body:string, json:mixed, headers:array}
     */
    public static function request(string $url, array $args = []): array
    {
        $defaults = [
            'method'  => 'GET',
            'headers' => [],
            'timeout' => 20,
        ];

        // JSON encode bodies when array given.
        if (isset($args['body']) && is_array($args['body'])) {
            $defaults['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($args['body']);
        }

        $resp = wp_remote_request($url, array_merge($defaults, $args));
        if (is_wp_error($resp)) {
            return [
                'code'    => 0,
                'body'    => $resp->get_error_message(),
                'json'    => null,
                'headers' => [],
            ];
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        $json = null;
        if (self::looks_like_json($body)) {
            $json = json_decode($body, true);
        }

        return [
            'code'    => (int) $code,
            'body'    => $body,
            'json'    => $json,
            'headers' => wp_remote_retrieve_headers($resp)->getAll(),
        ];
    }

    private static function looks_like_json(string $body): bool
    {
        $c = ltrim($body);
        return $c !== '' && ($c[0] === '{' || $c[0] === '[');
    }
}
