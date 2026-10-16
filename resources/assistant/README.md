# Booking assistant (US-4.5), version 2

`POST /api/v1/assistant/query` answers a player's message about booking a venue, in Arabic,
English or Arabizi. Version 2 keeps the contract 7.8 response exactly (same fields, same
meaning, same order) and only **adds optional fields**, so an app that reads only `reply_text`
and `suggested_venue_id` keeps working. It also adds `POST /api/v1/assistant/feedback`.

Both endpoints are for signed-in players only (`auth:sanctum`, `role:player`) and use the
unified response `{success, data, message, errors}`.

Files to review:

- [`faq.json`](faq.json): the policy and FAQ answers (cancellation, payment, how to book,
  greeting, help, thanks, out of scope). Status: **draft — pending team approval**. Prices and
  working hours are never written there: they are computed live from the time slots.
- [`evaluation-cases.json`](evaluation-cases.json): the accuracy test cases; group `v2` covers
  the features below. Run `php artisan assistant:evaluate --fallback` (or `--group=v2`).

## Request: `POST /api/v1/assistant/query`

| Field | Type | Notes |
|---|---|---|
| `query_text` | string, max 500 | The player's message. Required unless `option_id` is sent. |
| `conversation_id` | uuid, optional | The `conversation_id` of the previous answer, to continue the conversation. Another player's id: **403**; unknown id: **404**. |
| `option_id` | string, optional | A quick reply the player tapped: `use_location`, `pick_area`, `all_venues`, `cheapest`, `best_rated`, `new_search` or `city_<id>`. |
| `latitude`, `longitude` | numbers, optional | The player's location (both or neither; -90..90 and -180..180), for "near me". |

The reply follows the message's language; Arabizi (Arabic in Latin letters with digits,
"kif bilghi el 7ajez") is answered in Arabic. A message without letters (a tap, `"8"`) keeps
the conversation's language; in a new conversation the `Accept-Language` header decides.

## Response additions (`data`)

