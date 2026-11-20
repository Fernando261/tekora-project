<?php

declare(strict_types=1);

namespace Tests\Support\HoldsSqlite;

use RuntimeException;

/** Reconnect and resolve the SAME intention under BEGIN IMMEDIATE before retrying. */
final class HoldCommitUncertain extends RuntimeException {}
