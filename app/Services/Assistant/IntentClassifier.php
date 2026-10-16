<?php

namespace App\Services\Assistant;

/**
 * Rule-based reading of what a message is about, beyond the sport, day and hour that
 * RuleBasedQueryParser reads: a question the assistant answers from the FAQ or from live
 * data (prices, working hours, cancellation, payment, how to book), small talk (greeting,
 * help, thanks), and the search wishes "cheapest", "best rated", "near me", "any time",
 * "new search", "yes, book it". Phrases are in Arabic (MSA and Palestinian dialect),
 * English and Arabizi, written in normalised form (TextNormalizer::normalize()).
 *
 * It runs before Gemini, so a FAQ question or a greeting never spends a Gemini request.
 */
class IntentClassifier
{
    public const BOOKING_SEARCH = 'booking_search';

    public const AVAILABILITY = 'availability';

    public const PRICES = 'prices';

    public const WORKING_HOURS = 'working_hours';

    public const CANCELLATION = 'cancellation';

    public const PAYMENT = 'payment';

    public const HOW_TO_BOOK = 'how_to_book';

    public const CONFIRM_BOOKING = 'confirm_booking';

    public const GREETING = 'greeting';

    public const HELP = 'help';

    public const THANKS = 'thanks';

    public const OUT_OF_SCOPE = 'out_of_scope';

    public const INTENTS = [
        self::BOOKING_SEARCH, self::AVAILABILITY, self::PRICES, self::WORKING_HOURS, self::CANCELLATION, self::PAYMENT,
        self::HOW_TO_BOOK, self::CONFIRM_BOOKING, self::GREETING, self::HELP, self::THANKS, self::OUT_OF_SCOPE,
    ];

    /**
     * Questions answered whatever else the message says ("how do I cancel tomorrow's booking?").
     * Checked in this order, so "how do I cancel" is cancellation, not how to book.
     */
    private const STRONG = [
        self::CANCELLATION => [
            'الغاء', 'الغي', 'الغيت', 'الغيه', 'الغيها', 'يلغي', 'نلغي', 'تلغي', 'بلغي', 'ينلغي', 'انلغي', 'الغاءه', 'ابطل الحجز', 'اكنسل', 'كنسل',
            'استرجاع', 'استرداد', 'ترجيع المصاري', 'ارجاع المبلغ', 'ترجعولي', 'رجعولي',
            'cancel', 'cancels', 'cancelled', 'canceled', 'cancelling', 'canceling', 'cancellation', 'refund', 'refunds', 'money back',
            'el8a2', 'ilgha2', 'elgha2', 'alghi', 'algi', 'kansel', 'cancl',
        ],
        self::PAYMENT => [
            'دفع', 'ادفع', 'الدفع', 'بدفع', 'ندفع', 'تدفع', 'يدفع', 'الدفعه', 'تحويل بنكي', 'حواله', 'التحويل', 'ايصال', 'الايصال', 'الوصل', 'اثبات الدفع',
            'pay', 'paying', 'payment', 'payments', 'receipt', 'receipts', 'bank transfer', 'transfer',
            'daf3', 'edfa3', 'adfa3', 'badfa3', 'el daf3',
        ],
        self::HOW_TO_BOOK => [
            'كيف احجز', 'كيف بحجز', 'كيف نحجز', 'كيف الحجز', 'كيف اقدر احجز', 'كيف بقدر احجز', 'كيف يمكنني الحجز', 'كيف يمكنني ان احجز', 'كيف يتم الحجز',
            'كيف بدي احجز', 'كيف بصير الحجز', 'طريقه الحجز', 'خطوات الحجز', 'شلون احجز', 'ازاي احجز', 'كيفيه الحجز', 'كيف اعمل حجز',
            'how do i book', 'how to book', 'how can i book', 'how does booking work', 'how do you book', 'how do we book', 'booking process',
            'how booking works', 'how to make a booking', 'how do i make a booking', 'how do i reserve',
            'kif a7jez', 'kef a7jez', 'kif ba7jez', 'ezay a7gez', 'ezay a7jez', 'keef a7jez',
        ],
        self::WORKING_HOURS => [
            'مواعيد العمل', 'ساعات العمل', 'اوقات العمل', 'اوقات الدوام', 'مواعيد الدوام', 'ساعات الدوام', 'الدوام', 'دوامكم', 'دوامكو',
            'متي تفتحون', 'متي بتفتحوا', 'متي بتفتحو', 'متي تفتح', 'متي بتفتح', 'متي تغلقون', 'متي بتسكروا', 'متي بتسكرو', 'متي بتسكر', 'ايمتي بتفتحوا',
            'وقت الفتح', 'وقت الاغلاق', 'شو مواعيدكم', 'ما هي مواعيدكم', 'مواعيدكم',
            'working hours', 'opening hours', 'business hours', 'open hours', 'opening times', 'when do you open', 'when are you open',
            'what time do you open', 'what time do you close', 'when do you close', 'hours of operation',
            'mawa3eed el 3amal', 'sa3at el 3amal', 'dawam', 'el dawam', 'emta btefta7o', 'imta btifta7u',
        ],
    ];

