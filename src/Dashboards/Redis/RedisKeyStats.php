<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use Exception;
use RobiNN\Pca\Format;
use RobiNN\Pca\Http;

/**
 * The Size and Hot keys tabs. Both measure a SCAN sample batch by batch and keep only bounded top lists and
 * per-pattern totals, so the memory they use does not grow with the sample size.
 */
trait RedisKeyStats {
    private int $stats_top_keys = 50;

    private int $stats_top_patterns = 50;

    /**
     * Distinct patterns kept before the rest are folded into one row, so a keyspace without structure cannot exhaust memory.
     */
    private int $stats_max_patterns = 5_000;

    private int $stats_batch = 1_000;

    private string $stats_other = '(other patterns)';

    /**
     * @return array{pattern: string, sample_size: int, total_keys: int}
     *
     * @throws Exception
     */
    private function statsForm(): array {
        $pattern = trim((string) Http::get('pattern', '*'));

        return [
            'pattern'     => $pattern !== '' ? $pattern : '*',
            'sample_size' => max(100, min((int) Http::get('sample', $this->default_sample), 500_000)),
            'total_keys'  => $this->redis->databaseSize(),
        ];
    }

    /**
     * SCAN up to $sample_size keys and pass each one, with its pipelineKeys() info, to $collect.
     *
     * @param callable(string, array<string, mixed>): void $collect
     *
     * @return array{scanned: int, complete: bool}
     *
     * @throws Exception
     */
    private function statsScan(string $pattern, int $sample_size, bool $access, callable $collect): array {
        $cursor = '0';
        $seen = 0;
        $scanned = 0;

        do {
            // A small COUNT keeps every SCAN call short on a big database, the sample size only caps the total.
            [$cursor, $keys] = $this->redis->scanStep($pattern, $this->stats_batch, $cursor);
            $keys = array_slice($keys, 0, $sample_size - $seen);
            $seen += count($keys);

            if ($keys === []) {
                continue;
            }

            // A key can expire between the SCAN and the pipeline call, pipelineKeys() leaves it out.
            foreach ($this->redis->pipelineKeys(array_values($keys), $access) as $key => $info) {
                $scanned++;
                $collect((string) $key, $info);
            }
        } while ($cursor !== '0' && $seen < $sample_size);

        return ['scanned' => $scanned, 'complete' => $cursor === '0'];
    }

    /**
     * The shape of a key: numbers become {id} and long hex strings {hash}, so 'user:12:posts' and 'user:345:posts' are both 'user:{id}:posts'.
     */
    private function keyPattern(string $key, string $separator): string {
        $parts = $separator !== '' ? explode($separator, $key) : [$key];

        foreach ($parts as $i => $part) {
            $parts[$i] = (string) preg_replace(['/^[0-9a-f]{16,}$/i', '/(?<![A-Za-z0-9])\d+(?![A-Za-z])/'], ['{hash}', '{id}'], $part);
        }

        return implode($separator, $parts);
    }

    /**
     * @param array<string, mixed> $groups
     */
    private function statsGroupName(array $groups, string $name): string {
        return isset($groups[$name]) || count($groups) < $this->stats_max_patterns ? $name : $this->stats_other;
    }

