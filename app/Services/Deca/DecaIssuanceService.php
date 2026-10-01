<?php

namespace App\Services\Deca;

use App\Models\DecaDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class DecaIssuanceService
{
    public function __construct(private DecaPdfService $pdf) {}

    public function issue(User $user, array $data): DecaDocument
    {
        $path = null;
        try {
            return DB::transaction(function () use ($user, $data, &$path): DecaDocument {
                // Serialize submissions for this operator, including double taps/retries.
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $existing = DecaDocument::where('created_by', $user->id)
                    ->where('submission_key', $data['submission_key'])->first();
                if ($existing) {
                    return $existing;
                }

                $base = rtrim((string) config('deca.public_base_url'), '/');
                if (parse_url($base, PHP_URL_SCHEME) !== 'https' || ! parse_url($base, PHP_URL_HOST)) {
                    throw ValidationException::withMessages(['carrier_key' => 'Administración debe configurar la dirección HTTPS pública del DECA antes de emitir.']);
                }
                $carrier = config('deca.carriers.'.$data['carrier_key']);
                if (! $carrier || blank($carrier['tax_id'])) {
                    throw ValidationException::withMessages(['carrier_key' => 'El transportista no tiene sus datos fiscales configurados.']);
                }
                $now = now()->toImmutable();
                $token = bin2hex(random_bytes(32));
                $number = 'DECA-'.$now->format('Y').'-'.Str::ulid();
                $document = new DecaDocument([
                    'number' => $number,
                    'created_by' => $user->id,
                    'submission_key' => $data['submission_key'],
                    'carrier_key' => $data['carrier_key'],
                    'transport_date' => $data['transport_date'],
                    'snapshot' => [...collect($data)->except(['submission_key', 'confirmed'])->all(), 'carrier' => $carrier],
                    'public_token' => $token,
                    'public_url' => $base.route('deca.public-download', ['token' => $token], false),
                    'pdf_path' => 'deca/'.$now->format('Y/m').'/'.$number.'.pdf',
                    'issued_at' => $now,
                    // No automatic purge: at least a year beyond the planned transport date.
                    'retain_until' => CarbonImmutable::parse($data['transport_date'], 'Europe/Madrid')->addYear()->endOfDay()->utc(),
                ]);
                $bytes = $this->pdf->render($document);
                $path = $document->pdf_path;
                if (! Storage::disk('local')->put($path, $bytes)) {
                    throw new RuntimeException('No se pudo conservar el PDF DECA.');
                }
                $document->pdf_sha256 = hash('sha256', $bytes);
                $document->pdf_size = strlen($bytes);
                $document->save();

                return $document;
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }
}