    /**
     * Prices, unless a day or an hour is given: then it is a search, whose reply quotes the price.
     */
    private const PRICE_WORDS = [
        'اسعار', 'الاسعار', 'سعر', 'السعر', 'اسعاركم', 'بكم', 'بقديش', 'قديش', 'قداش', 'كم سعر', 'كم السعر', 'كم بتكلف', 'كم يكلف', 'كم تكلفه', 'التكلفه', 'تكلفه',
        'كم حق', 'حق الساعه', 'سعر الساعه', 'كم الحجز',
        'price', 'prices', 'pricing', 'how much', 'cost', 'costs', 'rates', 'fee', 'fees',
        'as3ar', 'se3r', 'si3r', 'b2adesh', 'adesh', 'addesh', 'bkam', 'b kam', 'kam se3r',
    ];

    /**
     * "What venues are available?": a list, even without an hour.
     */
    private const LIST_WORDS = [
        'ما هي ملاعب', 'ما هي الملاعب', 'شو الملاعب', 'شو في ملاعب', 'في ملاعب', 'ايش الملاعب', 'وين الملاعب', 'اي ملاعب', 'الملاعب المتاحه',
        'ملاعب متاحه', 'الملاعب الفاضيه', 'ملاعب فاضيه', 'المواعيد المتاحه', 'مواعيد متاحه', 'المواعيد الفاضيه', 'اعرض الملاعب', 'اعرضلي',
        'ورجيني', 'فرجيني', 'وريني', 'شوفلي', 'قائمه الملاعب', 'كل الملاعب', 'ابحث عن ملاعب', 'ابحث عن ملعب', 'دورلي', 'لاقيلي',
        'available venues', 'available courts', 'available pitches', 'available fields', 'available slots', 'free slots', 'free courts',
        'what venues', 'which venues', 'what courts', 'which courts', 'what pitches', 'which pitches', 'list venues', 'list courts',
        'show me', 'show venues', 'all venues', 'find venues', 'find courts', 'search for venues', 'what is available', "what's available", 'whats available',
        'shu el mala3eb', 'sho el mala3eb', 'mala3eb fadye',
    ];

    private const CHEAPEST = [
        'ارخص', 'الارخص', 'ارخصهم', 'ارخصها', 'ارخص اشي', 'اقل سعر', 'الاقل سعرا', 'باقل سعر', 'الاوفر', 'اوفر', 'رخيص', 'رخيصه', 'ارخص شي',
        'cheapest', 'cheaper', 'cheap', 'lowest price', 'least expensive', 'budget', 'ar5as', 'arkhas', 'ar5as wa7ad', 'r5ees',
    ];

    private const BEST_RATED = [
        'الاعلي تقييما', 'الاعلي تقييم', 'اعلي تقييم', 'اعلي تقييما', 'الاكثر تقييما', 'افضل تقييم', 'احسن تقييم', 'الافضل', 'افضل ملعب', 'احسن ملعب', 'الاحسن',
        'best rated', 'top rated', 'highest rated', 'best reviewed', 'the best', 'best venue', 'best court', 'best pitch', 'a7san', 'afdal', 'el afdal',
    ];

    private const NEAREST = ['الاقرب', 'اقرب', 'اقربهم', 'nearest', 'closest', 'a2rab', 'aqrab'];

