<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Certification;

use App\Domain\Certification\Models\CertificateTemplate;
use App\Http\Requests\Certification\StoreTemplateRequest;
use App\Http\Resources\Certification\CertificateTemplateResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Certificate designs, managed by the academy.
 *
 * Editing a template does NOT change certificates already issued: they carry
 * their own snapshot and their PDF is already rendered. That is deliberate —
 * a design change must not restyle documents people are holding.
 */
final class CertificateTemplateController
{
    public function index(): JsonResponse
    {
        Gate::authorize('manage-certificate-templates');

        $templates = CertificateTemplate::query()
            ->with('background')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return ApiResponse::ok(CertificateTemplateResource::collection($templates));
    }

    public function store(StoreTemplateRequest $request): JsonResponse
    {
        Gate::authorize('manage-certificate-templates');

        $template = DB::transaction(function () use ($request): CertificateTemplate {
            $template = CertificateTemplate::create([
                'name' => $request->string('name')->value(),
                'orientation' => $request->string('orientation')->value(),
                'background_media_id' => $request->input('background_media_id'),
                'layout' => array_merge(
                    CertificateTemplate::defaultLayout(),
                    $request->array('layout'),
                ),
                'is_default' => $request->boolean('is_default'),
                'is_active' => $request->has('is_active') ? $request->boolean('is_active') : true,
            ]);

            $this->settleDefault($template);

            return $template;
        });

        return ApiResponse::created(CertificateTemplateResource::make($template->fresh()));
    }

    public function update(StoreTemplateRequest $request, CertificateTemplate $template): JsonResponse
    {
        Gate::authorize('manage-certificate-templates');

        DB::transaction(function () use ($request, $template): void {
            $template->fill($request->only(['name', 'orientation', 'background_media_id']));

            if ($request->has('layout')) {
                // Merged, not replaced: a partial edit must not silently drop
                // every field the form did not send.
                $template->layout = array_merge($template->layout, $request->array('layout'));
            }

            if ($request->has('is_active')) {
                $template->is_active = $request->boolean('is_active');
            }

            if ($request->has('is_default')) {
                $template->is_default = $request->boolean('is_default');
            }

            $template->save();

            $this->settleDefault($template);
        });

        return ApiResponse::ok(CertificateTemplateResource::make($template->fresh()));
    }

    public function destroy(CertificateTemplate $template): JsonResponse
    {
        Gate::authorize('manage-certificate-templates');

        /*
         * Certificates keep working: `template_id` is nullOnDelete and the
         * renderer falls back to the default layout. Deleting a design must
         * not make an issued qualification unrenderable.
         */
        $template->delete();

        return ApiResponse::noContent();
    }

    /**
     * Exactly one default.
     *
     * Two defaults would make "which template does a new certificate use?"
     * depend on insertion order, which is the sort of thing that is only
     * noticed once a batch has been issued on the wrong design.
     */
    private function settleDefault(CertificateTemplate $template): void
    {
        if (! $template->is_default) {
            return;
        }

        CertificateTemplate::query()
            ->whereKeyNot($template->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
