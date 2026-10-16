<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's rating of the booking assistant ("تقييم تجربتك"), optionally about one conversation.
 */
class AssistantFeedback extends Model
{
    protected $table = 'assistant_feedback';

    /**
     * What a player can say they liked, with the labels the app shows ([Arabic, English]).
     */
    public const ASPECTS = [
        'booking_ease' => ['سهولة الحجز', 'Ease of booking'],
        'venue_variety' => ['تنوع الملاعب', 'Variety of venues'],
        'info_accuracy' => ['دقة المعلومات', 'Accurate information'],
        'app_experience' => ['تجربة التطبيق', 'App experience'],
        'prices' => ['الأسعار', 'Prices'],
        'customer_service' => ['خدمة العملاء', 'Customer service'],
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'assistant_conversation_id',
        'rating',
        'liked_aspects',
        'comment',
    ];

    protected $casts = [
        'rating' => 'integer',
        'liked_aspects' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'assistant_conversation_id');
    }
}
