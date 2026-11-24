<?php

declare(strict_types=1);

/**
 * Copyright 2026 TAJHOST (https://tajhost.com)
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Enombalance;

final class BalanceCache
{
    public function __construct(private readonly object $cache)
    {
    }

    /**
     * @param callable(): array<string, mixed> $load
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public function remember(string $key, int $ttl, callable $load): array
    {
        $cached = $this->read($key);
        if (is_array($cached)) {
            return [$cached, true];
        }
        $fresh = $load();
        $this->write($key, $fresh, $ttl);

        return [$fresh, false];
    }

    public function locked(string $key): bool
    {
        return $this->read($key) !== null;
    }

    public function lock(string $key, int $ttl): void
    {
        $this->write($key, '1', $ttl);
    }

    private function read(string $key): mixed
    {
        if (!method_exists($this->cache, 'get')) {
            return null;
        }
        try {
            return $this->cache->get($key);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function write(string $key, mixed $value, int $ttl): void
    {
        if (!method_exists($this->cache, 'set')) {
            return;
        }
        try {
            $this->cache->set($key, $value, $ttl);
        } catch (\Throwable $e) {
            return;
        }
    }
}
