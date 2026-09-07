<?php

declare(strict_types=1);

namespace App\Http\Requests\Media;

use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Models\Media;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('upload', Media::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Framework-level caps only. The authoritative MIME and size rules live
        // on the collection and are enforced in StoreUploadedMedia against the
        // file's real bytes, not its declared type.
        return [
            'collection' => ['required', Rule::enum(MediaCollection::class)],
            'file' => ['required', 'file', 'max:'.$this->maxKilobytes()],
        ];
    }

    public function collection(): MediaCollection
    {
        return MediaCollection::from($this->string('collection')->value());
    }

    private function maxKilobytes(): int
    {
        $collection = MediaCollection::tryFrom((string) $this->input('collection'));

        return (int) ceil(($collection?->maxBytes() ?? 25 * 1024 * 1024) / 1024);
    }
}
