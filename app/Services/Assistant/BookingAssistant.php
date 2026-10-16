<?php

namespace App\Services\Assistant;

use App\Models\City;
use App\Models\Sport;
use App\Models\TimeSlot;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Answers a player's booking question (US-4.5): understands it with Gemini when
 * available, falls back to the rule-based parser otherwise, searches real available
 * slots and replies in the player's language. AssistantConversations keeps the
 * conversation and logs each query to ASSISTANT_QUERY.
 *
 * Version 2 reads each message against the conversation so far (ConversationState): a
 * follow-up such as "8" completes "football tomorrow", "لا خليها بعد بكرا" changes the day.
 * It also answers a span of days ("this week"), lists up to five options ranked by rating,
 * price or distance, searches near the player's shared location, offers quick replies, and
 * answers the questions around a booking (prices and working hours live from the time slots,
 * policies from resources/assistant/faq.json).
 */
class BookingAssistant
{
    /**
     * The arguments a search can't run without; the fallback fills them if Gemini left one out.
     */
    private const REQUIRED_ARGUMENTS = ['sport', 'date', 'hour'];

    /**
     * Where the free hours offered with "which hour?" start and end ([from, until), HH:mm),
     * by the part of the day the player asked for.
     */
    private const PARTS_OF_DAY = [
        'morning' => ['05:00', '12:00'],
        'afternoon' => ['12:00', '17:00'],
        'evening' => ['17:00', null],
    ];

    /**
     * How many options a reply lists at most.
     */
    public const MAX_SUGGESTIONS = 5;

    /**
     * What the reply did: suggested a venue, found none, asked for the sport, day or hour, or
     * said the place isn't covered.
     */
    public const SUGGESTION = 'suggestion';

    public const NO_MATCH = 'no_match';

    public const NEEDS_DETAILS = 'needs_details';

    /**
     * The city or area the player named has no venues (yet).
     */
    public const NOT_COVERED = 'not_covered';

    /**
     * "Near me" without a shared location: the reply asks for it.
     */
    public const NEEDS_LOCATION = 'needs_location';

    /**
     * The sport named is not offered by any venue yet ("coming soon").
     */
    public const NOT_OFFERED = 'not_offered';

    /**
     * A question answered without a search (FAQ, prices, working hours, small talk).
     */
    public const ANSWER = 'answer';

    /**
     * Who understood the question.
     */
    public const FROM_GEMINI = 'gemini';

    public const FROM_FALLBACK = 'fallback';

    /**
     * Quick replies the player can send back as option_id (a city is "city_<CITY.id>").
     */
    public const OPTIONS = ['use_location', 'pick_area', 'all_venues', 'cheapest', 'best_rated', 'new_search'];

    /**
     * Sports this assistant has words for but the SPORT table may not have yet.
     */
    private const SPORT_NAMES = [
        'football' => ['كرة القدم', 'Football'],
        'basketball' => ['كرة السلة', 'Basketball'],
        'volleyball' => ['الكرة الطائرة', 'Volleyball'],
        'tennis' => ['التنس', 'Tennis'],
        'padel' => ['البادل', 'Padel'],
    ];

    public function __construct(
        private readonly GeminiQueryParser $gemini,
        private readonly RuleBasedQueryParser $rules,
        private readonly VenueSearchTool $tool,
        private readonly ReplyComposer $replies,
        private readonly IntentClassifier $intents,
        private readonly PeriodParser $periods,
        private readonly AssistantKnowledge $knowledge,
    ) {}

