<?php

namespace App\Services\DailyOperations;

use App\Models\DailyOperationLine;

class DailyOperationLineAutomationService
{
    public function syncTruckManagementForManualLine(DailyOperationLine $line, int $userId): void
    {
        $line->loadMissing('day');

        $associatedManagement = DailyOperationLine::query()
            ->where('day_id', $line->day_id)
            ->where('section', DailyOperationLine::SECTION_GESTION_CAMION)
            ->where('source_type', DailyOperationLine::SOURCE_MANUAL_LINE)
            ->where('parent_line_id', $line->id)
            ->first();

        if (! $line->requiresTruckManagement()) {
            $associatedManagement?->delete();

            return;
        }

        if ($associatedManagement !== null) {
            return;
        }

        $sortOrder = (int) $line->day->lines()->max('sort_order') + 1;

        $line->day->lines()->create([
            'section' => DailyOperationLine::SECTION_GESTION_CAMION,
            'counterparty_name' => $line->counterparty_name,
            'pallets' => 1,
            'observations' => 'Generada por '.$line->sectionLabel().'.',
            'without_booking' => false,
            'is_auto_generated' => true,
            'source_type' => DailyOperationLine::SOURCE_MANUAL_LINE,
            'source_id' => $line->id,
            'parent_line_id' => $line->id,
            'sort_order' => $sortOrder,
            'created_by' => $userId,
        ]);
    }

    public function removeTruckManagementForManualLine(DailyOperationLine $line): void
    {
        DailyOperationLine::query()
            ->where('day_id', $line->day_id)
            ->where('section', DailyOperationLine::SECTION_GESTION_CAMION)
            ->where('source_type', DailyOperationLine::SOURCE_MANUAL_LINE)
            ->where('parent_line_id', $line->id)
            ->delete();
    }
}
