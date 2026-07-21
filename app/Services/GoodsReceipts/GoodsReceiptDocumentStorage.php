<?php

namespace App\Services\GoodsReceipts;

use App\Models\GoodsReceipt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class GoodsReceiptDocumentStorage
{
    /**
     * @return array<string, mixed>
     */
    public function store(UploadedFile $document, ?GoodsReceipt $receipt = null): array
    {
        return [
            'document_path' => $document->store('goods-receipts', 'local'),
            'document_original_name' => $document->getClientOriginalName(),
            'document_mime' => $document->getMimeType(),
            'document_processed_at' => null,
            'ai_status' => GoodsReceipt::AI_STATUS_PENDING,
            'ai_extracted_data' => null,
            'ai_error' => null,
        ];
    }

    public function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        }
    }

    public function resolveDisk(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return $disk;
            }
        }

        return null;
    }

    /**
     * @return array{disk: string, path: string, contents: string, mime: string|null, original_name: string|null}|null
     */
    public function read(GoodsReceipt $receipt): ?array
    {
        $disk = $this->resolveDisk($receipt->document_path);

        if ($disk === null || $receipt->document_path === null) {
            return null;
        }

        return [
            'disk' => $disk,
            'path' => $receipt->document_path,
            'contents' => Storage::disk($disk)->get($receipt->document_path),
            'mime' => $receipt->document_mime,
            'original_name' => $receipt->document_original_name,
        ];
    }
}