    /**
     * Understands and answers one message at the players' local time $now, without logging
     * it. $state is what earlier turns understood (empty for a first message); the returned
     * state is what the next turn starts from. The evaluation (assistant:evaluate) uses it
     * with the fixed reference time of the test cases, and with $useGemini false to measure
     * the rule-based fallback alone.
     *
     * @param  array{option_id?: ?string, latitude?: float|string|null, longitude?: float|string|null, history?: list<array{query: string, reply: string}>, language?: ?string}  $context
     * @return array{arguments: array<string, string>, source: string, sport: ?Sport, slot: ?TimeSlot, outcome: string, reply_text: string,
     *               intent: string, state: ConversationState, suggestions: list<array{slot: TimeSlot, distance_km: ?float, exact: bool}>,
     *               options: list<string>, period: ?array{name: string, from: string, to: string}, arabic: bool}
     */
    public function respond(string $queryText, CarbonImmutable $now, bool $useGemini = true, ?ConversationState $state = null, array $context = []): array
    {
        $state ??= ConversationState::empty();
        $arabic = $this->isArabic($queryText, $state, $context['language'] ?? null);
        $state = $state->with(['language' => $arabic ? 'ar' : 'en', ...$this->sharedLocation($context)]);
        $turn = new AssistantTurn($queryText, $now, $arabic, $state);

        if (filled($context['option_id'] ?? null)) {
            return $this->chooseOption((string) $context['option_id'], $turn);
        }

        $read = $this->intents->read($queryText);

        if ($read['text'] === '') {
            return $this->answer($turn, IntentClassifier::HELP, $this->knowledge->answer('help', $arabic));
        }

        if ($read['reset']) {
            $turn->state = $state = $state->reset();
        }

        $parsed = $this->rules->parse($queryText, $now->startOfDay(), $now);

        // "بدي ملعب بكرا 8 والدفع كاش" is a booking that mentions paying; "كيف بدفع؟" is a question.
        if (in_array($read['strong'], [IntentClassifier::PAYMENT, IntentClassifier::HOW_TO_BOOK], true)
            && (isset($parsed['date']) || isset($parsed['hour'])) && ! $read['question']) {
            $read['strong'] = null;
        }

        if ($read['strong'] !== null) {
            return $this->faqAnswer($read['strong'], $turn);
        }

        $period = isset($parsed['date']) ? null : $this->periods->parse($queryText, $now->startOfDay());
        $searchSignals = $parsed !== [] || $period !== null || $read['sort'] !== null || $read['near'] || $read['any_hour'] || $read['list'];
        // A day alone in a longer message that is about nothing else ("what's the weather
        // today?") is not a booking request; "بكرا" alone, or as a follow-up, still is.
        $onlyADay = $this->onlyADay($parsed) && $period === null && $read['sort'] === null && ! $read['near'] && ! $read['any_hour'] && ! $read['list'];

        if ($onlyADay && $parsed !== [] && ! $read['booking_words'] && ! $state->inProgress() && count(explode(' ', $read['text'])) > 2) {
            $searchSignals = false;
        }

        if ($read['prices'] && ! isset($parsed['date']) && ! isset($parsed['hour']) && $period === null) {
            return $this->faqAnswer(IntentClassifier::PRICES, $turn);
        }

        if (! $searchSignals && ! $read['venue_words'] && ($read['yes'] || $read['no']) && $state->has('last_slot_id')) {
            return $read['yes'] ? $this->confirmBooking($turn) : $this->askAgain($turn);
        }

        if (! $searchSignals && ! $read['booking_words']) {
            $smallTalk = match (true) {
                $read['reset'] => 'new_search',
                $read['help'] => IntentClassifier::HELP,
                $read['thanks'] => IntentClassifier::THANKS,
                $read['greeting'] => IntentClassifier::GREETING,
                default => null,
            };

            if ($smallTalk !== null) {
                return $this->answer($turn, $smallTalk === 'new_search' ? IntentClassifier::BOOKING_SEARCH : $smallTalk, $this->knowledge->answer($smallTalk, $arabic));
            }
        }

        $context['known'] = array_intersect_key($state->toArray(), array_flip(['sport', 'date', 'hour', 'city', 'area']));
        ['arguments' => $arguments, 'source' => $source, 'unknown' => $unknown, 'extras' => $extras] = $this->interpret($queryText, $now, $useGemini, $context);
        $turn->source = $source;

        if (! $searchSignals && ! $read['booking_words'] && $this->onlyADay($arguments)
            && ! in_array($extras['intent'] ?? null, [IntentClassifier::BOOKING_SEARCH, IntentClassifier::AVAILABILITY], true)) {
            // Nothing a search can use: Gemini may still have recognised a question.
            $intent = $extras['intent'] ?? null;

            if (in_array($intent, [IntentClassifier::CANCELLATION, IntentClassifier::PAYMENT, IntentClassifier::HOW_TO_BOOK, IntentClassifier::PRICES, IntentClassifier::WORKING_HOURS], true)) {
                return $this->faqAnswer($intent, $turn);
            }

            if (in_array($intent, [IntentClassifier::GREETING, IntentClassifier::HELP, IntentClassifier::THANKS], true)) {
                return $this->answer($turn, $intent, $this->knowledge->answer($intent, $arabic));
            }

            if ($read['no'] && $state->inProgress()) {
                return $this->askAgain($turn);
            }

            return $this->answer($turn, IntentClassifier::OUT_OF_SCOPE, $this->knowledge->answer('out_of_scope', $arabic));
        }

        // A span of days from the rules; Gemini's only when the text names one the rules
        // can't read and gives no single day.
        if ($period === null && ! isset($parsed['date']) && isset($extras['period']) && $this->rules->mentionsDate($queryText, $now->startOfDay())) {
            $period = $this->periods->span($extras['period'], $now->startOfDay());
        }

        if ($period !== null) {
            unset($arguments['date']);
        }

        $listMode = $period !== null || $read['sort'] !== null || $read['near'] || $read['any_hour'] || $read['list']
            || ($extras['intent'] ?? null) === IntentClassifier::AVAILABILITY;
        $partOfDay = ! isset($arguments['hour']) ? $this->rules->partOfDay($queryText) : null;

        $turn->state = $state->merge([
            ...array_intersect_key($arguments, array_flip(VenueSearchTool::ARGUMENTS)),
            'period' => $period,
            'part_of_day' => $partOfDay,
            'sort' => $read['sort'],
            'location' => $read['near'] ? 'near' : null,
            'mode' => $listMode ? 'list' : null,
        ]);

        // "Any time": any hour of the day asked for, or of any day when none was.
        if ($read['any_hour']) {
            $turn->state = $turn->state->with(['hour' => null, 'part_of_day' => null]);

            if (! $turn->state->has('date') && ! $turn->state->has('period')) {
                $turn->state = $turn->state->with(['period' => $this->periods->span('any', $now->startOfDay())]);
            }
        }

        $turn->intent = $read['list'] || ($extras['intent'] ?? null) === IntentClassifier::AVAILABILITY ? IntentClassifier::AVAILABILITY : IntentClassifier::BOOKING_SEARCH;
        // A sport named in this message that may be missing from SPORT ("كرة سلة" before basketball is seeded).
        $turn->mentionedSport = isset($arguments['sport']) ? null : $this->rules->mentionedSport($queryText);

        if ($unknown !== null) {
            // The reply (and the evaluation) quote what was understood; the place is not kept.
            $turn->arguments = $arguments;
            $turn->state = $turn->state->with(['city' => $state->get('city'), 'area' => $state->get('area')]);

            return $this->finish($turn, self::NOT_COVERED, $this->notCoveredReply($unknown, $arabic));
        }

        return $this->search($turn);
    }

