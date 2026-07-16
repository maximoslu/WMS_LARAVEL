<?php

namespace App\Jobs;

use App\Services\Traceability\StockAlertEvaluationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EvaluateStockAlertsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $clientId,
        public readonly int $itemId,
    ) {}

    public function handle(StockAlertEvaluationService $alerts): void
    {
        $alerts->evaluate($this->clientId, $this->itemId, apply: true);
    }

    public function uniqueId(): string
    {
        return $this->clientId.':'.$this->itemId;
    }

    public int $uniqueFor = 300;
}
