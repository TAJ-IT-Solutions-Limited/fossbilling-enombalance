<?php

declare(strict_types=1);

/**
 * Copyright 2026 TAJHOST (https://tajhost.com)
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Enombalance\Api;

class Admin extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Dashboard card. Cached for 15 minutes. Does not take a token.
     *
     * @return array<string, mixed>
     */
    public function card(): array
    {
        return $this->getService()->card(false);
    }

    /**
     * One fresh GetBalance for this staff member per minute.
     *
     * @return array<string, mixed>
     */
    public function refresh(): array
    {
        $this->checkPermissions('enombalance', 'manage');

        return $this->getService()->card(true, (int) $this->getIdentity()->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $this->checkPermissions('enombalance', 'manage');

        return $this->getService()->getSettings();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update_settings($data): bool
    {
        $this->checkPermissions('enombalance', 'manage');
        $threshold = trim((string) ($data['low_balance_threshold'] ?? ''));
        if ($threshold !== '' && !is_numeric($threshold)) {
            throw new \FOSSBilling\InformationException('Threshold must be a number');
        }
        $this->di['mod_service']('extension')->setConfig([
            'ext' => 'mod_enombalance',
            'low_balance_threshold' => $threshold,
        ]);

        return true;
    }
}