    /**
     * Gemini's reading wins; the rule-based reading is the fallback when Gemini is
     * unavailable and fills any argument Gemini didn't return.
     *
     * @return array<string, string>
     */
    public function understand(string $queryText, CarbonImmutable $now, bool $useGemini = true): array
    {
        return $this->interpret($queryText, $now, $useGemini)['arguments'];
    }

    /**
     * The search step, from the merged conversation state: asks for what is still missing
     * (the location for "near me", the sport, the day, the hour), then searches.
     */
    private function search(AssistantTurn $turn): array
    {
        $state = $turn->state;
        $arabic = $turn->arabic;
        $offered = $this->tool->offeredSports();
        $sport = $this->tool->findSport($state->get('sport'));

        // A sport no venue offers yet, or one that isn't in SPORT at all: coming soon.
        if (($sport !== null && ! $offered->contains('id', $sport->id)) || ($sport === null && $turn->mentionedSport !== null && ! $offered->contains(
            fn (Sport $offeredSport) => mb_strtolower($offeredSport->name_en) === $turn->mentionedSport,
        ))) {
            $name = $sport !== null ? ($arabic ? $sport->name_ar : $sport->name_en) : self::SPORT_NAMES[$turn->mentionedSport][$arabic ? 0 : 1];
            $turn->state = $state->with(['sport' => null]);

            return $this->finish($turn, self::NOT_OFFERED, $this->replies->sportNotOffered(
                $name,
                $offered->map(fn (Sport $offeredSport) => $arabic ? $offeredSport->name_ar : $offeredSport->name_en)->all(),
                $arabic,
            ));
        }

        // With a single sport on offer (Football today), there is nothing to ask.
        $sport ??= $offered->count() === 1 ? $offered->first() : null;
        $listMode = $state->get('mode') === 'list';
        $wantsNear = $state->get('location') === 'near' || $state->get('sort') === 'nearest';

        if ($wantsNear && ! $state->hasLocation()) {
            $turn->state = $state->with(['asked' => 'location']);
            $turn->options = ['use_location', 'pick_area', 'all_venues'];

            return $this->finish($turn, self::NEEDS_LOCATION, $this->replies->askLocation($arabic));
        }

        $period = $state->get('period');

        if ($period === null && ! $state->has('date') && $listMode) {
            $period = $this->periods->span('upcoming', $turn->now->startOfDay());
        }

        $missing = array_keys(array_filter([
            'sport' => ! $sport,
            'date' => ! $state->has('date') && $period === null,
            'hour' => ! $state->has('hour') && ! $listMode,
        ]));
        $turn->sport = $sport;
        $turn->period = $period;

        if ($missing !== []) {
            $partOfDay = $state->get('part_of_day') ?? 'evening';
            [$from, $until] = self::PARTS_OF_DAY[$partOfDay];
            $freeHours = $missing === ['hour'] ? $this->tool->freeHours($this->arguments($state, $sport), $turn->now, $from, until: $until) : [];
            $turn->state = $state->with(['asked' => $missing[0]]);

            // "Football tomorrow": besides the hour, offer how to search (the design's three buttons).
            if ($missing === ['hour'] && ! $state->has('city') && ! $state->has('area') && ! $state->has('location')) {
                $turn->options = ['use_location', 'pick_area', 'all_venues'];
            }

            return $this->finish($turn, self::NEEDS_DETAILS, $this->replies->needsDetails($missing, $arabic, $freeHours, $partOfDay));
        }

        [$from, $until] = self::PARTS_OF_DAY[$state->get('part_of_day') ?? ''] ?? [null, null];
        $found = $this->tool->search([
            ...$this->arguments($state, $sport),
            ...($period !== null ? ['from' => $period['from'], 'to' => $period['to']] : []),
            'from_hour' => $from,
            'until_hour' => $until,
            'sort' => $state->get('sort'),
            'near' => $wantsNear,
            'latitude' => $state->get('latitude'),
            'longitude' => $state->get('longitude'),
        ], $turn->now, self::MAX_SUGGESTIONS);

        $today = $turn->now->startOfDay();

        if ($found['exact'] === []) {
            $turn->suggestions = array_map(fn (array $option) => [...$option, 'exact' => false], $found['alternatives']);
            $turn->state = $turn->state->with(['asked' => null]);

            // The US-4.5 wording stays as it is; the close alternatives come as suggestions.
            return $this->finish($turn, self::NO_MATCH, $this->replies->noMatch($arabic));
        }

        $turn->suggestions = array_map(fn (array $option) => [...$option, 'exact' => true], $found['exact']);
        $turn->slot = $found['exact'][0]['slot'];
        $turn->state = $turn->state->with(['asked' => null, 'last_slot_id' => $turn->slot->id]);

        if (count($found['exact']) > 1) {
            $turn->options = array_values(array_filter([
                $state->get('sort') === 'cheapest' ? null : 'cheapest',
                $state->get('sort') === 'best_rated' ? null : 'best_rated',
                $state->hasLocation() || $wantsNear ? null : 'use_location',
            ]));
        }

        // One hour on one day reads as before (one venue, "Want me to book it?"); a list for the rest.
        $reply = $listMode || $wantsNear
            ? $this->replies->options($found['exact'], $today, $arabic, $wantsNear ? 'nearest' : $state->get('sort'), $period['name'] ?? null)
            : $this->replies->suggestion($turn->slot, $today, $arabic);

        return $this->finish($turn, self::SUGGESTION, $reply);
    }

