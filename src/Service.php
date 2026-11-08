<?php

declare(strict_types=1);

/**
 * Admin dashboard balance for the Enom registrar already configured in FOSSBilling.
 *
 * Copyright 2026 TAJHOST (https://tajhost.com)
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Enombalance;

use FOSSBilling\InjectionAwareInterface;
use FOSSBilling\Interfaces\WidgetProviderInterface;

class Service implements InjectionAwareInterface, WidgetProviderInterface
{
    protected ?\Pimple\Container $di = null;

    private EnomBalanceSource $source;

    public function __construct(?EnomBalanceSource $source = null)
    {
        $this->source = $source ?? new EnomBalanceSource();
    }

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getModulePermissions(): array
    {
        return [
            'manage' => [],
            'manage_settings' => [],
        ];
    }

    public function install(): bool
    {
        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    public function getWidgets(): array
    {
        return [
            ['slot' => 'admin.theme.content.before', 'template' => 'mod_enombalance_balance', 'priority' => 5],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        $config = $this->di['mod_config']('enombalance') ?: [];
        $threshold = $config['low_balance_threshold'] ?? '';

        return [
            'low_balance_threshold' => is_numeric($threshold) ? (string) $threshold : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function card(bool $refresh = false, ?int $staffId = null): array
    {
        $now = new \DateTimeImmutable('now');
        $store = new BalanceCache($this->di['cache']);
        if (!$refresh) {
            [$cached, $hit] = $store->remember('enombalance.card', BalanceCard::CACHE_SECONDS, fn (): array => $this->freshCard($now));
            if ($hit) {
                return $cached;
            }

            return $cached;
        }
        if ($staffId !== null && $store->locked('enombalance.refresh.' . $staffId)) {
            $cached = $this->cacheGet('enombalance.card');
            if (is_array($cached)) {
                return $cached;
            }
        }

        $card = $this->freshCard($now);
        $this->cacheSet('enombalance.card', $card, BalanceCard::CACHE_SECONDS);
        if ($staffId !== null) {
            $this->cacheSet('enombalance.refresh.' . $staffId, '1', 60);
        }

        return $card;
    }

    /**
     * @return array<string, mixed>
     */
    private function freshCard(\DateTimeImmutable $now): array
    {
        $card = BalanceCard::fromResult($this->readBalance(), $this->locale(), $now);
        $threshold = $this->threshold();
        $card = BalanceCard::applyThreshold($card, $threshold);
        $alertKey = 'enombalance.alert.' . $now->format('Y-m-d');
        if (BalanceCard::shouldAlert($threshold, $card, $this->cacheGet($alertKey) ? $now->format('Y-m-d') : null, $now)) {
            $this->notifyLowBalance($card);
            $this->cacheSet($alertKey, '1', 172800);
        }

        return $card;
    }

    /**
     * @return array<string, mixed>
     */
    private function readBalance(): array
    {
        try {
            $registrar = $this->di['db']->findOne('TldRegistrar', 'registrar = ?', ['Enom']);
            if (!$registrar) {
                return ['ok' => false, 'error' => 'Could not read Enom balance'];
            }
            $adapter = $this->di['mod_service']('servicedomain')->registrarGetRegistrarAdapter($registrar);

            return $this->source->fetch($adapter);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not read Enom balance'];
        }
    }

    private function locale(): string
    {
        try {
            $locale = $this->di['locale'] ?? null;
            if (is_object($locale) && method_exists($locale, 'getLocale')) {
                return (string) $locale->getLocale();
            }
        } catch (\Throwable $e) {
            return 'en';
        }

        return 'en';
    }

    private function threshold(): ?float
    {
        $raw = $this->getSettings()['low_balance_threshold'];
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }

    /**
     * @param array<string, mixed> $card
     */
    private function notifyLowBalance(array $card): void
    {
        try {
            $system = $this->di['mod_service']('system');
            $from = (string) ($system->getParams([])['email'] ?? '');
            $admins = $this->di['db']->find('Admin', '1=1');
            $subject = (string) ($card['low_label'] ?? 'Low balance');
            $body = (string) ($card['title'] ?? $subject);
            $email = $this->di['mod_service']('email');
            foreach ($admins as $admin) {
                $to = (string) ($admin->email ?? '');
                if ($to === '' || $from === '') {
                    continue;
                }
                $email->sendMail($to, $from, $subject, $body);
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    private function cacheGet(string $key): mixed
    {
        try {
            $cache = $this->di['cache'] ?? null;
            if (!is_object($cache) || !method_exists($cache, 'get')) {
                return null;
            }

            return $cache->get($key);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function cacheSet(string $key, mixed $value, int $ttl): void
    {
        try {
            $cache = $this->di['cache'] ?? null;
            if (!is_object($cache) || !method_exists($cache, 'set')) {
                return;
            }
            $cache->set($key, $value, $ttl);
        } catch (\Throwable $e) {
            return;
        }
    }
}
