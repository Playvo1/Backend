<?php

namespace App\Services\Assistant;

use App\Models\Sport;
use App\Models\TimeSlot;
use Carbon\CarbonImmutable;

/**
 * The working notes of BookingAssistant while it answers one message: what it has
 * understood and found so far. Not stored; the conversation keeps only the state.
 */
final class AssistantTurn
{
    public string $source = BookingAssistant::FROM_FALLBACK;

    public string $intent = IntentClassifier::BOOKING_SEARCH;

    public ?Sport $sport = null;

    public ?TimeSlot $slot = null;

    /**
     * @var array<string, string>|null what the message was understood as, when it differs from the state
     */
    public ?array $arguments = null;

    /**
     * @var list<array{slot: TimeSlot, distance_km: ?float, exact: bool}>
     */
    public array $suggestions = [];

    /**
     * @var list<string> quick-reply ids
     */
    public array $options = [];

    /**
     * @var array<string, string> labels for quick replies that aren't fixed (cities)
     */
    public array $optionLabels = [];

    /**
     * @var array{name: string, from: string, to: string}|null
     */
    public ?array $period = null;

    /**
     * A sport the message names (lower-case English) that may be missing from SPORT.
     */
    public ?string $mentionedSport = null;

    public function __construct(
        public string $queryText,
        public readonly CarbonImmutable $now,
        public readonly bool $arabic,
        public ConversationState $state,
    ) {}
}
