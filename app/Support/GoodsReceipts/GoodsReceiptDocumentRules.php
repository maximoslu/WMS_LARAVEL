<?php

namespace App\Support\GoodsReceipts;

final class GoodsReceiptDocumentRules
{
    public const MAX_SIZE_KB = 51200;

    public const MAX_SIZE_LABEL = '50 MB';

    /** @return list<string> */
    public static function rules(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'file',
            'mimes:pdf,jpg,jpeg,png',
            'max:'.self::MAX_SIZE_KB,
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'document.max' => 'El documento no puede superar los 50 MB.',
            'document.mimes' => 'El documento debe ser un archivo PDF, JPG, JPEG o PNG.',
            'document.file' => 'El documento debe ser un archivo valido.',
            'document.required' => 'Adjunta un documento.',
        ];
    }

    public static function helperText(): string
    {
        return 'Formatos admitidos: PDF, JPG, JPEG o PNG. Tamaño máximo: 50 MB.';
    }
}
