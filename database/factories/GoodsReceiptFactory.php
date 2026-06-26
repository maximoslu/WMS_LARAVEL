<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoodsReceipt>
 */
class GoodsReceiptFactory extends Factory
{
    protected $model = GoodsReceipt::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'supplier_id' => Supplier::factory(),
            'receipt_number' => strtoupper(fake()->bothify('RCPT-#####')),
            'external_document_number' => strtoupper(fake()->bothify('ALB-#####')),
            'status' => GoodsReceipt::STATUS_DRAFT,
            'received_at' => fake()->date(),
            'notes' => fake()->optional()->sentence(),
            'document_path' => null,
            'document_original_name' => null,
            'document_mime' => null,
            'document_processed_at' => null,
            'ai_status' => null,
            'ai_extracted_data' => null,
            'ai_error' => null,
            'created_by' => User::factory(),
            'confirmed_by' => null,
            'confirmed_at' => null,
        ];
    }
}
