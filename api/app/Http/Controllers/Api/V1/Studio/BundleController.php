<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Studio;

use App\Domain\Catalog\Actions\CreateBundle;
use App\Domain\Catalog\Actions\DeleteBundle;
use App\Domain\Catalog\Actions\UpdateBundle;
use App\Domain\Catalog\Models\Bundle;
use App\Domain\Catalog\Support\BundlePublishChecklist;
use App\Http\Requests\Catalog\StoreBundleRequest;
use App\Http\Requests\Catalog\UpdateBundleRequest;
use App\Http\Resources\Catalog\BundleListResource;
use App\Http\Resources\Catalog\BundleResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Authoring bundles. Reads for the catalogue live in `BundleCatalogController`
 * — two audiences, two resources' worth of decisions (ADR-06).
 */
final class BundleController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Bundle::class);

        $bundles = Bundle::query()
            ->withCount('courses')
            ->with(['thumbnail', 'product.prices'])
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')->value()),
            )
            ->latest('updated_at')
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        return ApiResponse::ok(BundleListResource::collection($bundles));
    }

    public function store(StoreBundleRequest $request, CreateBundle $action): JsonResponse
    {
        Gate::authorize('create', Bundle::class);

        return ApiResponse::created(
            BundleResource::make($action->handle($request->toData()))
        );
    }

    public function show(Request $request, Bundle $bundle, BundlePublishChecklist $checklist): JsonResponse
    {
        Gate::authorize('view', $bundle);

        $bundle->load(['courses.product.prices', 'thumbnail', 'product.prices']);

        return ApiResponse::ok([
            ...BundleResource::make($bundle)->resolve($request),
            /*
             * The checklist the author acts on, from the rules the server
             * enforces on publish — so the button and the answer cannot
             * disagree (§10). Only here, never on the list: it is several
             * queries per bundle.
             */
            'checklist' => $checklist->evaluate($bundle),
            'available_actions' => array_map(
                static fn ($status): string => $status->value,
                $bundle->status->allowedTransitions(),
            ),
        ]);
    }

    public function update(UpdateBundleRequest $request, Bundle $bundle, UpdateBundle $action): JsonResponse
    {
        Gate::authorize('update', $bundle);

        return ApiResponse::ok(
            BundleResource::make($action->handle($bundle, $request->toData(), $request->suppliedKeys()))
        );
    }

    /**
     * Deleting a bundle does NOT touch what anybody bought. The enrolments it
     * granted are enrolments like any other, and `order_items` keeps its own
     * title snapshot, so an old receipt still reads correctly.
     */
    public function destroy(Bundle $bundle, DeleteBundle $action): JsonResponse
    {
        Gate::authorize('delete', $bundle);

        $action->handle($bundle);

        return ApiResponse::noContent();
    }
}