    private function ttlBucket(int $ttl): string {
        return $ttl < 0 ? 'No expiry' : $this->bucket($ttl, $this->ttl_buckets);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    private function sizeTab(): array {
        $data = $this->statsForm() + ['result' => null, 'tab' => 'size'];

        if (Http::get('run') === null) {
            return $data;
        }

        $separator = (string) ($this->servers[$this->current_server]['separator'] ?? ':');
        $groups = ['prefix' => [], 'pattern' => []];
        $top = [];
        $memory = 0;
        $this->resetTopThresholds();

        // Measure the sample: totals per prefix and per pattern, and the biggest keys.
        $scan = $this->statsScan($data['pattern'], $data['sample_size'], false, function (string $key, array $info) use ($separator, &$groups, &$top, &$memory): void {
            $size = (int) ($info['size'] ?? 0);
            $ttl = (int) ($info['ttl'] ?? -1);
            $bucket = $this->ttlBucket($ttl);
            $pattern = $this->keyPattern($key, $separator);
            $memory += $size;

            foreach (['prefix' => $this->namespaceOf($pattern, $separator), 'pattern' => $pattern] as $level => $name) {
                $name = $this->statsGroupName($groups[$level], $name);
                $group = $groups[$level][$name] ?? ['count' => 0, 'memory' => 0, 'max' => 0, 'ttl' => []];
                $group['count']++;
                $group['memory'] += $size;
                $group['max'] = max($group['max'], $size);
                $group['ttl'][$bucket] = ($group['ttl'][$bucket] ?? 0) + 1;
                $groups[$level][$name] = $group;
            }

            $this->collectTop($top, [
                'key'     => $key,
                'pattern' => $pattern,
                'type'    => (string) ($info['type'] ?? 'unknown'),
                'size'    => $size,
                'items'   => $info['count'] ?? null,
                'ttl'     => $ttl,
            ], 'size', $this->stats_top_keys);
        });

        if ($scan['scanned'] === 0) {
            return $data + ['tab_message' => 'No keys matched the pattern.'];
        }

        // Scale the sample to the whole database. Only possible when the sample is all the keys, or a slice of
        // all of them; a slice of the keys that match a pattern says nothing about how many match in total.
        $factor = match (true) {
            $scan['complete'] => 1.0,
            $data['pattern'] === '*' => max(1.0, $data['total_keys'] / $scan['scanned']),
            default => null,
        };

        // Encoding of the biggest keys, one call each: there are only a few of them.
        $top = $this->topRows($top, 'size', $this->stats_top_keys);

        foreach ($top as $i => $row) {
            try {
                $top[$i]['encoding'] = $this->redis->keyEncoding($row['key']);
            } catch (Exception) {
                $top[$i]['encoding'] = '';
            }
        }

        $ttl_columns = array_merge(['No expiry'], array_keys($this->ttl_buckets));
        $levels = [];

        foreach ($groups as $level => $rows) {
            uasort($rows, static fn (array $a, array $b): int => $b['memory'] <=> $a['memory']);
            $levels[$level] = [
                'total' => count($rows),
                'rows'  => array_map(fn (int|string $name, array $group): array => [
                    'name'       => (string) $name,
                    'count'      => $group['count'],
                    'est_count'  => $factor !== null ? (int) round($group['count'] * $factor) : null,
                    'memory'     => $group['memory'],
                    'est_memory' => $factor !== null ? (int) round($group['memory'] * $factor) : null,
                    'avg'        => (int) round($group['memory'] / $group['count']),
                    'max'        => $group['max'],
                    'percent'    => $this->percent($group['memory'], $memory),
                    'ttl'        => array_map(static fn (string $bucket): int => $group['ttl'][$bucket] ?? 0, $ttl_columns),
                ], array_keys(array_slice($rows, 0, $this->stats_top_patterns, true)), array_slice($rows, 0, $this->stats_top_patterns, true)),
            ];
        }

        $share = $this->percent($scan['scanned'], $data['total_keys']);

        $data['result'] = [
            'factor'      => $factor,
            'complete'    => $scan['complete'],
            'ttl_columns' => $ttl_columns,
            'levels'      => $levels,
            'top'         => $top,
            'tiles'       => [
                [
                    'label' => 'Keys scanned',
                    'value' => Format::number($scan['scanned']),
                    'note'  => ($scan['complete'] ? 'every matching key, ' : 'a sample, ').Format::number($share, 2).'% of '.Format::number($data['total_keys']).' keys in the database',
                ],
                [
                    'label' => 'Memory in sample',
                    'value' => Format::bytes($memory),
                    'note'  => Format::bytes((int) round($memory / $scan['scanned'])).' per key on average',
                ],
                [
                    'label' => 'Estimated memory',
                    'value' => $factor !== null ? Format::bytes((int) round($memory * $factor)) : '-',
                    'note'  => match (true) {
                        $factor === null => 'only for the pattern * or a complete scan',
                        $factor === 1.0 => 'measured, the scan was complete',
                        default => 'the sample scaled x'.Format::number($factor, 2).' to the database size',
                    },
                ],
                [
                    'label' => 'Patterns',
                    'value' => Format::number(count($groups['pattern'])),
                    'note'  => Format::number(count($groups['prefix'])).' prefixes',
                ],
            ],
        ];

        return $data;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    private function hotkeysTab(): array {
        // OBJECT FREQ only works under an LFU policy, OBJECT IDLETIME under every other one. INFO works where CONFIG is blocked.
        try {
            $policy = (string) ($this->redis->getInfo('memory')['maxmemory_policy'] ?? '');
        } catch (Exception) {
            $policy = '';
        }

        $lfu = str_contains($policy, 'lfu');
        $data = $this->statsForm() + ['result' => null, 'tab' => 'hotkeys', 'policy' => $policy, 'lfu' => $lfu];

        if (Http::get('run') === null) {
            return $data;
        }

        $separator = (string) ($this->servers[$this->current_server]['separator'] ?? ':');
        $metric = $lfu ? 'freq' : 'idle';
        $groups = [];
        $top = [];
        $tracked = 0;
        $sum = 0;
        $this->resetTopThresholds();

        // Measure the sample: the access counter (or idle time) of every key, and its total per pattern.
        $scan = $this->statsScan($data['pattern'], $data['sample_size'], true, function (string $key, array $info) use ($separator, $metric, $lfu, &$groups, &$top, &$tracked, &$sum): void {
            $value = $info[$metric] ?? null;

            if (!is_int($value)) {
                return;
            }

            $size = (int) ($info['size'] ?? 0);
            $pattern = $this->keyPattern($key, $separator);
            $name = $this->statsGroupName($groups, $pattern);
            $group = $groups[$name] ?? ['count' => 0, 'sum' => 0, 'best' => $value, 'memory' => 0];
            $group['count']++;
            $group['sum'] += $value;
            $group['best'] = $lfu ? max($group['best'], $value) : min($group['best'], $value);
            $group['memory'] += $size;
            $groups[$name] = $group;
            $tracked++;
            $sum += $value;

            // The lowest idle time is the hottest, so it ranks by its negative.
            $this->collectTop($top, [
                'key'     => $key,
                'pattern' => $pattern,
                'type'    => (string) ($info['type'] ?? 'unknown'),
                'size'    => $size,
                'ttl'     => (int) ($info['ttl'] ?? -1),
                'value'   => $value,
                'rank'    => $lfu ? $value : -$value,
            ], 'rank', $this->stats_top_keys);
        });

        if ($scan['scanned'] === 0) {
            return $data + ['tab_message' => 'No keys matched the pattern.'];
        }

        if ($tracked === 0) {
            return $data + ['tab_message' => 'The server reports neither OBJECT FREQ nor OBJECT IDLETIME for these keys.'];
        }

        // LFU: patterns by their share of all the access counters. Idle time: patterns used most recently on average.
        $rows = [];

        foreach ($groups as $name => $group) {
            $rows[] = [
                'name'    => (string) $name,
                'count'   => $group['count'],
                'avg'     => round($group['sum'] / $group['count'], 1),
                'best'    => $group['best'],
                'memory'  => $group['memory'],
                'sum'     => $group['sum'],
                'percent' => $lfu ? $this->percent($group['sum'], $sum) : $this->percent($group['count'], $tracked),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $lfu ? $b['sum'] <=> $a['sum'] : $a['avg'] <=> $b['avg']);

        $share = $this->percent($scan['scanned'], $data['total_keys']);

        $data['result'] = [
            'metric'   => $metric,
            'top'      => $this->topRows($top, 'rank', $this->stats_top_keys),
            'patterns' => array_slice($rows, 0, $this->stats_top_patterns),
            'total'    => count($rows),
            'tiles'    => [
                [
                    'label' => 'Keys scanned',
                    'value' => Format::number($scan['scanned']),
                    'note'  => ($scan['complete'] ? 'every matching key, ' : 'a sample, ').Format::number($share, 2).'% of '.Format::number($data['total_keys']).' keys in the database',
                ],
                [
                    'label' => 'Eviction policy',
                    'value' => $policy !== '' ? $policy : 'unknown',
                    'note'  => $lfu ? 'ranked by the LFU access counter (OBJECT FREQ)' : 'ranked by idle time (OBJECT IDLETIME)',
                ],
                [
                    'label' => $lfu ? 'Average counter' : 'Average idle time',
                    'value' => $lfu ? Format::number($sum / $tracked, 1) : Format::seconds((int) round($sum / $tracked)),
                    'note'  => 'over '.Format::number($tracked).' keys',
                ],
                [
                    'label' => 'Patterns',
                    'value' => Format::number(count($rows)),
                    'note'  => 'numbers in keys read as {id}',
                ],
            ],
        ];

        return $data;
    }
}
