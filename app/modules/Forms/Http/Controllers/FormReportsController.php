<?php

namespace App\Modules\Forms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Forms\DTOs\FormReportDTO;
use App\Modules\Forms\Http\Requests\StoreFormReportRequest;
use App\Modules\Forms\Http\Resources\FormReportResource;
use App\Modules\Forms\Models\FormReport;
use App\Modules\Forms\Services\FormReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class FormReportsController extends Controller
{
    public function __construct(
        private FormReportService $service
    ) {}

    public function indexByForm(Request $request, string $formId): AnonymousResourceCollection
    {
        $paginator = $this->service->indexByForm($request, $formId);

        return FormReportResource::collection($paginator);
    }

    public function show(Request $request, string $id): FormReportResource
    {
        $report = FormReport::with('form', 'creator', 'file')
            ->withTrashed()
            ->findOrFail($id);

        $this->authorize('view', $report);

        return FormReportResource::make($report);
    }

    public function store(StoreFormReportRequest $request): FormReportResource
    {
        $report = $this->service->create(
            FormReportDTO::fromRequest($request)
        );

        return FormReportResource::make(
            $report->loadMissing(['form', 'creator'])
        );
    }

    public function destroy(Request $request, FormReport $report): Response
    {
        $this->authorize('delete', $report);

        $report->delete();

        return response()->noContent();
    }

    /**
     * Restore a soft-deleted report.
     */
    public function restore(string $id): FormReportResource
    {
        $report = FormReport::withTrashed()->findOrFail($id);

        $this->authorize('restore', $report);

        $report->restore();

        return FormReportResource::make(
            $report->loadMissing(['form', 'creator', 'file'])
        );
    }

    /**
     * Permanently delete a soft-deleted report.
     */
    public function forceDestroy(string $id): Response
    {
        $report = FormReport::withTrashed()->findOrFail($id);

        $this->authorize('forceDelete', $report);

        $report->forceDelete();

        return response()->noContent();
    }
}
