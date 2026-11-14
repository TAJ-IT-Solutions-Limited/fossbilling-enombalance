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

    public static function onAfterAdminCronRun(\Box_Event $event): void
    {
        $event->getDi()['mod_service']('enombalance')->checkBalanceFromCron();
    }

    public function checkBalanceFromCron(): void
    {
        if (!isset($this->di['cache'])) {
            return;
        }
        $store = new BalanceCache($this->di['cache']);
        if ($store->locked('enombalance.cron')) {
            return;
        }
        $store->lock('enombalance.cron', 3600);
        $now = new \DateTimeImmutable('now');
        $store->remember('enombalance.card', BalanceCard::CACHE_SECONDS, fn (): array => $this->freshCard($now));
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
        unset($staffId);
        $this->assertManage();
        $now = new \DateTimeImmutable('now');
        $store = new BalanceCache($this->di['cache']);
        if (!$refresh) {
            [$cached] = $store->remember('enombalance.card', BalanceCard::CACHE_SECONDS, fn (): array => $this->freshCard($now));

            return $cached;
        }
        if ($store->locked('enombalance.refresh')) {
            $cached = $this->cacheGet('enombalance.card');
            if (is_array($cached)) {
                return $cached;
            }
        }

        $card = $this->freshCard($now);
        $this->cacheSet('enombalance.card', $card, BalanceCard::CACHE_SECONDS);
        $this->cacheSet('enombalance.refresh', '1', 60);

        return $card;
    }

    public static function alertRecipient(?string $companyEmail, ?string $cronEmail): ?string
    {
        $to = trim((string) $companyEmail);
        $cron = trim((string) $cronEmail);
        if ($to === '' || ($cron !== '' && strcasecmp($to, $cron) === 0)) {
            return null;
        }

        return $to;
    }

    private function assertManage(): void
    {
        if (!isset($this->di['mod_service'])) {
            return;
        }
        $staff = $this->di['mod_service']('staff');
        if (is_object($staff) && method_exists($staff, 'checkPermissions')) {
            $staff->checkPermissions('enombalance', 'manage');
        }
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
            $company = is_object($system) && method_exists($system, 'getParamValue')
                ? (string) $system->getParamValue('company_email')
                : '';
            $cron = $this->di['db']->findOne('Admin', 'system_name = ?', ['cron']);
            $cronEmail = is_object($cron) ? (string) ($cron->email ?? '') : '';
            $to = self::alertRecipient($company, $cronEmail);
            if ($to === null) {
                $this->di['logger']->warning('Enom balance alert was not sent');

                return;
            }
            $subject = (string) ($card['low_label'] ?? 'Low balance');
            $body = (string) ($card['title'] ?? $subject);
            $this->di['mod_service']('email')->sendMail($to, $to, $subject, $body);
        } catch (\Throwable $e) {
            if (isset($this->di['logger'])) {
                $this->di['logger']->warning('Enom balance alert was not sent');
            }
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