    /**
     * A quick reply the player tapped.
     */
    private function chooseOption(string $optionId, AssistantTurn $turn): array
    {
        $state = $turn->state;
        // A tap has no text of its own; it is logged as the button the player saw.
        if (trim($turn->queryText) === '') {
            $turn->queryText = $this->optionLabel($optionId, $turn->arabic);
        }

        if (preg_match('/^city_(\d+)$/D', $optionId, $m) === 1 && ($city = City::query()->find((int) $m[1])) !== null) {
            $turn->state = $state->with(['city' => $city->name_en, 'area' => null, 'location' => 'area', 'mode' => 'list', 'asked' => null]);

            return $this->search($turn);
        }

        $changes = match ($optionId) {
            'use_location' => ['location' => 'near', 'city' => null, 'area' => null, 'mode' => 'list'],
            'all_venues' => ['location' => 'all', 'city' => null, 'area' => null, 'mode' => 'list'],
            'cheapest', 'best_rated' => ['sort' => $optionId, 'mode' => 'list'],
            default => null,
        };

        if ($changes !== null) {
            $turn->state = $state->with([...$changes, 'asked' => null]);

            return $this->search($turn);
        }

        if ($optionId === 'new_search') {
            $turn->state = $state->reset();

            return $this->answer($turn, IntentClassifier::BOOKING_SEARCH, $this->knowledge->answer('new_search', $turn->arabic));
        }

        return $this->pickCity($turn);
    }

