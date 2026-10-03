<?php

namespace App\Services\Assistant;

use Carbon\CarbonImmutable;

/**
 * Turns a player's free-text booking request into VenueSearchTool arguments:
 * sport, date (YYYY-MM-DD), hour (HH:mm), and optionally city and area.
 */
interface QueryParser
{
    /**
     * Returns only the arguments that could be understood, or null when the parser
     * could not produce an answer at all (e.g. the remote model was unreachable).
     *
     * @return array<string, string>|null
     */
    public function parse(string $queryText, CarbonImmutable $today): ?array;
}
