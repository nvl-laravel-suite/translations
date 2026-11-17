<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Closure;
use Illuminate\Cache\Lock as CacheLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Support\Facades\Cache;
use Nvl\Support\Config\PackageOptions;
use Nvl\Translations\Exceptions\TranslationsException;
use Nvl\Translations\Exceptions\TranslationWorkspaceLockedException;
use Nvl\Translations\Support\TranslationConfiguration;
use WeakMap;

/**
 * Serializes workspace import, export, prune, scan, edit and adoption operations.
 *
 * Called inside a caller's open transaction on the catalog connection, the lease is attached to that connection's own
 * transaction record and held until its outermost commit or a rollback that discards it, so the operation's row locks
 * are never left behind without the workspace lease; a transaction on another connection never releases it. Later
 * operations anywhere inside the same outer transaction, after a nested transaction that took the lease committed
 * included, reuse the lease while the lock store still names this process its owner. A COMMIT that throws runs no
 * Laravel transaction callback: the next transaction on the connection then acquires a fresh lease, and the abandoned
 * one expires after `nvl-translations.lock.seconds` unless it is released first.
 *
 * @internal
 */
final class TranslationProcessLock
{
    /** @var WeakMap<Connection, array{lock: Lock, transaction: DatabaseTransactionRecord}> Leases held until an open caller transaction ends */
    private WeakMap $held;

    public function __construct()
    {
        $this->held = new WeakMap;
    }

    /**
     * @template TResult
     *
     * @param  Connection  $connection  The catalog connection whose transaction the operation joins
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function execute(string $operation, Connection $connection, Closure $callback): mixed
    {
        $transaction = $this->openTransaction($connection);
        if (isset($this->held[$connection])) {
            $held = $this->held[$connection];
            if ($transaction !== null && $this->encloses($this->outermost($transaction), $held['transaction']) && $this->owns($held['lock'])) {
                return $callback();
            }
            unset($this->held[$connection]);
            if ($this->owns($held['lock'])) {
                $held['lock']->release();
            }
        }

        $lock = $this->acquire($operation);
        if ($transaction === null) {
            try {
                return $callback();
            } finally {
                $lock->release();
            }
        }

        $release = function () use ($connection, $lock): void {
            if (($this->held[$connection]['lock'] ?? null) === $lock) {
                unset($this->held[$connection]);
                $lock->release();
            }
        };
        $this->held[$connection] = ['lock' => $lock, 'transaction' => $transaction];
        // Release on the outermost commit, and on a rollback of any transaction from this one outward: early Laravel 13
        // releases drop a committed savepoint's callbacks without running them when its parent savepoint rolls back.
        $outer = $this->outermost($transaction);
        $outer->addCallback($release);
        for ($record = $transaction; ; $record = $record->parent) {
            $record->addCallbackForRollback($release);
            if ($record === $outer) {
                break;
            }
        }

        return $callback();
    }

    /**
     * Return the innermost open transaction record of this connection that defers callbacks, or null when the
     * connection has none (no transaction, or only a test wrapper transaction).
     */
    private function openTransaction(Connection $connection): ?DatabaseTransactionRecord
    {
        if ($connection->transactionLevel() === 0 || ! app()->bound('db.transactions')) {
            return null;
        }
        $manager = app('db.transactions');
        $name = $connection->getName();
        $level = $connection->transactionLevel();

        return $manager->callbackApplicableTransactions()
            ->last(static fn (DatabaseTransactionRecord $record): bool => $record->connection === $name && $record->level <= $level);
    }

    /**
     * Return the outermost open transaction around the current one whose commit runs this connection's callbacks.
     */
    private function outermost(DatabaseTransactionRecord $current): DatabaseTransactionRecord
    {
        $applicable = app('db.transactions')->callbackApplicableTransactions()->all();
        $outer = $current;
        while ($outer->parent !== null && $outer->parent->level < $outer->level && in_array($outer->parent, $applicable, true)) {
            $outer = $outer->parent;
        }

        return $outer;
    }

    /**
     * Whether the lease's transaction is the outer transaction or nested in it: still open, or a committed child whose
     * row locks the outer transaction keeps. A record abandoned by a failed COMMIT stays pending and becomes the parent
     * of the next transaction at the same level, so levels must decrease.
     */
    private function encloses(DatabaseTransactionRecord $outer, DatabaseTransactionRecord $lease): bool
    {
        $record = $lease;
        while ($record !== $outer && $record->level > $outer->level) {
            if ($record->parent->level >= $record->level) {
                return false;
            }
            $record = $record->parent;
        }

        return $record === $outer;
    }

    private function owns(Lock $lock): bool
    {
        return $lock instanceof CacheLock && $lock->isOwnedByCurrentProcess();
    }

    private function acquire(string $operation): Lock
    {
        $cacheStore = Cache::store(PackageOptions::lockStore('translations'))->getStore();

        if (! $cacheStore instanceof LockProvider) {
            $storeClass = $cacheStore::class;

            throw new TranslationsException(
                "The configured translation lock store [{$storeClass}] does not support atomic locks.",
            );
        }

        $lock = $cacheStore->lock(
            'nvl:translations:workspace',
            TranslationConfiguration::positiveInteger('nvl-translations.lock.seconds', 300),
        );
        $wait = TranslationConfiguration::nonNegativeInteger('nvl-translations.lock.wait_seconds', 0);

        try {
            if ($wait > 0 ? $lock->block($wait) : $lock->get()) {
                return $lock;
            }
        } catch (LockTimeoutException) {
        }

        throw TranslationWorkspaceLockedException::forOperation($operation);
    }
}
