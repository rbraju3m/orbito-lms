<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Enums;

enum DeliveryStatus: string
{
    /* Not yet sent, or waiting for its next retry. */
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    /* Every attempt used up. Redelivering makes a NEW delivery. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Succeeded => 'Delivered',
            self::Failed => 'Failed',
        };
    }
}
