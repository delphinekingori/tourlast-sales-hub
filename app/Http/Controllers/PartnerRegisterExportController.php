<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Exports\PartnerRegisterExport;
use App\Models\User;
use App\Support\PartnerRegisterFilters;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PartnerRegisterExportController extends Controller
{
    /**
     * Download the filtered Partner Register as an Excel file.
     */
    public function excel(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->can(Permission::ExportPartnerRegister->value), 403);

        $filters = PartnerRegisterFilters::fromArray($request->query());

        return Excel::download(new PartnerRegisterExport($filters), 'tourlast-partner-register-'.now()->format('Y-m-d').'.xlsx');
    }

    /**
     * Download the branded onboarding report as a PDF.
     */
    public function pdf(Request $request): Response
    {
        abort_unless($request->user()->can(Permission::ExportPartnerRegister->value), 403);

        $filters = PartnerRegisterFilters::fromArray($request->query());
        $partners = $filters->query()->get();

        $pdf = Pdf::loadView('reports.partner-register', [
            'filters' => $filters,
            'partners' => $partners,
            'byType' => $partners->countBy(fn ($partner) => $partner->propertyTypeLabel())->sortDesc(),
            'bySalesperson' => $partners->countBy(fn ($partner) => $partner->user?->name ?? 'Unattributed')->sortDesc(),
            'salesperson' => $filters->salespersonId ? User::find($filters->salespersonId) : null,
            'statusLabel' => PartnerRegisterFilters::statusOptions()[$filters->status],
            'generatedBy' => $request->user()->name,
            'logo' => base64_encode((string) file_get_contents(public_path('images/tourlast-logo.png'))),
        ])->setPaper('a4');

        return $pdf->download('tourlast-onboarding-report-'.now()->format('Y-m-d').'.pdf');
    }
}
