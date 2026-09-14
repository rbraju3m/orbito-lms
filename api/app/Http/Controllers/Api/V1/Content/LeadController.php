<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Content;

use App\Domain\Content\Actions\ChangeLeadStatus;
use App\Domain\Content\Actions\DeleteLead;
use App\Domain\Content\Enums\LeadStatus;
use App\Domain\Content\Models\Lead;
use App\Http\Requests\Content\UpdateLeadRequest;
use App\Http\Resources\Content\LeadResource;
use App\Support\Http\ApiResponse;
use App\Support\Http\CsvDownload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** An academy's captured leads (`lead.view`, `lead.manage`, `lead.export`). See docs/LEADS.md. */
final class LeadController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Lead::class);

        $leads = $this->filtered($request)
            ->orderByDesc('last_submitted_at')
            // `last_submitted_at` has second precision; without a tiebreak a
            // row can appear on two pages or on none (§ Phase 8).
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return ApiResponse::ok(LeadResource::collection($leads)->additional(['meta' => [
            'can_manage' => Gate::allows('manage', Lead::class),
            'can_export' => Gate::allows('export', Lead::class),
        ]]));
    }

    /** The same filters as the list, so the file is what the screen showed. */
    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('export', Lead::class);

        $leads = $this->filtered($request)->orderBy('id')->cursor();

        return CsvDownload::stream(
            'leads-'.now()->toDateString().'.csv',
            ['email', 'name', 'status', 'source', 'source_title', 'submissions', 'consent_text',
                'consented_at', 'first_submitted_at', 'last_submitted_at'],
            (function () use ($leads): iterable {
                foreach ($leads as $lead) {
                    yield [
                        $lead->email, $lead->name, $lead->status->value, $lead->source->value,
                        $lead->source_title, $lead->submissions_count, $lead->consent_text,
                        $lead->consented_at->toIso8601String(), $lead->created_at->toIso8601String(),
                        $lead->last_submitted_at->toIso8601String(),
                    ];
                }
            })(),
        );
    }

    public function update(UpdateLeadRequest $request, Lead $lead, ChangeLeadStatus $action): JsonResponse
    {
        Gate::authorize('update', $lead);

        return ApiResponse::ok(LeadResource::make($action->handle($lead, $request->leadStatus())));
    }

    public function destroy(Lead $lead, DeleteLead $action): JsonResponse
    {
        Gate::authorize('delete', $lead);

        $action->handle($lead);

        return ApiResponse::noContent();
    }

    /** @return Builder<Lead> */
    private function filtered(Request $request): Builder
    {
        $status = LeadStatus::tryFrom((string) $request->query('status', ''));
        $search = addcslashes(trim((string) $request->query('q', '')), '%_\\');

        return Lead::query()
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('email', 'like', '%'.$search.'%')
                ->orWhere('name', 'like', '%'.$search.'%')));
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 20), 1), (int) config('orbito.pagination.max_per_page'));
    }
}
