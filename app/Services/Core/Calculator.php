<?php

namespace App\Services\Core;

use App\Models\PaymentOrderPoint;
use App\Models\User;
use App\Models\PaymentOrder;
use App\Models\SponsorshipPoint;
use App\Models\Pack;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class Calculator
{
    // Tipos donde point = monto en SOLES (ya calculado)
    private const TIPOS_PATROCINIO = ['P', 'PS'];
    private const TIPOS_RESIDUAL = ['R', 'RS'];
    private const TIPOS_COMPRA_PERSONAL = ['B'];
    private const TIPOS_VOLUMEN_GRUPAL = ['G'];

    /**
     * Calcula todas las ganancias y volúmenes de un usuario
     * AHORA SOPORTA DATOS HISTÓRICOS (LEGACY) Y TRANSACCIONALES
     */
    public function points(string $userCode, $paymentOrderPoints, $paymentProductOrderPoints, $month = null, $year = null): object
    {
        $userModel = User::where('uuid', $userCode)->first();
        if (!$userModel) {
            return $this->emptyObject();
        }

        // Si no se especifica mes/año, usar el actual
        $now = Carbon::now();
        $targetMonth = $month ?? $now->month;
        $targetYear = $year ?? $now->year;
        $userCodeUpper = strtoupper($userCode);

        // ACUMULADORES
        $patrocinioProducto = 0.0;
        $patrocinioServicio = 0.0;
        $residualProducto = 0.0;
        $residualServicio = 0.0;
        $pointGroupProducto = 0.0;
        $pointGroupServicio = 0.0;
        $puntosProducto = 0.0;
        $puntosServicio = 0.0;

        // Control de duplicados
        $processedGanancia = [];
        $processedGrupal = [];
        $processedPersonal = [];

        // =========================================================
        // 1. PROCESAR PRODUCTOS FÍSICOS
        // =========================================================
        foreach ($paymentProductOrderPoints as $productOrder) {
            $fecha = Carbon::parse($productOrder->created_at);
            if ($fecha->month == $targetMonth && $fecha->year == $targetYear && (int) $productOrder->state === 1) {
                $puntosProducto += (float) ($productOrder->points ?? 0);
            }
        }

        // =========================================================
        // 2. PROCESAR ÓRDENES DE LA RED (SOPORTA LEGACY)
        // =========================================================
        foreach ($paymentOrderPoints as $orderPoint) {
            // Validar estado
            if (!(bool) $orderPoint->state) continue;

            // 🔥 VERIFICAR SI ES UN REGISTRO LEGACY (histórico)
            $isLegacy = isset($orderPoint->is_legacy) && $orderPoint->is_legacy === true;

            // Para registros legacy, NO aplicar filtro de mes/año
            if (!$isLegacy) {
                $fecha = Carbon::parse($orderPoint->created_at);
                $esMesActual = ($fecha->month == $targetMonth && $fecha->year == $targetYear);
                if (!$esMesActual) continue;
            }

            // Obtener valores
            $valorFila = (float) ($orderPoint->point ?? 0);
            $userCodeOrden = strtoupper($orderPoint->user_code ?? '');
            $sponsorCode = strtoupper($orderPoint->sponsor_code ?? '');
            $orderId = $orderPoint->payment_order_id;
            $tipoFila = $orderPoint->type ?? '';
            $packId = $orderPoint->pack_id ?? null;

            // 🔥 Para registros legacy, generar un ID único para control de duplicados
            if (empty($orderId) && $isLegacy) {
                $orderId = 'LEGACY_' . ($orderPoint->id ?? uniqid()) . '_' . $userCodeOrden;
            }

            // Saltar si no hay ID de orden
            if (empty($orderId)) continue;

            // =========================================================
            // A. PUNTOS PERSONALES (el usuario actual es el comprador)
            // =========================================================
            if ($userCodeUpper === $userCodeOrden && in_array($tipoFila, self::TIPOS_COMPRA_PERSONAL)) {
                if (!isset($processedPersonal[$orderId])) {
                    $categoria = $this->resolvePackCategory($packId, $orderId);
                    if ($categoria === 'PRODUCTO') {
                        $puntosProducto += $valorFila;
                    } else {
                        $puntosServicio += $valorFila;
                    }
                    $processedPersonal[$orderId] = true;
                }
            }

            // =========================================================
            // B. BONOS DE PATROCINIO
            // =========================================================
            if ($userCodeUpper === $userCodeOrden && in_array($tipoFila, self::TIPOS_PATROCINIO)) {
                $key = "ganancia_{$orderId}_{$tipoFila}";
                if (!isset($processedGanancia[$key])) {
                    $categoria = $this->resolvePackCategory($packId, $orderId);
                    if ($tipoFila === 'P') {
                        $patrocinioProducto += $valorFila;
                    } elseif ($tipoFila === 'PS') {
                        $patrocinioServicio += $valorFila;
                    }
                    $processedGanancia[$key] = true;
                }
            }

            // =========================================================
            // C. BONOS RESIDUALES
            // =========================================================
            if ($userCodeUpper === $userCodeOrden && in_array($tipoFila, self::TIPOS_RESIDUAL)) {
                $key = "residual_{$orderId}_{$tipoFila}";
                if (!isset($processedGanancia[$key])) {
                    $categoria = $this->resolvePackCategory($packId, $orderId);
                    if ($tipoFila === 'R') {
                        $residualProducto += $valorFila;
                    } elseif ($tipoFila === 'RS') {
                        $residualServicio += $valorFila;
                    }
                    $processedGanancia[$key] = true;
                }
            }

            // =========================================================
            // D. VOLUMEN GRUPAL
            // =========================================================
            if ($userCodeUpper === $userCodeOrden && in_array($tipoFila, self::TIPOS_VOLUMEN_GRUPAL)) {
                $key = "grupal_{$orderId}";
                if (!isset($processedGrupal[$key])) {
                    $categoria = $this->resolvePackCategory($packId, $orderId);
                    if ($categoria === 'PRODUCTO') {
                        $pointGroupProducto += $valorFila;
                    } else {
                        $pointGroupServicio += $valorFila;
                    }
                    $processedGrupal[$key] = true;
                }
            }
        }

        // =========================================================
        // 3. 🔥 BONIFICAR PUNTOS POR INVITADOS HISTÓRICOS (DOSB)
        // =========================================================
        // Si el usuario es DOSB (raíz corporativa), sumar puntos por cada invitado histórico
        if (strtoupper($userCode) === 'DOSB') {
            $legacyCount = \App\Models\GuestsTokenUser::where('sponsor_user_code', $userCode)
                ->where('state', true)
                ->count();
            
            // Cada invitado histórico aporta puntos base
            $puntosPorInvitado = 100;
            $puntosProducto += ($legacyCount * $puntosPorInvitado);
        }

        // Totales
        $personalTotal = $puntosProducto + $puntosServicio;
        $patrocinioTotal = $patrocinioProducto + $patrocinioServicio;
        $residualTotal = $residualProducto + $residualServicio;
        $pointGroupTotal = $pointGroupProducto + $pointGroupServicio;

        return (object) [
            'patrocinio' => round($patrocinioTotal, 2),
            'residual' => round($residualTotal, 2),
            'personal' => round($personalTotal, 2),
            'pointGroup' => round($pointGroupTotal, 2),
            'compra' => (object) ['total_puntos' => round($personalTotal, 2)],
            'patrocinioProducto' => round($patrocinioProducto, 2),
            'patrocinioServicio' => round($patrocinioServicio, 2),
            'patrocinioTotal' => round($patrocinioTotal, 2),
            'residualProducto' => round($residualProducto, 2),
            'residualServicio' => round($residualServicio, 2),
            'pointGroupProducto' => round($pointGroupProducto, 2),
            'pointGroupServicio' => round($pointGroupServicio, 2),
            'puntosPersonalesProducto' => round($puntosProducto, 2),
            'puntosPersonalesServicio' => round($puntosServicio, 2),
            'infinito' => 0.0,
            'pointAfiliado' => 0.0,
            'personalGlobal' => 0.0,
            'patrocinioRequest' => 0.0,
            'legacy_bonus' => round($puntosProducto + $puntosServicio, 2), // 🔥 Bonus por legacy
        ];
    }

    /**
     * Calcula el total de puntos de un usuario
     */
    public function pointsTotal(string $userCode, $paymentOrderPoints, $paymentProductOrderPoints, $month = null, $year = null): float
    {
        $result = $this->points($userCode, $paymentOrderPoints, $paymentProductOrderPoints, $month, $year);
        return (float) $result->pointGroup + (float) $result->personal;
    }

    /**
     * ============================================================
     * resolvePackCategory() - CORREGIDO PARA SOPORTAR LEGACY
     * ============================================================
     */
    private function resolvePackCategory(?string $packId, ?string $orderId): string
    {
        // 🔥 Si es un ID legacy, retornar categoría por defecto
        if (strpos($orderId ?? '', 'LEGACY_') === 0) {
            return 'SERVICIO';
        }

        if ($packId) {
            $pack = Pack::find($packId);
            if ($pack && $pack->category) {
                return strtoupper($pack->category);
            }
        }

        if ($orderId) {
            $order = PaymentOrder::with('pack')->find($orderId);
            if ($order && $order->pack && $order->pack->category) {
                return strtoupper($order->pack->category);
            }
        }

        return 'SERVICIO';
    }

    /**
     * Retorna un objeto vacío para cuando no hay usuario
     */
    private function emptyObject(): object
    {
        return (object) [
            'patrocinio' => 0.0,
            'residual' => 0.0,
            'compra' => (object) ['total_puntos' => 0.0],
            'personal' => 0.0,
            'pointGroup' => 0.0,
            'patrocinioProducto' => 0.0,
            'patrocinioServicio' => 0.0,
            'patrocinioTotal' => 0.0,
            'residualProducto' => 0.0,
            'residualServicio' => 0.0,
            'pointGroupProducto' => 0.0,
            'pointGroupServicio' => 0.0,
            'puntosPersonalesProducto' => 0.0,
            'puntosPersonalesServicio' => 0.0,
            'infinito' => 0.0,
            'pointAfiliado' => 0.0,
            'personalGlobal' => 0.0,
            'patrocinioRequest' => 0.0,
            'legacy_bonus' => 0.0,
        ];
    }
}