    private const NEAR_ME = [
        'قريب مني', 'قريبه مني', 'قريبين مني', 'قريب علي', 'قريبه علي', 'قريب لي', 'قريبه لي', 'قريب من موقعي', 'قريبه من موقعي', 'بالقرب مني',
        'قريب من عندي', 'قريبه من عندي', 'قريب من بيتي', 'جنبي', 'حواليني', 'حولي', 'منطقتي', 'بمنطقتي',
        'near me', 'nearby', 'close to me', 'around me', 'close by', 'near my location', 'near where i am', 'near here',
        '2areeb meni', '2rib mni', 'qarib mini', 'areeb mni', 'qareeb meni', '2arib mni',
    ];

    private const ANY_HOUR = ['اي ساعه', 'باي ساعه', 'اي وقت', 'باي وقت', 'مش مهم الوقت', 'مش مهم الساعه', 'any time', 'anytime', 'any hour', 'ay sa3a'];

    /**
     * Question words: with them a payment or booking phrase is a question, even next to a day.
     */
    private const QUESTION_WORDS = ['كيف', 'كيفيه', 'طريقه', 'شلون', 'ازاي', 'وين', 'متي', 'هل', 'ممكن اعرف', 'how', 'where', 'when', 'can i', 'do i', 'kif', 'kef', 'ezay', 'wein'];

    private const RESET = ['بحث جديد', 'من جديد', 'من الاول', 'ابدا من جديد', 'خلينا نبدا من جديد', 'انسي', 'new search', 'start over', 'start again', 'reset'];

    private const YES = [
        'نعم', 'اه', 'ايوه', 'ايوا', 'اي نعم', 'اكيد', 'تمام', 'موافق', 'ماشي', 'احجزه', 'احجزلي', 'احجزها', 'احجزو', 'احجز', 'يلا', 'بدي اياه', 'بدي ياه',
        'yes', 'yeah', 'yep', 'yup', 'sure', 'ok', 'okay', 'book it', 'book', 'please do', 'go ahead', 'aywa', 'aiwa', 'ah', 'tamam', 'akeed', 'yalla',
    ];

    private const NO = ['لا', 'لأ', 'لا شكرا', 'مش هيك', 'no', 'nope', 'no thanks', 'la2', 'la'];

    private const GREETINGS = [
        'مرحبا', 'مرحبتين', 'مرحب', 'اهلا', 'اهلين', 'اهلا وسهلا', 'هلا', 'هلا والله', 'السلام عليكم', 'سلام عليكم', 'السلام', 'سلام', 'هاي', 'هلو', 'هالو',
        'صباح الخير', 'مساء الخير', 'صباحو', 'مساءو', 'يا هلا',
        'hi', 'hello', 'hey', 'hiya', 'good morning', 'good evening', 'good afternoon', 'salam', 'salaam', 'marhaba', 'mar7aba', 'ahlan', 'ahla', 'hala',
    ];

    private const HELP_WORDS = [
        'مساعده', 'ساعدني', 'ساعدوني', 'محتاج مساعده', 'شو بتقدر تعمل', 'شو بتعمل', 'ماذا تستطيع', 'ماذا يمكنك', 'شو بتعرف', 'مين انت', 'من انت', 'شو انت',
        'كيف بتشتغل', 'كيف تعمل', 'شو بتساعد',
        'help', 'help me', 'what can you do', 'who are you', 'what are you', 'how does this work', 'what do you do', 'menu', 'options',
        'sa3edni', 'mosa3ade', 'shu bt3mal',
    ];

    private const THANKS_WORDS = [
        'شكرا', 'شكرا جزيلا', 'مشكور', 'مشكورين', 'يعطيك العافيه', 'الله يعطيك العافيه', 'تسلم', 'تسلمي', 'يسلمو', 'يسلموا', 'ممنون', 'جزاك الله خير',
        'thanks', 'thank you', 'thx', 'ty', 'merci', 'shukran', 'shokran', 'tslam', 'yslamo', 'ya3tik el 3afye',
    ];

