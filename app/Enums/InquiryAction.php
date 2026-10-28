<?php

namespace App\Enums;

enum InquiryAction: string
{
    case Accept = 'accept';
    case Reject = 'reject';
    case Cancel = 'cancel';
    case Complete = 'complete';

    public function source(): InquiryStatus
    {
        return $this === self::Complete ? InquiryStatus::Accepted : InquiryStatus::Pending;
    }

    public function target(): InquiryStatus
    {
        return match ($this) {
            self::Accept => InquiryStatus::Accepted,
            self::Reject => InquiryStatus::Rejected,
            self::Cancel => InquiryStatus::Cancelled,
            self::Complete => InquiryStatus::Completed,
        };
    }

    public function timestampColumn(): string
    {
        return $this->target()->value.'_at';
    }
}
