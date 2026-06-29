<?php

namespace App\Services\DailyOperations;

use App\Models\DailyOperationLine;

class DailyOperationLineAutomationService
{
    public function syncAssociatedLinesForManualLine(DailyOperationLine $line, int $userId): void
    {
        $line->loadMissing('day');

        $this->syncManagementAssociation($line, $userId);
        $this->syncTripAssociation($line, $userId);
    }

    public function removeAssociatedAutoLinesForManualLine(DailyOperationLine $line): void
    {
        DailyOperationLine::query()
            ->where('day_id', $line->day_id)
            ->where('source_type', DailyOperationLine::SOURCE_MANUAL_LINE)
            ->where('parent_line_id', $line->id)
            ->delete();
    }

    private function syncManagementAssociation(DailyOperationLine $line, int $userId): void
    {
        $associatedManagement = $this->associatedLine($line, DailyOperationLine::SECTION_GESTION_CAMION);

        if (! $line->requiresTruckManagement()) {
            $associatedManagement?->delete();

            return;
        }

        if ($associatedManagement !== null) {
            return;
        }

        $this->createAssociatedLine(
            $line,
            DailyOperationLine::SECTION_GESTION_CAMION,
            1,
            'Generada por '.$line->sectionLabel().'.',
            $userId,
        );
    }

    private function syncTripAssociation(DailyOperationLine $line, int $userId): void
    {
        $associatedTrip = $this->associatedLine($line, DailyOperationLine::SECTION_VIAJE_CAMION);

        if (! $line->requiresTruckTrip()) {
            $associatedTrip?->delete();

            return;
        }

        if ($associatedTrip !== null) {
            return;
        }

        $this->createAssociatedLine(
            $line,
            DailyOperationLine::SECTION_VIAJE_CAMION,
            1,
            'Generada por '.$line->sectionLabel().'.',
            $userId,
        );
    }

    private function associatedLine(DailyOperationLine $line, string $section): ?DailyOperationLine
    {
        return DailyOperationLine::query()
            ->where('day_id', $line->day_id)
            ->where('section', $section)
            ->where('source_type', DailyOperationLine::SOURCE_MANUAL_LINE)
            ->where('parent_line_id', $line->id)
            ->first();
    }

    private function createAssociatedLine(
        DailyOperationLine $line,
        string $section,
        int $units,
        string $observations,
        int $userId,
    ): void {
        $sortOrder = (int) $line->day->lines()->max('sort_order') + 1;

        $line->day->lines()->create([
            'section' => $section,
            'counterparty_name' => $line->counterparty_name,
            'pallets' => $units,
            'observations' => $observations,
            'without_booking' => false,
            'is_auto_generated' => true,
            'source_type' => DailyOperationLine::SOURCE_MANUAL_LINE,
            'source_id' => $line->id,
            'parent_line_id' => $line->id,
            'sort_order' => $sortOrder,
            'created_by' => $userId,
        ]);
    }
}
