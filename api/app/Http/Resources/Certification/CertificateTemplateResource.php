<?php

declare(strict_types=1);

namespace App\Http\Resources\Certification;

use App\Domain\Certification\Models\CertificateTemplate;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin CertificateTemplate
 */
final class CertificateTemplateResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'orientation' => $this->orientation->value,
            'orientation_label' => $this->orientation->label(),
            'background_media_id' => $this->background_media_id,
            'background_url' => $this->whenLoaded(
                'background',
                fn () => $this->background?->publicUrl(),
            ),
            // Merged with the defaults so the editor always receives every
            // field, even for a template stored before one was added.
            'layout' => array_merge(CertificateTemplate::defaultLayout(), $this->layout),
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
