<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseController;
use App\Http\Resources\PaginationCollection;
use App\Models\CollectionRequestPatrocinioUser;
use App\Models\User;
use App\Services\Core\FileUpload;
use App\Services\Core\FinancialLedgerService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CollectionRequestPatrocinioUserController extends BaseController
{
    private FileUpload $fileUpload;

    public function __construct()
    {
        $this->fileUpload = new FileUpload();
    }

    public function findAll(Request $request)
    {
        $query = CollectionRequestPatrocinioUser::with(['user.file', 'fileModel']);
        if (!Auth::user()?->is_admin) $query->where('user_id', Auth::id());
        if ($request->filled('month') || $request->filled('year')) {
            $period = $this->period($request);
            if (!$period) return $this->sendError('Periodo invalido.', [], 422);
            $query->whereDate('period', $period->toDateString());
        }
        if ($request->filled('code')) $query->whereHas('user', fn ($q) => $q->where('uuid', $request->query('code')));
        if ($request->filled('name')) $query->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.$request->query('name').'%'));

        return $this->sendResponse(new PaginationCollection($query->latest()->paginate((int) $request->query('limit', $this->limit))), 'Solicitudes obtenidas.');
    }

    public function search(Request $request)
    {
        $query = CollectionRequestPatrocinioUser::with('user');
        if (!Auth::user()?->is_admin) $query->where('user_id', Auth::id());
        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('uuid', $search));
        }
        return $this->sendResponse($query->latest()->get(), 'Solicitudes obtenidas.');
    }

    public function balance(Request $request)
    {
        $period = $this->period($request);
        if (!$period) return $this->sendError('Periodo invalido.', [], 422);
        $user = Auth::user();
        if (Auth::user()?->is_admin && $request->filled('user_id')) $user = User::find($request->query('user_id'));
        if (!$user) return $this->sendError('Usuario no encontrado.', [], 404);

        $summary = app(FinancialLedgerService::class)->payoutSummary($period->copy()->startOfMonth(), $period->copy()->endOfMonth(), $user);
        return $this->sendResponse($summary + ['period' => $period->format('Y-m')], 'Saldo mensual obtenido.');
    }

    public function generate(Request $request)
    {
        $validator = Validator::make($request->all(), ['amount' => 'nullable|numeric|min:0.01', 'points' => 'nullable|numeric|min:0.01']);
        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);
        $amount = (float) $request->input('amount', $request->input('points', 0));
        if ($amount <= 0) return $this->sendError('El monto debe ser mayor a cero.', [], 422);

        return DB::transaction(function () use ($amount) {
            $user = User::whereKey(Auth::id())->lockForUpdate()->firstOrFail();
            $period = now()->startOfMonth();
            CollectionRequestPatrocinioUser::where('user_id', $user->id)->whereDate('period', $period)
                ->where('state', CollectionRequestPatrocinioUser::PENDING)->lockForUpdate()->get();
            $balance = app(FinancialLedgerService::class)->payoutSummary($period, $period->copy()->endOfMonth(), $user);
            if ($amount > $balance['available']) {
                return $this->sendError('El monto solicitado supera el saldo disponible.', ['available' => $balance['available']], 422);
            }
            $collection = CollectionRequestPatrocinioUser::create([
                'user_id' => $user->id, 'points' => (int) round($amount), 'amount' => $amount,
                'period' => $period->toDateString(), 'state' => CollectionRequestPatrocinioUser::PENDING,
                'requested_at' => now(),
            ]);
            return $this->sendResponse($collection, 'Solicitud registrada como pago pendiente.');
        });
    }

    public function approve(Request $request)
    {
        if (!Auth::user()?->is_admin) return $this->sendError('Solo un administrador puede procesar solicitudes.', [], 403);
        $validator = Validator::make($request->all(), [
            'requestId' => 'nullable|integer|required_without:userId',
            'userId' => 'nullable|integer|required_without:requestId',
            'approve' => 'required|integer|in:0,2,3', 'reason' => 'nullable|string|max:1000',
            'file' => 'nullable|file|max:5120',
        ]);
        if ((int) $request->input('approve') === CollectionRequestPatrocinioUser::REJECTED && !$request->filled('reason')) {
            return $this->sendError('Debe indicar el motivo del rechazo.', ['reason' => ['El motivo es obligatorio.']], 422);
        }
        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        return DB::transaction(function () use ($request) {
            $query = CollectionRequestPatrocinioUser::where('state', CollectionRequestPatrocinioUser::PENDING);
            $request->filled('requestId') ? $query->whereKey($request->requestId) : $query->where('user_id', $request->userId)->oldest();
            $collection = $query->lockForUpdate()->first();
            if (!$collection) return $this->sendError('La solicitud ya fue procesada o no existe.', [], 409);

            $paid = (int) $request->approve === CollectionRequestPatrocinioUser::PAID;
            $fileId = $request->hasFile('file') ? $this->fileUpload->upload($request->file('file'), 'request') : null;
            $collection->update([
                'state' => $paid ? CollectionRequestPatrocinioUser::PAID : CollectionRequestPatrocinioUser::REJECTED,
                'file' => $fileId, 'confirm' => now(),
                'approved_by' => Auth::id(), 'approved_at' => now(), 'paid_at' => $paid ? now() : null,
                'rejection_reason' => $paid ? null : $request->reason,
            ]);
            return $this->sendResponse($collection->fresh(['user', 'fileModel']), $paid ? 'Pago aprobado y cobrado.' : 'Solicitud rechazada.');
        });
    }

    public function download(Request $request)
    {
        return $this->sendResponse($request->filled('file') ? $this->fileUpload->downloadFileAsBase64($request->query('file')) : [], 'download');
    }

    private function period(Request $request): ?Carbon
    {
        $month = (int) $request->query('month', now()->month);
        $year = (int) $request->query('year', now()->year);
        return $month >= 1 && $month <= 12 && $year >= 2000 ? Carbon::create($year, $month, 1)->startOfMonth() : null;
    }
}
