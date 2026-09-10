<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Models\Media;
use Illuminate\Validation\Validator;

/**
 * An id that EXISTS is not an id you may use (§10).
 *
 * The media must be the caller's own, and of the collection the field expects
 * — otherwise anyone could point their cover at somebody else's private file.
 * `UpdateCourseRequest` and `UpsertLessonRequest` carry private copies of this
 * from before it was shared; the bundle requests shipped WITHOUT it, checking
 * `exists` alone, which this trait was written to fix.
 */
trait ValidatesOwnedMedia
{
    protected function assertOwnedMedia(Validator $validator, string $field, MediaCollection $collection): void
    {
        $id = $this->input($field);

        if (! is_numeric($id)) {
            return;
        }

        $media = Media::find((int) $id);

        if ($media === null
            || $media->owner_id !== $this->user()?->id
            || $media->collection !== $collection->value) {
            $validator->errors()->add($field, 'That file is not available for this field.');
        }
    }
}
