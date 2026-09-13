<?php

declare(strict_types=1);

namespace CanonicalMapper\Infrastructure\Cli;

/**
 * What the shell is told, and the one place in this project where the whole
 * argument becomes a number.
 *
 * Three outcomes and three codes, and the distance between the second and the
 * third is the repository's entire claim. A file that could not be read is a
 * failure: nothing was produced, and whatever runs this next should stop. A file
 * that was read and left one product in question is not a failure — it produced
 * a menu and a list of questions — and a pipeline that treated the two the same
 * would either halt on every ambiguity or publish under every one of them.
 *
 * Three rather than two, so that "needs a human" is distinguishable from
 * "worked" as well: a caller that only checks for zero would otherwise never
 * learn that a report is waiting. A caller that does not care can test for
 * non-zero and get the cautious reading, which is the right default for a
 * caller that has not thought about it.
 *
 * A usage error shares Refused with an unreadable export. It is the honest fit
 * of the three: nothing was produced, so it is not Resolved, and a run that
 * never started cannot be Withheld — Withheld means the mapping completed.
 */
enum ExitCode: int
{
    /** Every item resolved. The menu on stdout is the whole assortment. */
    case Resolved = 0;

    /** Nothing was produced: the arguments or the export could not be used. */
    case Refused = 1;

    /** The mapping completed and at least one item is waiting on a person. */
    case Withheld = 3;
}
