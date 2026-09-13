<?php

declare(strict_types=1);

namespace CanonicalMapper\Infrastructure\Output;

use CanonicalMapper\Domain\Resolution\Flag;
use JsonException;

/**
 * The withheld items as a report, one JSON object per line.
 *
 * JSON lines rather than a JSON document, and the difference matters at the far
 * end of a pipe. A document has to be complete before it can be parsed, so a run
 * over a large export would tell nobody anything until it finished; a line is
 * readable the moment it is written, greppable by reason code without a parser,
 * and appendable to whatever the operator already pipes their logs into. It is
 * also the format that does not tempt anyone to put a count or a summary at the
 * top — a report of "COMPONENT_MISSING x3" that does not say which three is not
 * a work item.
 *
 * Not pretty-printed, for the same reason: one flag, one line. The canonical
 * menu is pretty-printed because it is read during a review as often as it is
 * parsed, and this is read by the line.
 *
 * Every key is always present, including a null sku, so that a consumer reading
 * these can count on the shape. The keys are written out longhand in a fixed
 * order rather than taken from an array's insertion order, which is the same
 * decision the canonical writer makes and for the same reason.
 */
final class FlagReportWriter
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * @param list<Flag> $flags
     *
     * @throws JsonException
     */
    public function write(array $flags): string
    {
        $report = '';

        foreach ($flags as $flag) {
            $report .= json_encode(self::line($flag), self::FLAGS) . "\n";
        }

        // A run that withheld nothing writes nothing at all, rather than an empty
        // line or a cheerful sentence. stderr is where a person looks to find out
        // whether there is work; silence is the answer that costs them nothing to
        // read.
        return $report;
    }

    /**
     * @return array{source: string, sourceProductId: string, sku: string|null, reason: string, detail: string}
     */
    private static function line(Flag $flag): array
    {
        return [
            'source' => $flag->source->value,
            'sourceProductId' => $flag->sourceProductId,
            'sku' => $flag->sku?->value,
            'reason' => $flag->reason->value,
            'detail' => $flag->detail,
        ];
    }
}
