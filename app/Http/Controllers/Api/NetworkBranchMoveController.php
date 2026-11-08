<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Core\NetworkBranchMoveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class NetworkBranchMoveController extends Controller
{
    public function __construct(private NetworkBranchMoveService $moves) {}

    private function confirmationDisabled(): ?JsonResponse
    {
        if ($this->confirmationAvailable()) {
            return null;
        }

        return response()->json([
            'message' => 'La confirmación de movimientos permanece bloqueada hasta validar el backend con MySQL aislado.',
        ], 423);
    }

    private function confirmationAvailable(): bool
    {
        $productionCertified = config('network_moves.backend_certified') === true;
        $localHost = in_array(strtolower((string) config('database.connections.mysql.host')),
            ['127.0.0.1', 'localhost', '::1'], true);
        $localDatabase = config('database.connections.mysql.database') === 'imperiog';
        $localOptIn = app()->environment('local', 'testing')
            && config('network_moves.local_testing_enabled') === true && $localHost && $localDatabase;

        return config('network_moves.confirmation_enabled') === true && ($productionCertified || $localOptIn);
    }

    public function status(): JsonResponse
    {
        if (!Auth::user()?->is_admin) return response()->json(['message' => 'Solo administradores.'], 403);
        return response()->json([
            'confirmation_enabled' => $this->confirmationAvailable(),
            'mode' => config('network_moves.backend_certified') === true ? 'certified' :
                ($this->confirmationAvailable() ? 'local_test' : 'disabled'),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        if (!Auth::user()?->is_admin) return response()->json(['message' => 'Solo administradores.'], 403);
        $validator = Validator::make($request->all(), [
            'userCode' => ['required', 'string', 'max:100'],
            'newSponsorCode' => ['required', 'string', 'max:100'],
        ]);
        if ($validator->fails()) return response()->json(['message' => 'Datos inválidos.', 'errors' => $validator->errors()], 422);

        try {
            return response()->json($this->moves->preview(
                $request->string('userCode')->toString(),
                $request->string('newSponsorCode')->toString(),
                (int) Auth::id()
            ));
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'No fue posible simular el movimiento.'], 500);
        }
    }

    public function confirm(Request $request): JsonResponse
    {
        if (!Auth::user()?->is_admin) return response()->json(['message' => 'Solo administradores.'], 403);
        if ($response = $this->confirmationDisabled()) return $response;
        $validator = Validator::make($request->all(), [
            'userCode' => ['required', 'string', 'max:100'],
            'newSponsorCode' => ['required', 'string', 'max:100'],
        ]);
        if ($validator->fails()) return response()->json(['message' => 'Datos inválidos.', 'errors' => $validator->errors()], 422);

        try {
            return response()->json($this->moves->move(
                $request->string('userCode')->toString(),
                $request->string('newSponsorCode')->toString(),
                (int) Auth::id()
            ));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'El movimiento falló y la transacción fue revertida.'], 500);
        }
    }

    public function previewBatch(Request $request): JsonResponse
    {
        if (!Auth::user()?->is_admin) return response()->json(['message' => 'Solo administradores.'], 403);
        $validator = Validator::make($request->all(), [
            'moves' => ['required', 'array', 'min:1'],
            'moves.*' => ['required', 'string', 'max:100'],
        ]);
        if ($validator->fails()) return response()->json(['message' => 'Datos inválidos.', 'errors' => $validator->errors()], 422);
        try {
            return response()->json($this->moves->previewBatch($request->input('moves'), (int) Auth::id()));
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'No fue posible simular el lote.'], 500);
        }
    }

    public function confirmBatch(Request $request): JsonResponse
    {
        if (!Auth::user()?->is_admin) return response()->json(['message' => 'Solo administradores.'], 403);
        if ($response = $this->confirmationDisabled()) return $response;
        $validator = Validator::make($request->all(), ['previewToken' => ['required', 'string']]);
        if ($validator->fails()) return response()->json(['message' => 'Datos inválidos.', 'errors' => $validator->errors()], 422);
        try {
            return response()->json($this->moves->confirmBatch($request->string('previewToken')->toString(), (int) Auth::id()));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'El lote falló y la transacción fue revertida.'], 500);
        }
    }
}
