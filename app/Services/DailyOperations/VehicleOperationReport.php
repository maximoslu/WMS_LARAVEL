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
        $modes = [];
        foreach ([DailyOperationLine::SOURCE_GOODS_RECEIPT => GoodsReceipt::class, DailyOperationLine::SOURCE_GOODS_DISPATCH => GoodsDispatch::class] as $source => $model) {
            $ids = $day->lines->where('source_type', $source)->pluck('source_id')->filter()->unique();
            foreach ($model::where('client_id', $day->client_id)->whereIn('id', $ids)->get(['id', 'vehicle_type', 'transport_mode']) as $movement) {
                $sources[$source.':'.$movement->id] = $movement->vehicle_type;
                $modes[$source.':'.$movement->id] = $movement->transport_mode;
            }
        }
        $totals = [];
        foreach (['Entrada', 'Salida', 'Sin origen identificado'] as $direction) {
            foreach ([...VehicleType::OPTIONS, 'unknown' => 'Sin clasificar'] as $type => $label) {
                $totals[$direction][$type] = ['label' => $label, 'management' => 0, 'trips' => 0, 'monge' => 0, 'subcontracted' => 0, 'unclassified' => 0];
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
            if ($measure === 'trips') {
                $mode = $modes[$line->source_type.':'.$line->source_id] ?? null;
                $bucket = in_array($mode, ['monge', 'subcontracted'], true) ? $mode : 'unclassified';
                $totals[$direction][$type][$bucket] += (int) $line->pallets;
            }
        }

        return ['totals' => $totals, 'sources' => $sources, 'modes' => $modes];
    }
}