    /**
     * "Pick an area": the cities that have venues, as buttons.
     */
    private function pickCity(AssistantTurn $turn): array
    {
        $cities = City::query()
            ->whereIn('id', Venue::query()->where('status', 'active')->select('city_id'))
            ->orderBy('id')
            ->get();
        $cities = $cities->isEmpty() ? City::query()->orderBy('id')->get() : $cities;
        $turn->state = $turn->state->with(['asked' => 'place', 'mode' => 'list']);
        $turn->options = $cities->map(fn (City $city) => 'city_'.$city->id)->all();
        $turn->optionLabels = $cities->mapWithKeys(fn (City $city) => ['city_'.$city->id => $turn->arabic ? $city->name_ar : $city->name_en])->all();
        $turn->intent = IntentClassifier::BOOKING_SEARCH;

        return $this->finish($turn, self::NEEDS_DETAILS, $this->replies->pickCity(array_values($turn->optionLabels), $turn->arabic));
    }

    /**
     * "Yes" after a suggestion: the assistant can't book by itself, so it says how, with the
     * slot again if it is still free (or searches again if it was taken meanwhile).
     */
    private function confirmBooking(AssistantTurn $turn): array
    {
        $slot = TimeSlot::query()->with('venue.city')->find($turn->state->get('last_slot_id'));
        $stillFree = $slot !== null && $slot->status === 'available' && $slot->venue?->status === 'active';

        if (! $stillFree) {
            $turn->state = $turn->state->with(['last_slot_id' => null]);

            return $this->search($turn);
        }

        $turn->intent = IntentClassifier::CONFIRM_BOOKING;
        $turn->slot = $slot;
        $turn->sport = $slot->sport;
        $turn->suggestions = [['slot' => $slot, 'distance_km' => null, 'exact' => true]];

        return $this->finish($turn, self::SUGGESTION, $this->knowledge->answer('confirm_booking', $turn->arabic));
    }

    /**
     * "No" to a suggestion: keep what was said and ask for another day or hour.
     */
    private function askAgain(AssistantTurn $turn): array
    {
        $turn->state = $turn->state->with(['hour' => null, 'last_slot_id' => null, 'asked' => 'hour']);

        return $this->answer($turn, IntentClassifier::BOOKING_SEARCH, $turn->arabic
            ? 'تمام. أخبرني بيوم أو ساعة أخرى، أو اختر طريقة البحث.'
            : 'OK. Tell me another day or hour, or choose how to search.', ['use_location', 'pick_area', 'all_venues'], self::NEEDS_DETAILS);
    }

