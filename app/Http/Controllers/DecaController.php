<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDecaDocumentRequest;
use App\Models\DecaDocument;
use App\Services\Deca\DecaIssuanceService;
use App\Services\Deca\DecaPdfService;
use App\Support\WmsNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class DecaController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('deca.index', [
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function create(Request $request): View
    {
        return view('deca.create', [
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
            'carriers' => config('deca.carriers'),
            'submissionKey' => (string) Str::uuid(),
        ]);
    }

    public function store(StoreDecaDocumentRequest $request, DecaIssuanceService $service): RedirectResponse
    {
        $document = $service->issue($request->user(), $request->validated());

        return to_route('deca.show', $document)->with('status', 'DECA emitido. Descarga el PDF y entrégalo al conductor antes de salir.');
    }

    public function documents(Request $request): View
    {
        return view('deca.documents', [
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
            'documents' => DecaDocument::latest('id')->paginate(20),
        ]);
    }

    public function show(Request $request, DecaDocument $decaDocument, DecaPdfService $pdf): View
    {
        return view('deca.show', [
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
            'document' => $decaDocument,
            'qr' => 'data:image/svg+xml;base64,'.base64_encode($pdf->qr($decaDocument->public_url)),
        ]);
    }

    public function download(DecaDocument $decaDocument): Response
    {
        return $this->pdfResponse($decaDocument);
    }

    public function publicDownload(string $token): Response
    {
        return $this->pdfResponse(DecaDocument::where('public_token', $token)->firstOrFail());
    }

    private function pdfResponse(DecaDocument $document): Response
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($document->pdf_path), 404, 'Documento no disponible.');
        $bytes = $disk->get($document->pdf_path);
        abort_unless(hash_equals($document->pdf_sha256, hash('sha256', $bytes)), 503, 'Documento temporalmente no disponible.');

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$document->number.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
