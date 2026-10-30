<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryReadProgress extends Model
{
    protected $table = 'service_inquiry_reads';

    protected $guarded = ['*'];

    protected $visible = ['id', 'service_inquiry_id', 'user_id', 'last_read_message_id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['service_inquiry_id' => 'integer', 'user_id' => 'integer', 'last_read_message_id' => 'integer'];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(ServiceInquiry::class, 'service_inquiry_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lastReadMessage(): BelongsTo
    {
        return $this->belongsTo(ServiceInquiryMessage::class, 'last_read_message_id');
    }
}
