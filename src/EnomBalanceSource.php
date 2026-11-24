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
        unset($result['uid'], $result['pw'], $result['password'], $result['token']);

        return $result;
    }
}
