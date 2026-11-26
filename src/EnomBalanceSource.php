<?php

declare(strict_types=1);

/**
 * One GetBalance call through the registrar adapter already installed with FOSSBilling.
 *
 * Copyright 2026 TAJHOST (https://tajhost.com)
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Enombalance;

final class EnomBalanceSource
{
    /**
     * @return array<string, mixed>
     */
    public function fetch(object $adapter): array
    {
        if (!method_exists($adapter, 'testApi')) {
            return ['ok' => false, 'error' => 'Could not read Enom balance'];
        }
        $result = $adapter->testApi();
        if (!is_array($result)) {
            return ['ok' => false, 'error' => 'Could not read Enom balance'];
        }
        return self::allow(self::redact($result));
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function redact(array $result): array
    {
        $out = [];
        foreach ($result as $key => $value) {
            if (is_string($key) && self::secret($key)) {
                $out[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::redact($value);
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function allow(array $result): array
    {
        $out = [];
        foreach (['ok', 'balance', 'available_balance', 'error'] as $key) {
            if (array_key_exists($key, $result)) {
                $out[$key] = $result[$key];
            }
        }
        if (isset($out['error']) && is_string($out['error'])) {
            $out['error'] = preg_replace('/(uid|pw|password|token|secret|auth)=[^&\s]+/i', '$1=[redacted]', $out['error']) ?? $out['error'];
        }

        return $out;
    }

    private static function secret(string $key): bool
    {
        $name = strtolower($key);
        foreach (['pass', 'pw', 'token', 'key', 'secret', 'uid', 'auth'] as $part) {
            if (str_contains($name, $part)) {
                return true;
            }
        }

        return false;
    }
}
