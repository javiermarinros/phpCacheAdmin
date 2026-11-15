<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;

trait MemcachedKeysList {
    /**
     * @return array<int, string>
     *
     * @throws MemcachedException
     */
    public function getAllKeys(): array {
        $search = (string) Http::get('s', '', true);
        $this->template->addGlobal('search_value', $search);

        $all_key_lines = $this->memcached->getKeys();

        if ($search === '') {
            return $all_key_lines;
        }

        $filtered_lines = [];

        foreach ($all_key_lines as $line) {
            $space = strpos($line, ' ');
            $key = substr($line, 4, ($space === false ? strlen($line) : $space) - 4);

            if (str_contains($key, '%')) {
                $key = urldecode($key);
            }

            if (Helpers::matchSearchPattern($key, $search)) {
                $filtered_lines[] = $line;
            }
        }

        return $filtered_lines;
    }

    /**
     * @param array<int, string> $raw_lines
     *
     * @return array<int, array<string, mixed>>
     */
    public function keysTableView(array $raw_lines): array {
        $formatted_keys = [];
        $time = time();

        foreach ($raw_lines as $line) {
            $key_data = $this->memcached->parseLine($line);
            $key = urldecode($key_data['key']);
            $ttl = $key_data['exp'] ?? -1;
            $ttl_display = $ttl === -1 ? 'Doesn\'t expire' : $ttl - $time;

            $formatted_keys[] = [
                'key'  => $key,
                'info' => [
                    'link_title'           => $key,
                    'bytes_size'           => $key_data['size'] ?? 0,
                    'timediff_last_access' => $key_data['la'] ?? 0,
                    'ttl'                  => $ttl_display,
                ],
            ];
        }

        return Helpers::sortKeys($formatted_keys);
    }

    /**
     * @param array<int, string> $raw_lines
     *
     * @return array<int|string, mixed>
     * @throws MemcachedException
     */
    public function keysTreeView(array $raw_lines): array {
        $separator = $this->servers[$this->current_server]['separator'] ?? ':';

        $this->template->addGlobal('separator', $separator);

        $time = time();

        $keys = [];

        foreach ($raw_lines as $line) {
            $key_data = $this->memcached->parseLine($line);

            if (!isset($key_data['key'])) {
                continue;
            }

            $ttl = $key_data['exp'] ?? -1;

            $keys[] = [
                'key'  => urldecode($key_data['key']),
                'info' => [
                    'bytes_size'           => $key_data['size'] ?? 0,
                    'timediff_last_access' => $key_data['la'] ?? 0,
                    'ttl'                  => $ttl === -1 ? 'Doesn\'t expire' : $ttl - $time,
                ],
            ];
        }

        return Helpers::keysTree($keys, $separator);
    }

    /**
     * Decoded keys inside a namespace (all keys for an empty namespace), as key => size in bytes.
     *
     * @param array<int, string> $raw_lines
     *
     * @return array<string, int>
     */
    private function namespaceKeys(array $raw_lines, string $namespace): array {
        $separator = $this->servers[$this->current_server]['separator'] ?? ':';
        $keys = [];

        foreach ($raw_lines as $line) {
            $key_data = $this->memcached->parseLine($line);

            if (!isset($key_data['key'])) {
                continue;
            }

            $key = urldecode($key_data['key']);

            if ($namespace === '' || str_starts_with($key, $namespace.$separator)) {
                $keys[$key] = $key_data['size'] ?? 0;
            }
        }

        return $keys;
    }

    /**
     * One request of the namespace view. Memcached has no cursor (all keys come from one metadump), so it is complete at once.
     *
     * @return array<string, mixed>
     *
     * @throws MemcachedException
     */
    public function keysNamespaceStep(string $prefix): array {
        $separator = $this->servers[$this->current_server]['separator'] ?? ':';

        // At the top level the search box filters the keys, inside a namespace the prefix does.
        $keys = $this->namespaceKeys($prefix === '' ? $this->getAllKeys() : $this->memcached->getKeys(), $prefix);

        return ['cursor' => '0', 'scanned' => count($keys)] + Helpers::namespaceBatch($keys, $prefix, $separator);
    }

    /**
     * @return array{cursor: string, deleted: int}
     *
     * @throws MemcachedException
     */
    private function deleteNamespaceStep(string $namespace): array {
        $deleted = 0;

        foreach (array_keys($this->namespaceKeys($this->memcached->getKeys(), $namespace)) as $key) {
            if ($this->memcached->delete((string) $key)) {
                $deleted++;
            }
        }

        return ['cursor' => '0', 'deleted' => $deleted];
    }
}
