<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use Exception;
use RobiNN\Pca\Config;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;

trait RedisKeysList {
    /**
     * @param bool $all Ignore the scan limit (export must not be truncated).
     *
     * @return array<int, string>
     *
     * @throws Exception
     */
    public function getAllKeys(bool $all = false): array {
        $search = (string) Http::get('s', '', true);
        $this->template->addGlobal('search_value', $search);

        $filter = $this->searchPattern($search);
        $scansize = Config::get('redisoptions.scansize');
        $scan_threshold = Config::get('redisoptions.scanthreshold', 100_000);

        if ($scansize !== null || $this->redis->databaseSize() > $scan_threshold || !$this->isCommandSupported('KEYS')) {
            return $this->redis->scanKeys($filter, (int) ($scansize ?? 1000), $all ? PHP_INT_MAX : null);
        }

        return $this->redis->keys($filter);
    }

    private function searchPattern(string $search): string {
        $search = trim($search);

        if ($search === '') {
            return '*';
        }

        return preg_match('/[*?\[\]]/', $search) === 1 ? $search : '*'.$search.'*';
    }

    public function isCommandSupported(string $command): bool {
        static $supported = [];

        try {
            return $supported[$command] ??= $this->redis->commandExists(strtolower($command));
        } catch (Exception) {
            return false;
        }
    }

    /**
     * @param array<int|string, mixed> $keys
     *
     * @return array<int|string, mixed>
     *
     * @throws Exception
     */
    private function pipeline(array $keys): array {
        $data = [];

        foreach (array_chunk(array_values($keys), 1000) as $chunk) {
            $data += $this->redis->pipelineKeys($chunk);
        }

        return $data;
    }

    /**
     * A key can disappear between the listing and the pipeline call, so fall back to safe defaults.
     *
     * @param array<int|string, mixed> $pipeline
     *
     * @return array<string, string|int>
     */
    private function keyInfo(array $pipeline, string $key): array {
        $data = $pipeline[$key] ?? [];
        $ttl = $data['ttl'] ?? -1;

        return [
            'bytes_size' => $data['size'] ?? 0,
            'type'       => $data['type'] ?? 'unknown',
            'ttl'        => $ttl === -1 ? 'Doesn\'t expire' : $ttl,
        ];
    }

    /**
     * @param array<int|string, mixed> $keys_array
     *
     * @return array<int, array<string, string|int>>
     *
     * @throws Exception
     */
    public function keysTableView(array $keys_array): array {
        $pipeline = $this->pipeline($keys_array);
        $formatted_keys = [];

        foreach ($keys_array as $key) {
            $formatted_keys[] = [
                'key'   => $key,
                'items' => $pipeline[$key]['count'] ?? null,
                'info'  => ['link_title' => $key] + $this->keyInfo($pipeline, $key),
            ];
        }

        return Helpers::sortKeys($formatted_keys);
    }

    /**
     * @param array<int|string, mixed> $keys_array
     *
     * @return array<int|string, mixed>
     *
     * @throws Exception
     */
    public function keysTreeView(array $keys_array): array {
        $pipeline = $this->pipeline($keys_array);
        $separator = $this->servers[$this->current_server]['separator'] ?? ':';
        $this->template->addGlobal('separator', $separator);

        $keys = [];

        foreach ($keys_array as $key) {
            $keys[] = [
                'key'   => $key,
                'items' => $pipeline[$key]['count'] ?? null,
                'info'  => $this->keyInfo($pipeline, $key),
            ];
        }

        return Helpers::keysTree($keys, $separator);
    }

    /**
     * The keys of one request of the namespace view (or of a namespace delete), from a cursor.
     *
     * A small database is read with one KEYS call, like getAllKeys(). A big one is read with SCAN, 1000 keys per call,
     * for at most ~0.5 s or 20 000 keys: Redis is never blocked for long and one request never holds all the keys.
     * The browser sends the returned cursor back until it is '0'.
     *
     * @return array{0: string, 1: array<int, string>} [next cursor, keys]
     *
     * @throws Exception
     */
    private function keysStep(string $pattern, string $cursor): array {
        $scan_threshold = Config::get('redisoptions.scanthreshold', 100_000);

        if ($cursor === '0' && Config::get('redisoptions.scansize') === null && $this->redis->databaseSize() <= $scan_threshold && $this->isCommandSupported('KEYS')) {
            return ['0', $this->redis->keys($pattern)];
        }

        $deadline = hrtime(true) + 500_000_000;
        $keys = [];

        do {
            [$cursor, $batch] = $this->redis->scanStep($pattern, 1000, $cursor);
            array_push($keys, ...$batch);
        } while ($cursor !== '0' && count($keys) < 20_000 && hrtime(true) < $deadline);

        return [$cursor, $keys];
    }

    /**
     * Glob pattern for all the keys inside a namespace (the namespace itself is matched literally).
     */
    private function namespacePattern(string $namespace): string {
        $separator = $this->servers[$this->current_server]['separator'] ?? ':';

        return addcslashes($namespace.$separator, '*?[]\\').'*';
    }

    /**
     * One request of the namespace view: the keys from the cursor, grouped by the next level below the prefix.
     *
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function keysNamespaceStep(string $prefix, string $cursor): array {
        $separator = $this->servers[$this->current_server]['separator'] ?? ':';

        // At the top level the search box filters the keys, inside a namespace the prefix does.
        $pattern = $prefix === '' ? $this->searchPattern((string) Http::get('s', '', true)) : $this->namespacePattern($prefix);
        [$next, $keys] = $this->keysStep($pattern, $cursor);

        $sizes = [];

        foreach ($this->pipeline($keys) as $key => $info) {
            $sizes[$key] = (int) ($info['size'] ?? 0);
        }

        return ['cursor' => $next, 'scanned' => count($keys)] + Helpers::namespaceBatch($sizes, $prefix, $separator);
    }

    /**
     * One request of a namespace delete (UNLINK frees the memory in the background).
     *
     * @return array{cursor: string, deleted: int}
     *
     * @throws Exception
     */
    private function deleteNamespaceStep(string $namespace, string $cursor): array {
        [$next, $keys] = $this->keysStep($this->namespacePattern($namespace), $cursor);
        $deleted = 0;

        // In a cluster the keys can be in different slots, so they are removed one by one.
        foreach (array_chunk($keys, $this->is_cluster ? 1 : 1000) as $chunk) {
            $result = $this->redis->unlink($chunk);
            $deleted += is_int($result) ? $result : 0;
        }

        return ['cursor' => $next, 'deleted' => $deleted];
    }
}
