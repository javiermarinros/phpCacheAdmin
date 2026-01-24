<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca;

class Http {
    private static bool $stop_redirect = false;

    public static function stopRedirect(): void {
        self::$stop_redirect = true;
    }

    /**
     * Generate a query string based on the provided filter and additional parameters.
     *
     * @param array<int|string, string>     $preserve   Parameters to be preserved.
     * @param array<int|string, int|string> $additional Additional parameters with their new value.
     */
    public static function queryString(array $preserve = [], array $additional = []): string {
        $keep = ['dashboard', 'server', 'db', 's', 'sortdir', 'sortcol'];
        $preserve = array_fill_keys(array_merge($keep, $preserve), true);
        $query = [];

        if (!empty($_SERVER['REQUEST_URI'])) {
            $url_parts = parse_url($_SERVER['REQUEST_URI']);

            if (isset($url_parts['query']) && ($url_parts['query'] !== '')) {
                parse_str($url_parts['query'], $query);
                $query = array_intersect_key($query, $preserve);
            }
        }

        // Usar array_merge para que $additional sobrescriba los valores de $query
        $query = array_merge($query, $additional);

        return $query !== [] ? '?'.http_build_query($query) : '';
    }

    /**
     * Get query parameter.
     *
     * @template Type
     *
     * @param Type $default
     * @param bool $raw Si es true, no sanitiza caracteres especiales HTML (útil para búsquedas).
     *
     * @return Type
     */
    public static function get(string $key, $default = null, bool $raw = false) {
        if (!isset($_GET[$key])) {
            return $default;
        }

        if (is_int($default)) {
            $value = filter_var($_GET[$key], FILTER_SANITIZE_NUMBER_INT);

            return (int) $value;
        }

        // Si raw es true, usar FILTER_UNSAFE_RAW para no convertir : a &#58; etc.
        $filter = $raw ? FILTER_UNSAFE_RAW : FILTER_SANITIZE_FULL_SPECIAL_CHARS;

        return filter_var($_GET[$key], $filter);
    }

    /**
     * Get POST value.
     *
     * @template Type
     *
     * @param Type $default
     *
     * @return Type
     */
    public static function post(string $key, $default = null) {
        if (!isset($_POST[$key])) {
            return $default;
        }

        $filter = is_int($default) ? FILTER_SANITIZE_NUMBER_INT : FILTER_UNSAFE_RAW;
        $value = filter_var($_POST[$key], $filter);

        return is_int($default) ? (int) $value : $value;
    }

    /**
     * @param array<int|string, string>     $preserve   Parameters to be preserved.
     * @param array<int|string, int|string> $additional Additional parameters with their new value.
     */
    public static function redirect(array $preserve = [], array $additional = []): void {
        if (self::$stop_redirect === false) {
            $location = self::queryString($preserve, $additional);

            if (!headers_sent()) {
                header('Location: '.$location);
            }

            echo '<script>window.location.replace("'.$location.'");</script>';

            exit;
        }
    }
}
