<?php

namespace App\Services\GoodsReceipts;

use App\Models\GoodsReceipt;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

class OpenAiGoodsReceiptExtractor implements GoodsReceiptAiExtractorInterface
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly GoodsReceiptDocumentStorage $documents,
    ) {}

    public function extractFromDocument(GoodsReceipt $receipt): GoodsReceiptAiExtractionResult
    {
        $apiKey = (string) config('services.openai.api_key');
        $model = (string) config('services.openai.receipt_model', 'gpt-4.1');

        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY no esta configurada.');
        }

        $document = $this->documents->read($receipt);

        if ($document === null) {
            throw new RuntimeException('La entrada no tiene un documento accesible para interpretar con IA.');
        }

        $response = $this->http
            ->withToken($apiKey)
            ->acceptJson()
            ->timeout(120)
            ->post('https://api.openai.com/v1/responses', [
                'model' => $model,
                'store' => false,
                'input' => [
                    [
                        'role' => 'developer',
                        'content' => [
                            [
                                'type' => 'input_text',
                                'text' => $this->instructionPrompt(),
                            ],
                        ],
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            $this->documentInputContent($document),
                            [
                                'type' => 'input_text',
                                'text' => 'Extrae una propuesta de recepcion de mercancia para un WMS. Si dudas, deja campos vacios y devuelve avisos claros.',
                            ],
                        ],
                    ],
                ],
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'goods_receipt_ai_extraction',
                        'description' => 'Propuesta estructurada de una entrada de mercancia a partir de un albaran o documento de proveedor.',
                        'strict' => true,
                        'schema' => $this->responseSchema(),
                    ],
                ],
            ]);

        if ($response->failed()) {
            $errorMessage = (string) data_get($response->json(), 'error.message', '');
            $normalizedMessage = mb_strtolower($errorMessage);

            if (
                str_contains($normalizedMessage, 'vision')
                || str_contains($normalizedMessage, 'pdf')
                || str_contains($normalizedMessage, 'image')
                || str_contains($normalizedMessage, 'input_file')
                || str_contains($normalizedMessage, 'input_image')
            ) {
                throw new RuntimeException('Modelo IA no compatible con documento visual o PDF escaneado.');
            }

            if ($errorMessage !== '') {
                throw new RuntimeException($errorMessage);
            }

            $response->throw();
        }

        $payload = $response->json();
        $status = (string) ($payload['status'] ?? '');

        if ($status !== 'completed') {
            throw new RuntimeException('La respuesta de OpenAI no termino correctamente.');
        }

        $outputText = $this->extractOutputText($payload);

        if ($outputText === null) {
            throw new RuntimeException('OpenAI no devolvio una propuesta interpretable.');
        }

        $decoded = json_decode($outputText, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI devolvio una respuesta no valida para la extraccion estructurada.');
        }

        $decoded['provider'] = 'openai';
        $decoded['model'] = $model;

        return GoodsReceiptAiExtractionResult::fromArray($decoded);
    }

    /**
     * @param  array{contents: string, mime: string|null, original_name: string|null}  $document
     * @return array<string, mixed>
     */
    private function documentInputContent(array $document): array
    {
        $mime = $document['mime'] ?: 'application/octet-stream';
        $filename = $document['original_name'] ?: 'goods-receipt-document';
        $base64 = base64_encode($document['contents']);

        if (str_starts_with($mime, 'image/')) {
            return [
                'type' => 'input_image',
                'detail' => 'high',
                'image_url' => 'data:'.$mime.';base64,'.$base64,
            ];
        }

        $payload = [
            'type' => 'input_file',
            'filename' => $filename,
            'file_data' => 'data:'.$mime.';base64,'.$base64,
        ];

        if ($mime === 'application/pdf') {
            $payload['detail'] = 'high';
        }

        return $payload;
    }

    private function instructionPrompt(): string
    {
        return <<<'TEXT'
Eres un asistente de recepcion WMS especializado en interpretar albaranes y documentos de proveedor.

Debes devolver solo una propuesta estructurada para revision humana:
- Nunca inventes datos si el documento no los muestra con claridad.
- Prioriza marca visible, logo, remitente, emisor y proveedor legal cuando aparezcan en el documento.
- Si no puedes detectar un valor, usa null, 0 o lista de avisos.
- SKU y descripcion son datos de articulo.
- El lote es trazabilidad operativa de la entrada y no debe tratarse como maestro del articulo.
- total_units es la cantidad total recibida en unidades.
- units_per_pallet es el paletizado detectado o inferido con cautela.
- full_pallets y peak_units deben cuadrar con total_units cuando sea posible.
- confidence debe ir de 0 a 1.
- warnings debe recoger dudas, incoherencias o faltas de informacion.
- Si hay varias lineas, devuelve una por referencia o producto claramente distinguible.
- En supplier_name, devuelve el nombre comercial o legal mas reconocible para que un usuario de almacen pueda localizar el proveedor facilmente.
TEXT;
    }

    /**
     * @return array<string, mixed>
     */
    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'supplier_name' => ['type' => ['string', 'null']],
                'delivery_note_number' => ['type' => ['string', 'null']],
                'received_date' => ['type' => ['string', 'null']],
                'confidence' => ['type' => ['number', 'null']],
                'warnings' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'lines' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'sku' => ['type' => ['string', 'null']],
                            'description' => ['type' => ['string', 'null']],
                            'lot' => ['type' => ['string', 'null']],
                            'units_per_pallet' => ['type' => ['integer', 'null']],
                            'total_units' => ['type' => 'integer'],
                            'full_pallets' => ['type' => ['integer', 'null']],
                            'peak_units' => ['type' => ['integer', 'null']],
                            'confidence' => ['type' => ['number', 'null']],
                            'warnings' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['sku', 'description', 'lot', 'units_per_pallet', 'total_units', 'full_pallets', 'peak_units', 'confidence', 'warnings'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['supplier_name', 'delivery_note_number', 'received_date', 'confidence', 'warnings', 'lines'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractOutputText(array $payload): ?string
    {
        if (is_string($payload['output_text'] ?? null) && trim((string) $payload['output_text']) !== '') {
            return (string) $payload['output_text'];
        }

        foreach (($payload['output'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach (($item['content'] ?? []) as $content) {
                if (! is_array($content)) {
                    continue;
                }

                if (($content['type'] ?? null) === 'refusal') {
                    throw new RuntimeException('OpenAI rechazo interpretar este documento.');
                }

                if (is_string($content['text'] ?? null) && trim((string) $content['text']) !== '') {
                    return (string) $content['text'];
                }
            }
        }

        return null;
    }
}
