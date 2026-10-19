<?php

declare(strict_types=1);

namespace Contenir\Queue\Job;

use RuntimeException;

/**
 * Thrown by a handler when another attempt cannot succeed (the record it works
 * on is gone, or a remote service rejected the request outright). The job is
 * marked failed straight away instead of being retried.
 */
final class UnrecoverableJobException extends RuntimeException {}
