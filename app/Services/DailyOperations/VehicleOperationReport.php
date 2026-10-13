<?php

namespace App\Services\DailyOperations;

use App\Models\DailyOperationDay;
use App\Models\DailyOperationLine;
use App\Models\GoodsDispatch;
use App\Models\GoodsReceipt;
use App\Support\VehicleType;

class VehicleOperationReport
{
    public function forDay(DailyOperationDay $day): array
    {
        $day->loadMissing(['client', 'lines']);
        if (! $day->client?->requiresVehicleType()) {
            return ['totals' => [], 'sources' => []];
        }
        $sources = [];
        foreach ([DailyOperationLine::SOURCE_GOODS_RECEIPT => GoodsReceipt::class, DailyOperationLine::SOURCE_GOODS_DISPATCH => GoodsDispatch::class] as $source => $model) {
            $ids = $day->lines->where('source_type', $source)->pluck('source_id')->filter()->unique();
            foreach ($model::where('client_id', $day->client_id)->whereIn('id', $ids)->get(['id', 'vehicle_type']) as $movement) {
                $sources[$source.':'.$movement->id] = $movement->vehicle_type;
            }
        }
        $totals = [];
        foreach (['Entrada', 'Salida', 'Sin origen identificado'] as $direction) {
            foreach ([...VehicleType::OPTIONS, 'unknown' => 'Sin clasificar'] as $type => $label) {
                $totals[$direction][$type] = ['label' => $label, 'management' => 0, 'trips' => 0];
            }
        }
        foreach ($day->lines as $line) {
            $measure = match ($line->section) {
                DailyOperationLine::SECTION_GESTION_CAMION => 'management',
                DailyOperationLine::SECTION_VIAJE_CAMION => 'trips',
                default => null,
            };
            if ($measure === null) {
                continue;
            }
            $direction = match ($line->source_type) {
                DailyOperationLine::SOURCE_GOODS_RECEIPT => 'Entrada',
                DailyOperationLine::SOURCE_GOODS_DISPATCH => 'Salida',
                default => 'Sin origen identificado',
            };
            $type = $sources[$line->source_type.':'.$line->source_id] ?? 'unknown';
            $type = isset(VehicleType::OPTIONS[$type]) ? $type : 'unknown';
            $totals[$direction][$type][$measure] += (int) $line->pallets;
        }

        return ['totals' => $totals, 'sources' => $sources];
    }
}