    /**
     * Prices and working hours are computed from the slots, filtered by the sport, city and
     * area the message names; the policies come from faq.json.
     */
    private function faqAnswer(string $intent, AssistantTurn $turn): array
    {
        $arabic = $turn->arabic;

        if (! in_array($intent, [IntentClassifier::PRICES, IntentClassifier::WORKING_HOURS], true)) {
            return $this->answer($turn, $intent, $this->knowledge->answer($intent, $arabic));
        }

        $parsed = $this->rules->parse($turn->queryText, $turn->now->startOfDay(), $turn->now);
        $sport = $this->tool->findSport($parsed['sport'] ?? null);
        $offered = $this->tool->offeredSports();
        $sport ??= $offered->count() === 1 ? $offered->first() : null;
        // Logged as the parsed sport, like a search; the conversation's request is left as it was.
        $turn->sport = $sport;
        $arguments = array_filter(['sport' => $sport?->name_en, 'city' => $parsed['city'] ?? null, 'area' => $parsed['area'] ?? null]);
        $city = $this->tool->findCity($parsed['city'] ?? null);
        $place = $parsed['area'] ?? ($city === null ? null : ($arabic ? $city->name_ar : $city->name_en));

        if ($intent === IntentClassifier::WORKING_HOURS) {
            return $this->answer($turn, $intent, $this->replies->workingHours($this->knowledge->workingHours($arguments, $turn->now), $place, $arabic));
        }

        $prices = $this->knowledge->prices($arguments, $turn->now);

        if ($prices !== null) {
            // The cheapest coming slots, one per venue, to book straight from the answer.
            $turn->suggestions = array_map(fn (array $option) => [...$option, 'exact' => true], $this->tool->search([
                ...$arguments,
                'sport' => $prices['cheapest']->sport->name_en,
                'from' => $turn->now->toDateString(),
                'to' => $turn->now->addDays(AssistantKnowledge::PRICE_DAYS)->toDateString(),
                'sort' => 'cheapest',
            ], $turn->now, self::MAX_SUGGESTIONS)['exact']);
            $turn->slot = $turn->suggestions[0]['slot'] ?? null;
        }

        return $this->answer($turn, $intent, $this->replies->prices($prices, $sport === null ? null : ($arabic ? $sport->name_ar : $sport->name_en), $place, $arabic));
    }

    /**
     * @param  list<string>  $options
     */
    private function answer(AssistantTurn $turn, string $intent, string $replyText, array $options = [], string $outcome = self::ANSWER): array
    {
        $turn->intent = $intent;
        $turn->options = $options;

        return $this->finish($turn, $outcome, $replyText);
    }

    /**
     * The result of a turn, in the shape the controller, the conversation log and the
     * evaluation read.
     */
    private function finish(AssistantTurn $turn, string $outcome, string $replyText): array
    {
        $state = $turn->state;
        $arguments = $turn->arguments ?? array_filter([
            'sport' => $turn->sport?->name_en ?? $state->get('sport'),
            'date' => $state->get('date'),
            'hour' => $state->get('hour'),
            'city' => $state->get('city'),
            'area' => $state->get('area'),
        ]);

        return [
            'arguments' => $arguments,
            'source' => $turn->source,
            'sport' => $turn->sport ?? $this->tool->findSport($state->get('sport')),
            'slot' => $turn->slot,
            'outcome' => $outcome,
            'reply_text' => $replyText,
            'intent' => $turn->intent,
            'state' => $state,
            'suggestions' => $turn->suggestions,
            'options' => array_map(fn (string $id) => ['id' => $id, 'label' => $turn->optionLabels[$id] ?? $this->optionLabel($id, $turn->arabic)], $turn->options),
            'period' => $turn->period ?? $state->get('period'),
            'arabic' => $turn->arabic,
            'query_text' => $turn->queryText,
        ];
    }

    /**
     * Whether the arguments give at most a day: nothing a search could be about.
     *
     * @param  array<string, string>  $arguments
     */
    private function onlyADay(array $arguments): bool
    {
        return array_diff(array_keys(array_intersect_key($arguments, array_flip(VenueSearchTool::ARGUMENTS))), ['date']) === [];
    }

    /**
     * The search arguments of the state (sport resolved, the rest as understood).
     *
     * @return array<string, string>
     */
    private function arguments(ConversationState $state, ?Sport $sport): array
    {
        return array_filter([
            'sport' => $sport?->name_en,
            'date' => $state->get('date'),
            'hour' => $state->get('hour'),
            'city' => $state->get('city'),
            'area' => $state->get('area'),
        ], 'is_string');
    }

    private function optionLabel(string $optionId, bool $arabic): string
    {
        if (preg_match('/^city_(\d+)$/D', $optionId, $m) === 1 && ($city = City::query()->find((int) $m[1])) !== null) {
            return $arabic ? $city->name_ar : $city->name_en;
        }

        return $this->replies->optionLabel($optionId, $arabic);
    }

