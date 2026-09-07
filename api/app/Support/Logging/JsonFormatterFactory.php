<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;

/**
 * One line of JSON per log record, so production logs are queryable.
 * Applied via the `json` channel in config/logging.php.
 */
final class JsonFormatterFactory
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            // Not every Monolog handler formats its own output (e.g. handlers that
            // forward records verbatim). Skip those rather than fataling on them.
            if (! $handler instanceof FormattableHandlerInterface) {
                continue;
            }

            $handler->setFormatter(new JsonFormatter(
                batchMode: JsonFormatter::BATCH_MODE_JSON,
                appendNewline: true,
            ));
        }
    }
}
