<?php

namespace App\Http\Controllers\Travel;

use App\Http\Controllers\Controller;
use App\Models\ContractDocument;
use App\Support\Travel\ContractTerms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Contract files from the private disk, for people who may see the
 * contract's commercial terms.
 */
class ContractDocumentController extends Controller
{
    public function __invoke(Request $request, ContractDocument $document): StreamedResponse
    {
        abort_unless(ContractTerms::seesDocuments($request->user(), $document->contract->provider), 403);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->response($document->path, $document->original_name);
    }
}
