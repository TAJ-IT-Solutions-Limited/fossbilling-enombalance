<?php

declare(strict_types=1);

namespace FOSSBilling {
    interface InjectionAwareInterface
    {
        public function setDi(\Pimple\Container $di): void;

        public function getDi(): ?\Pimple\Container;
    }
}

namespace FOSSBilling\Interfaces {
    interface WidgetProviderInterface
    {
        public function getWidgets(): array;
    }
}

namespace Pimple {
    class Container implements \ArrayAccess
    {
        public function offsetExists(mixed $offset): bool
        {
            return false;
        }

        public function offsetGet(mixed $offset): mixed
        {
            return null;
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
        }

        public function offsetUnset(mixed $offset): void
        {
        }
    }
}

namespace {
require dirname(__DIR__) . '/src/RefreshLock.php';
require dirname(__DIR__) . '/src/Service.php';
require dirname(__DIR__) . '/src/EnomBalanceSource.php';

use Box\Mod\Enombalance\RefreshLock;
use Box\Mod\Enombalance\Service;

$failed = 0;

function check(bool $ok, string $message): void
{
    global $failed;
    if ($ok) {
        fwrite(STDOUT, "ok $message\n");

        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL $message\n");
}

check(Service::alertRecipient('accounts@example.com', 'cron@example.com') === 'accounts@example.com', 'company email is the alert recipient');
check(Service::alertRecipient('cron@example.com', 'cron@example.com') === null, 'the cron account is not an alert recipient');
check(Service::alertRecipient('  ', 'cron@example.com') === null, 'an empty company email sends no alert');
check(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/Service.php'), "checkPermissions('enombalance', 'manage')"), 'the balance card asks for the manage permission');
check(RefreshLock::key(4) === 'enombalance.refresh.4' && RefreshLock::key(9) === 'enombalance.refresh.9', 'refresh uses one lock per staff member');
check(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/Service.php'), 'RefreshLock::key($staffId)'), 'refresh is locked per staff member');

if ($failed > 0) {
    exit(1);
}
fwrite(STDOUT, "all passed\n");
}
