<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Database;

use Illuminate\Database\SqlServerConnection;

/**
 * SqlServer connection that reports query results to the logger. Only registered when
 * tracing.db_results is on and no other package already provides a connection for this driver.
 */
class TracedSqlServerConnection extends SqlServerConnection
{
    use TracesQueryResults;
}
