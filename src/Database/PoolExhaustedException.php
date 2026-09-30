<?php

declare(strict_types=1);

namespace Loongs\Database;

use RuntimeException;

/** No pooled connection became free within the connection's pool.wait_timeout. */
final class PoolExhaustedException extends RuntimeException
{
}
