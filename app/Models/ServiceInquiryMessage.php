<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceInquiryMessage extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $visible = ['id', 'service_inquiry_id', 'author_user_id', 'body', 'created_at'];

    protected function casts(): array
    {
        return ['service_inquiry_id' => 'integer', 'author_user_id' => 'integer', 'created_at' => 'datetime'];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(ServiceInquiry::class, 'service_inquiry_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