| Field | Type | Meaning |
|---|---|---|
| `conversation_id` | uuid | Send it with the next message. Always use the latest one: after 30 idle minutes a new conversation starts. |
| `intent` | string | `booking_search`, `availability`, `prices`, `working_hours`, `cancellation`, `payment`, `how_to_book`, `booking_status` ("why isn't my booking confirmed?"), `venue_info` (facilities the data doesn't hold: lights, turf, parking...), `confirm_booking`, `greeting`, `help`, `thanks`, `out_of_scope`. |
| `outcome` | string | `suggestion`, `no_match` (nothing at the hour asked for; see near misses below), `needs_details`, `needs_location`, `not_covered` (a city or area we have no venues in, also a bare name such as "في رفح"), `not_offered` (sport not available yet), `answer` (a question answered without a search). |
| `parsed_date_range` | object or null | `{period, from, to}` when a span of days was asked for (then `parsed_date` is null). `period`: `this_week`, `next_week`, `this_month`, `next_month`, `weekend`, `next_weekend`, `upcoming`, `any`, or `days` for weekdays offered as alternatives ("الجمعة أو السبت": only those days are searched; `from`/`to` are the first and last). |
| `suggestions` | array (0-5) | One per venue, best first; `suggested_venue_id` is always the first one's venue (null only when there are no suggestions). Each: `venue_id, time_slot_id, name_ar, name_en, area_ar, area_en, city_ar, city_en, date, start_time, end_time, price, rating, distance_km` (null without a location) and `exact`: true when the slot starts at the hour asked for or runs a full hour from it; false for a slot that holds the hour only partly ("8 ونص" in 20:00-21:00) and for a close alternative. |
| `options` | array | Quick-reply buttons `{id, label}`, the label in the reply's language. Send the id back as `option_id`. |

`parsed_sport_id`, `parsed_date` and `parsed_hour` describe the whole request understood so
far (so after "8" they hold tomorrow and 20:00), and each message is still logged to
`assistant_queries`.

### What the assistant does

- **Follow-ups**: each message is merged with what the conversation understood (sport, day or
  span, hour, city or area, location choice, sort). A detail given again replaces the old one
  ("لا خليها بعد بكرا"); a message with a new sport and day starts a new request; "بحث جديد" /
  "new search" forgets it. A question in the middle ("كيف بدفع؟") keeps the request.
- **Spans of days**: this week / next week (Sunday to Saturday), this or next month, the
  weekend (Friday and Saturday), the coming days (7), any day (14). Only free slots from now on.
- **Lists and ranking**: the hour asked for first, then rating (high first), price (low first)
  and distance when a location is shared; "الأرخص / cheapest" puts price first, "near me" puts
  distance first (venues without coordinates last), "الأعلى تقييماً / best rated" rating first.
- **Near me** without coordinates asks for the location with `use_location`, `pick_area` and
  `all_venues`. The app should send `latitude`/`longitude` with `use_location`.
- **"Football tomorrow"** without an hour asks "أي ساعة؟" with the free hours and offers the
  same three buttons; `all_venues` then lists that day's options.
- **Only one sport on offer** (Football today): it is assumed when the player names none. A
  sport no venue offers yet is answered as coming soon.
- **Near misses**: when nothing is free at the hour asked for, the slots starting up to three
  hours from it come back as `suggestions` with `exact: false`, `suggested_venue_id` is the
  first one's venue (also logged), and the reply names them: "لا يوجد موعد متاح الساعة 17:00
  بالضبط. أقرب المواعيد: ملعب الجلاء غداً الساعة 20:00 (35 شيكل)..." / "Nothing is free at exactly
  17:00. Closest times: ...". With no close slot at all, the US-4.5 wording stays ("No venues
  match that request — try a different time.") and `suggestions` is empty.
- **A part of the day with nothing free** ("بكرا بعد العصر") says so and offers the hours free
  that day; a day with nothing free says so.
- **"Now" / "الحين" / "هلأ"** without an hour lists what is left today from the current time.
- **Prices** (`ما هي أسعار الملاعب؟`) and **working hours** (`ما هي مواعيد العمل؟`) are computed
  from the time slots of the next 14 days, filtered by the sport, city or area named. Prices
  come from the **free** slots only (what can be booked); working hours from **every** published
  slot, free, held or booked (when the venues are open). Past slots never count.
- **Dates** in replies: "اليوم" / "غداً" / "today" / "tomorrow", otherwise "يوم الإثنين 5 أكتوبر" /
  "on Mon 5 Oct" (lists: "الإثنين 5 أكتوبر" / "Mon 5 Oct"); never YYYY-MM-DD.
- **Places**: a city or area Gemini returns counts only when the text names a place.
- **Honesty**: never a booked, blocked or past slot, an inactive or removed venue, or a venue
  that doesn't offer the sport. The assistant can't book by itself; "yes" after a suggestion
  says how to book it (`confirm_booking`).

### Example: a follow-up (Arabic)

```http
POST /api/v1/assistant/query
{"query_text": "بدي ملعب كورة بكرا"}
```

```json
{"success": true, "message": "OK", "errors": null, "data": {
  "assistant_query_id": 1, "parsed_sport_id": 1, "parsed_date": "2026-10-04", "parsed_hour": null,
  "suggested_venue_id": null, "reply_text": "أي ساعة؟ المتاح مساءً: 20:00.",
  "conversation_id": "b845a03b-8ba3-458c-afb9-5dd06c48c622", "intent": "booking_search",
  "outcome": "needs_details", "parsed_date_range": null, "suggestions": [],
  "options": [{"id": "use_location", "label": "استخدم موقعي"}, {"id": "pick_area", "label": "اختر منطقة"},
              {"id": "all_venues", "label": "كل الملاعب"}]}}
```

```http
POST /api/v1/assistant/query
{"query_text": "8", "conversation_id": "b845a03b-8ba3-458c-afb9-5dd06c48c622"}
```

```json
{"success": true, "message": "OK", "errors": null, "data": {
  "assistant_query_id": 2, "parsed_sport_id": 1, "parsed_date": "2026-10-04", "parsed_hour": "20:00",
  "suggested_venue_id": 1,
  "reply_text": "يتوفر موعد في ملعب الحقل الأخضر غداً الساعة 20:00 بسعر 50 شيكل للساعة. هل تريد أن أحجزه لك؟",
  "conversation_id": "b845a03b-8ba3-458c-afb9-5dd06c48c622", "intent": "booking_search",
  "outcome": "suggestion", "parsed_date_range": null,
  "suggestions": [
    {"venue_id": 1, "time_slot_id": 1, "name_ar": "ملعب الحقل الأخضر", "name_en": "Green Field Court",
     "area_ar": "الرمال", "area_en": "Al-Rimal", "city_ar": "غزة", "city_en": "Gaza", "date": "2026-10-04",
     "start_time": "20:00", "end_time": "21:00", "price": 50, "rating": 4.5, "distance_km": null, "exact": true},
    {"venue_id": 2, "time_slot_id": 2, "name_ar": "ملعب الجلاء", "name_en": "Al-Jalaa Stadium", "...": "..."}],
  "options": [{"id": "cheapest", "label": "الأرخص"}, {"id": "best_rated", "label": "الأعلى تقييماً"},
              {"id": "use_location", "label": "استخدم موقعي"}]}}
```

### Example: near me with a location (quick reply)

```http
POST /api/v1/assistant/query
{"query_text": "ابحث عن ملاعب قريبة مني"}
→ outcome "needs_location", options use_location / pick_area / all_venues

POST /api/v1/assistant/query
{"option_id": "use_location", "latitude": 31.5095, "longitude": 34.4625, "conversation_id": "..."}
```

```json
{"data": {"suggested_venue_id": 2, "intent": "booking_search", "outcome": "suggestion",
  "parsed_date_range": {"period": "upcoming", "from": "2026-10-03", "to": "2026-10-09"},
  "reply_text": "الأقرب إليك في الأيام القادمة: ملعب الجلاء غداً الساعة 20:00 (35 شيكل، 0.1 كم)، ملعب الحقل الأخضر غداً الساعة 20:00 (50 شيكل، 1.7 كم)، الملعب الجنوبي الإثنين 5 أكتوبر الساعة 19:00 (60 شيكل، 24.4 كم). أيها تريد؟",
  "suggestions": [{"venue_id": 2, "distance_km": 0.1, "...": "..."}, {"venue_id": 1, "distance_km": 1.7}, {"venue_id": 3, "distance_km": 24.4}]}}
```

The location is kept rounded to about 100 m in the conversation and forgotten when it expires;
it is never sent to Gemini.

### Example: a question (English)

```http
POST /api/v1/assistant/query
{"query_text": "how do I cancel a booking?"}
```

```json
{"data": {"intent": "cancellation", "outcome": "answer", "suggested_venue_id": null, "suggestions": [], "options": [],
  "reply_text": "There is no cancel button in the app yet. A new booking waits about 10 minutes for payment: if you don't upload the payment receipt in that time, it can be cancelled automatically and the slot is free again. If you already uploaded the receipt or the booking is confirmed and you want to cancel, please contact the venue directly. If the receipt is rejected at review, the booking is cancelled automatically."}}
```

## `POST /api/v1/assistant/feedback`

The "تقييم تجربتك" screen. A saved rating answers **201 Created**; validation errors are 422,
another player's conversation 403, an unknown one 404, more than 10 ratings an hour 429.

| Field | Type | Notes |
|---|---|---|
| `rating` | integer 1-5 | Required. |
| `liked_aspects` | array, optional | Keys: `booking_ease` (سهولة الحجز), `venue_variety` (تنوع الملاعب), `info_accuracy` (دقة المعلومات), `app_experience` (تجربة التطبيق), `prices` (الأسعار), `customer_service` (خدمة العملاء). |
| `comment` | string, optional | Up to 500 characters. |
| `conversation_id` | uuid, optional | Must be the player's own (403 / 404 otherwise). |

```http
POST /api/v1/assistant/feedback
{"rating": 5, "liked_aspects": ["booking_ease"], "comment": "ممتاز", "conversation_id": "f0d180bc-111b-47d5-acc8-248aa59ac147"}
```

```http
HTTP/1.1 201 Created
{"success": true, "message": "Thank you for your feedback.", "errors": null, "data": {
  "feedback_id": 1, "rating": 5,
  "liked_aspects": [{"key": "booking_ease", "label_ar": "سهولة الحجز", "label_en": "Ease of booking"}],
  "comment": "ممتاز", "conversation_id": "f0d180bc-111b-47d5-acc8-248aa59ac147"}}
```

## Limits and configuration

| Env | Default | Meaning |
|---|---|---|
| `ASSISTANT_HOURLY_LIMIT` | 20 | Messages with text per player per hour (every message counts, whether Gemini answers it or not). |
| `ASSISTANT_OPTION_HOURLY_LIMIT` | 60 | Quick-reply taps (`option_id` without text) per player per hour; they never reach Gemini. |
| `ASSISTANT_CONVERSATION_MINUTES` | 30 | Idle minutes after which a conversation starts afresh. |
| `ASSISTANT_FEEDBACK_HOURLY_LIMIT` | 10 | Ratings per player per hour. |
| `GEMINI_API_KEY`, `GEMINI_MODEL`, `GEMINI_TIMEOUT` | | Without a key, or when Gemini fails, times out or hits its quota, the rule-based fallback answers alone. Questions, small talk and taps never use Gemini. Gemini sees only the message, the last three turns of the conversation and the search values settled so far. |

## What the data needs

The assistant only suggests what is in the database, so it shines when venues are `active`,
linked to Football in `venue_sports`, have **upcoming time slots** with prices, a rating, an
area, and **latitude/longitude** (for "near me" and `distance_km`).