    /**
     * The reply follows the message's language. A message without letters ("8", "😀") keeps
     * the conversation's language, or the app's when given.
     */
    private function isArabic(string $queryText, ConversationState $state, ?string $language): bool
    {
        if (preg_match('/\p{L}/u', $queryText) === 1) {
            return TextNormalizer::isArabic($queryText);
        }

        $language = $state->get('language') ?? $language;

        return $language === null ? TextNormalizer::isArabic($queryText) : $language === 'ar';
    }

    /**
     * The location the app shared, rounded to three decimals (about 100 m): enough to sort
     * venues by distance, without keeping the player's exact position.
     *
     * @return array<string, float>
     */
    private function sharedLocation(array $context): array
    {
        $latitude = $context['latitude'] ?? null;
        $longitude = $context['longitude'] ?? null;

        if (! is_numeric($latitude) || ! is_numeric($longitude) || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
            return [];
        }

        return ['latitude' => round((float) $latitude, 3), 'longitude' => round((float) $longitude, 3)];
    }

    /**
     * The source is "gemini" only when a sport, date or hour from Gemini survived the checks
     * below; unknown is a city or area the player named that isn't covered; extras are
     * Gemini's optional intent and period, when they are valid values.
     *
     * @return array{arguments: array<string, string>, source: string, unknown: ?array{kind: string, name: string}, extras: array<string, string>}
     */
    private function interpret(string $queryText, CarbonImmutable $now, bool $useGemini, array $context = []): array
    {
        $today = $now->startOfDay();
        $fallback = $this->rules->parse($queryText, $today, $now);
        $fromGemini = $useGemini ? $this->gemini->parse($queryText, $today, $now, $context) : null;

        if ($fromGemini === null) {
            return ['arguments' => $fallback, 'source' => self::FROM_FALLBACK, 'unknown' => $this->unknownPlace($queryText, $fallback), 'extras' => []];
        }

        $extras = array_filter([
            'intent' => in_array($fromGemini['intent'] ?? null, IntentClassifier::INTENTS, true) ? $fromGemini['intent'] : null,
            'period' => in_array($fromGemini['period'] ?? null, PeriodParser::NAMES, true) ? $fromGemini['period'] : null,
        ]);
        $fromGemini = $this->onlyValid($fromGemini, $today);

        // Mode ANY makes Gemini fill every required argument, even a day or an hour the
        // player never gave; then the assistant asks for it instead of guessing.
        if (! $this->rules->mentionsDate($queryText, $today)) {
            unset($fromGemini['date']);
        }

        if (! $this->rules->mentionsTime($queryText, $today)) {
            unset($fromGemini['hour']);
        }

        // A sport outside the SPORT table can't be searched; the fallback's reading may be.
        if (isset($fromGemini['sport']) && ! $this->tool->findSport($fromGemini['sport'])) {
            unset($fromGemini['sport']);
        }

        // With the conversation as context Gemini may repeat an earlier sport or place; only
        // what the message itself names counts as said now (the state keeps the rest).
        foreach (['sport', 'city', 'area'] as $field) {
            if (isset($fromGemini[$field], $context['known'][$field]) && ! isset($fallback[$field])
                && TextNormalizer::normalize($fromGemini[$field]) === TextNormalizer::normalize((string) $context['known'][$field])) {
                unset($fromGemini[$field]);
            }
        }

        $source = array_intersect_key($fromGemini, array_flip(self::REQUIRED_ARGUMENTS)) === [] ? self::FROM_FALLBACK : self::FROM_GEMINI;
        ['arguments' => $fromGemini, 'unknown' => $unknown] = $this->resolvePlace($fromGemini, $fallback);
        $arguments = [...$fromGemini, ...array_diff_key($fallback, $fromGemini)];

        return ['arguments' => $arguments, 'source' => $source, 'unknown' => $unknown ?? $this->unknownPlace($queryText, $arguments), 'extras' => $extras];
    }