    /**
     * Words that only make sense in a booking request, so a message with them is never out
     * of scope: the verbs, which also confirm a suggestion ("احجزه"), and the venue words.
     */
    private const BOOKING_VERBS = ['احجز', 'حجز', 'نحجز', 'بحجز', 'احجزلي', 'الحجز', 'book', 'booking', 'reserve', 'reservation', 'a7jez', '7ajz'];

    private const VENUE_WORDS = [
        'ملعب', 'ملاعب', 'الملعب', 'الملاعب', 'العب', 'نلعب', 'بلعب', 'مباراه', 'ماتش', 'مواعيد', 'موعد', 'متاح', 'متاحه', 'فاضي', 'فاضيه', 'صاله', 'ساحه', 'ثاني', 'تاني', 'غيره',
        'court', 'courts', 'pitch', 'pitches', 'field', 'fields', 'venue', 'venues', 'stadium', 'play', 'game', 'match', 'slot', 'slots', 'available', 'free',
        'another', 'other', 'different',
        'mal3ab', 'mala3eb', 'el3ab', 'nel3ab',
    ];

    /**
     * @return array{text: string, strong: ?string, prices: bool, list: bool, sort: ?string, near: bool, any_hour: bool, reset: bool,
     *               yes: bool, no: bool, greeting: bool, help: bool, thanks: bool, booking_words: bool, venue_words: bool, question: bool}
     */
    public function read(string $queryText): array
    {
        $text = TextNormalizer::normalize($queryText);
        $strong = null;

        foreach (self::STRONG as $intent => $phrases) {
            if ($this->says($text, $phrases)) {
                $strong = $intent;

                break;
            }
        }

        return [
            'text' => $text,
            'strong' => $strong,
            'prices' => $this->says($text, self::PRICE_WORDS),
            'list' => $this->says($text, self::LIST_WORDS)
                // "what football venues are available", "which courts are free tomorrow".
                || preg_match('/(?<![a-z])(?:what|which|any|list|show)\s+(?:[a-z]+\s+){0,2}(?:venues|courts|pitches|fields|slots)\s+(?:are\s+|is\s+)?(?:available|free|open)(?![a-z])/', $text) === 1,
            'sort' => match (true) {
                $this->says($text, self::CHEAPEST) => 'cheapest',
                $this->says($text, self::BEST_RATED) => 'best_rated',
                $this->says($text, self::NEAREST) => 'nearest',
                default => null,
            },
            'near' => $this->says($text, self::NEAR_ME) || $this->says($text, self::NEAREST)
                // "ملاعب قريبة" alone; "ملعب قريب من الجلاء" names a place instead.
                || preg_match('/(?<![\p{L}])[وبلف]?(?:ال)?ملا?عب\s+(?:ال)?قريب(?:ه|ين)?(?![\p{L}])(?!\s+من\s)/u', $text) === 1,
            'any_hour' => $this->says($text, self::ANY_HOUR),
            'reset' => $this->says($text, self::RESET),
            'yes' => $this->isShort($text) && $this->says($text, self::YES),
            'no' => $this->isShort($text) && $this->says($text, self::NO),
            'greeting' => $this->says($text, self::GREETINGS),
            'help' => $this->says($text, self::HELP_WORDS),
            'thanks' => $this->says($text, self::THANKS_WORDS),
            'booking_words' => $this->says($text, self::BOOKING_VERBS) || $this->says($text, self::VENUE_WORDS),
            'venue_words' => $this->says($text, self::VENUE_WORDS),
            'question' => $this->says($text, self::QUESTION_WORDS),
        ];
    }

    /**
     * A one-word "yes" or "no" is a reply to the assistant, not a new request.
     */
    private function isShort(string $text): bool
    {
        return $text !== '' && count(explode(' ', $text)) <= 4;
    }

    /**
     * Whole-word phrases, which may carry a one-letter Arabic prefix (و، ب، ل، ف).
     *
     * @param  list<string>  $phrases
     */
    private function says(string $text, array $phrases): bool
    {
        if ($text === '') {
            return false;
        }

        foreach ($phrases as $phrase) {
            $pattern = '/(?<![\p{L}\p{N}])[وبلف]?'.str_replace(' ', '\s+', preg_quote($phrase, '/')).'(?![\p{L}\p{N}])/u';

            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
