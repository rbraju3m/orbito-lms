<?php

declare(strict_types=1);

namespace App\Domain\Media\Events;

use App\Domain\Media\Models\Media;
use Illuminate\Foundation\Events\Dispatchable;

final class MediaUploaded
{
    use Dispatchable;

    public function __construct(public readonly Media $media) {}
}