    /**
     * Keeps only the search arguments, drops a date that isn't a real YYYY-MM-DD date from
     * today on (a past day can't be booked, so the fallback's reading is used instead), and
     * pads or drops an hour so it is HH:mm, the formats the search and the ERD columns expect.
     *
     * @param  array<string, string>  $arguments
     * @return array<string, string>
     */
    private function onlyValid(array $arguments, CarbonImmutable $today): array
    {
        $arguments = array_intersect_key($arguments, array_flip(VenueSearchTool::ARGUMENTS));

        if (isset($arguments['date'])
            && (! $this->isDate($arguments['date']) || $arguments['date'] < $today->toDateString())) {
            unset($arguments['date']);
        }

        if (isset($arguments['hour'])) {
            $hour = preg_match('/^(\d{1,2}):([0-5]\d)(?::\d{2})?$/D', $arguments['hour'], $m) && (int) $m[1] < 24
                ? sprintf('%02d:%s', $m[1], $m[2])
                : null;

            $arguments['hour'] = $hour;
        }

        return array_filter($arguments);
    }

    /**
     * Gemini's optional city and area are hard filters, so one that names nothing seeded must
     * not hide a real match or quietly search elsewhere:
     * - an unknown city is replaced by the rule-based reading of the text (which finds "Gaza"
     *   in "Gaza City"); when that finds none either, the city is reported as not covered;
     * - an area that is really a city name is used as the city;
     * - an area no venue is in is replaced by the rule-based reading, or reported.
     *
     * @param  array<string, string>  $arguments
     * @param  array<string, string>  $fallback
     * @return array{arguments: array<string, string>, unknown: ?array{kind: string, name: string}}
     */
    private function resolvePlace(array $arguments, array $fallback): array
    {
        $unknown = null;

        if (isset($arguments['city'])) {
            $city = $this->cityNamed($arguments['city'])?->name_en ?? $fallback['city'] ?? null;
            $unknown = $city === null ? ['kind' => 'city', 'name' => $this->shown($arguments['city'])] : null;
            $arguments['city'] = $city;
        }

        if (isset($arguments['area']) && ($city = $this->cityNamed($arguments['area']))) {
            unset($arguments['area']);
            $arguments['city'] ??= $city->name_en;
        } elseif (isset($arguments['area']) && ! $this->tool->knowsArea($arguments['area'])) {
            // Kept when the text names no known area either, so the search finds nothing
            // rather than looking everywhere.
            $area = $arguments['area'];
            $arguments['area'] = $fallback['area'] ?? $area;

            if (! isset($fallback['area']) && $unknown === null && preg_match('/\p{L}/u', $area) === 1) {
                $unknown = ['kind' => 'area', 'name' => $this->shown($area)];
            }
        }

        return ['arguments' => array_filter($arguments), 'unknown' => $unknown];
    }

    /**
     * A place the player named that no seeded city, area or venue matches.
     *
     * @param  array<string, string>  $arguments
     * @return ?array{kind: string, name: string}
     */
    private function unknownPlace(string $queryText, array $arguments): ?array
    {
        if (isset($arguments['area'])) {
            return null;
        }

        $place = $this->rules->unknownPlace($queryText);

        if ($place === null) {
            return null;
        }

        // Inside a known city it is an area there; otherwise a city we don't cover.
        return ['kind' => isset($arguments['city']) ? 'area' : 'city', 'name' => $this->shown($place)];
    }

    /**
     * @param  array{kind: string, name: string}  $unknown
     */
    private function notCoveredReply(array $unknown, bool $arabic): string
    {
        if ($unknown['kind'] === 'area') {
            return $this->replies->unknownArea($unknown['name'], $arabic);
        }

        $cities = City::query()->orderBy('id')->get(['name_ar', 'name_en'])
            ->map(fn (City $city) => $arabic ? $city->name_ar : $city->name_en)
            ->unique()
            ->values()
            ->all();

        return $this->replies->notCovered($unknown['name'], $cities, $arabic);
    }

    /**
     * A place name as it is quoted back to the player.
     */
    private function shown(string $place): string
    {
        $place = Str::limit(trim(preg_replace('/\s+/u', ' ', $place) ?? $place), 60);

        return preg_match('/^[a-z ]+$/', $place) === 1 ? ucwords($place) : $place;
    }

    /**
     * The seeded city a value names, also when it adds the word city ("Gaza City", "مدينة غزة").
     */
    private function cityNamed(string $value): ?City
    {
        return $this->tool->findCity($value)
            ?? $this->tool->findCity(preg_replace('/(?<!\p{L})(?:city|مدينه)(?!\p{L})/u', '', TextNormalizer::normalize($value)));
    }

    private function isDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
