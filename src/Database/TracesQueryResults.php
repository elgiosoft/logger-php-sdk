<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Database;

use Elgiosoft\Logger\Integrations\DatabaseIntegration;
use Throwable;

/**
 * Hands query results to the database integration right after Laravel returns them, so the
 * "db.query" span of that statement can show its rows / affected-row count.
 *
 * Mixed into thin subclasses of Laravel's connection classes (see DatabaseIntegration::registerConnections()).
 */
trait TracesQueryResults
{
    /**
     * Variadic tail keeps the signature compatible with every Laravel version (13 added $fetchUsing).
     */
    public function select($query, $bindings = [], $useReadPdo = true, ...$rest)
    {
        $rows = parent::select($query, $bindings, $useReadPdo, ...$rest);

        $this->reportQueryResult(fn (DatabaseIntegration $integration) => $integration->attachRows($this->getName(), $rows));

        return $rows;
    }

    public function affectingStatement($query, $bindings = [])
    {
        $affected = parent::affectingStatement($query, $bindings);

        $this->reportQueryResult(fn (DatabaseIntegration $integration) => $integration->attachAffected($this->getName(), (int) $affected));

        return $affected;
    }

    private function reportQueryResult(callable $callback): void
    {
        try {
            if (function_exists('app') && app()->bound(DatabaseIntegration::class)) {
                $callback(app(DatabaseIntegration::class));
            }
        } catch (Throwable) {
            // Never let observability break a query.
        }
    }
}
