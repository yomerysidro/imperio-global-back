<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseController;
use App\Models\File;
use App\Models\PaymentLog;
use App\Models\PaymentProductOrderDetail;
use App\Models\Product;
use App\Models\ProductPointPack;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProductController extends BaseController
{
    public function index(Request $request)
    {
        $user = Auth::guard('api')->user();
        $query = Product::with('file_image')->orderByDesc('created_at');
        $this->applyCatalogVisibility($query, $user);

        $products = $query->get()->map(fn (Product $product) => $this->catalogProduct($product, $user));
        return $this->sendResponse($products, 'Productos obtenidos correctamente.');
    }

    public function show(string $productId)
    {
        $user = Auth::guard('api')->user();
        $product = Product::with('file_image')->find($productId);
        if (!$product || !$this->isVisibleTo($product, $user)) {
            return $this->sendError('Producto no encontrado.', [], 404);
        }

        return $this->sendResponse($this->catalogProduct($product, $user), 'Producto obtenido correctamente.');
    }

    public function store(Request $request)
    {
        if ($response = $this->denyUnlessAdmin()) return $response;
        $validator = $this->productValidator($request);
        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        $product = DB::transaction(fn () => Product::create([
            'title' => trim($request->title),
            'price' => $request->price,
            'points' => $request->points,
            'stock' => $request->stock,
            'file' => $this->storeImage($request),
            'user_id' => Auth::id(),
            'state' => $request->boolean('state', false),
            'is_promotion' => $request->boolean('is_promotion', false),
            'promotion_start_at' => $request->boolean('is_promotion') ? $request->promotion_start_at : null,
            'promotion_end_at' => $request->boolean('is_promotion') ? $request->promotion_end_at : null,
        ]));

        return $this->sendResponse($product->load('file_image'), 'Producto creado correctamente.');
    }

    public function update(Request $request, string $productId)
    {
        if ($response = $this->denyUnlessAdmin()) return $response;
        $product = Product::find($productId);
        if (!$product) return $this->sendError('Producto no encontrado.', [], 404);

        $validator = $this->productValidator($request, $product);
        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        $oldFileId = $product->file;
        $newFileId = null;
        DB::transaction(function () use ($request, $product, &$newFileId) {
            $data = $request->only(['title', 'price', 'points', 'stock']);
            if ($request->has('state')) $data['state'] = $request->boolean('state');
            if ($request->has('is_promotion')) {
                $data['is_promotion'] = $request->boolean('is_promotion');
                if (!$data['is_promotion']) {
                    $data['promotion_start_at'] = null;
                    $data['promotion_end_at'] = null;
                } else {
                    $data['promotion_start_at'] = $request->promotion_start_at;
                    $data['promotion_end_at'] = $request->promotion_end_at;
                }
            } elseif ($product->is_promotion) {
                if ($request->has('promotion_start_at')) $data['promotion_start_at'] = $request->promotion_start_at;
                if ($request->has('promotion_end_at')) $data['promotion_end_at'] = $request->promotion_end_at;
            }
            if ($request->hasFile('file')) {
                $newFileId = $this->storeImage($request);
                $data['file'] = $newFileId;
            }
            $product->update($data);
        });
        if ($newFileId && $oldFileId) $this->deleteImage($oldFileId);

        return $this->sendResponse($product->fresh('file_image'), 'Producto actualizado correctamente.');
    }

    public function destroy(string $productId)
    {
        if ($response = $this->denyUnlessAdmin()) return $response;
        $product = Product::find($productId);
        if (!$product) return $this->sendError('Producto no encontrado.', [], 404);

        if (PaymentProductOrderDetail::where('product_id', $product->id)->exists()) {
            return $this->sendError(
                'No se puede eliminar porque el producto tiene ventas registradas. Puede despublicarlo.',
                [],
                409
            );
        }

        $fileId = $product->file;
        DB::transaction(function () use ($product) {
            ProductPointPack::where('product_id', $product->id)->delete();
            $product->delete();
        });
        if ($fileId) $this->deleteImage($fileId);

        return $this->sendResponse(true, 'Producto eliminado definitivamente.');
    }

    public function changeStatus(Request $request, string $productId)
    {
        if ($response = $this->denyUnlessAdmin()) return $response;
        $validator = Validator::make($request->all(), ['state' => 'required|boolean']);
        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        $product = Product::find($productId);
        if (!$product) return $this->sendError('Producto no encontrado.', [], 404);
        $product->update(['state' => $request->boolean('state')]);
        return $this->sendResponse($product->fresh('file_image'), $product->state ? 'Producto publicado.' : 'Producto despublicado.');
    }

    public function register(Request $request) { return $this->store($request); }
    public function search(Request $request) { return $this->index($request); }

    private function rules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';
        return [
            'title' => [$presence, 'string', 'max:255'],
            'price' => [$presence, 'numeric', 'min:0'],
            'points' => [$presence, 'integer', 'min:0'],
            'stock' => [$presence, 'integer', 'min:0'],
            'state' => ['sometimes', 'boolean'],
            'is_promotion' => ['sometimes', 'boolean'],
            'promotion_start_at' => ['sometimes', 'nullable', 'date'],
            'promotion_end_at' => ['sometimes', 'nullable', 'date'],
            'file' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ];
    }

    private function productValidator(Request $request, ?Product $product = null)
    {
        $validator = Validator::make($request->all(), $this->rules($product !== null));
        $validator->after(function ($validator) use ($request, $product) {
            $isPromotion = $request->has('is_promotion')
                ? $request->boolean('is_promotion')
                : (bool) ($product?->is_promotion ?? false);
            if (!$isPromotion) return;

            $start = $request->has('promotion_start_at')
                ? $request->input('promotion_start_at')
                : $product?->promotion_start_at;
            $end = $request->has('promotion_end_at')
                ? $request->input('promotion_end_at')
                : $product?->promotion_end_at;

            if (blank($start)) $validator->errors()->add('promotion_start_at', 'La fecha de inicio es obligatoria para una promocion.');
            if (blank($end)) $validator->errors()->add('promotion_end_at', 'La fecha de fin es obligatoria para una promocion.');
            if (!blank($start) && !blank($end) && strtotime((string) $end) < strtotime((string) $start)) {
                $validator->errors()->add('promotion_end_at', 'La fecha de fin debe ser posterior o igual a la fecha de inicio.');
            }
        });
        return $validator;
    }

    private function applyCatalogVisibility($query, ?User $user): void
    {
        if ($user?->is_admin) return;

        $query->where('state', true);
        if (!$user) {
            $query->where('is_promotion', false);
            return;
        }

        $query->where(function ($visibility) {
            $visibility->where('is_promotion', false)
                ->orWhere(function ($promotion) {
                    $promotion->where('is_promotion', true)
                        ->where('promotion_start_at', '<=', now())
                        ->where('promotion_end_at', '>=', now());
                });
        });
    }

    private function isVisibleTo(Product $product, ?User $user): bool
    {
        if ($user?->is_admin) return true;
        if (!$product->state) return false;
        if (!$product->is_promotion) return true;
        if (!$user || !$product->promotion_start_at || !$product->promotion_end_at) return false;

        return now()->between($product->promotion_start_at, $product->promotion_end_at, true);
    }

    private function catalogProduct(Product $product, ?User $user): Product
    {
        $discount = $this->discountFor($user);
        $publicPrice = round((float) $product->price, 2);
        $product->setAttribute('public_price', $publicPrice);
        $product->setAttribute('discount_percentage', $discount);
        $product->setAttribute('final_price', round($publicPrice * (100 - $discount) / 100, 2));
        $product->setAttribute('points', (int) $product->points);
        return $product;
    }

    private function discountFor(?User $user): float
    {
        if (!$user || $user->is_admin) return 0;
        $payment = PaymentLog::query()
            ->join('payment_orders', 'payment_orders.id', '=', 'payment_logs.payment_order_id')
            ->join('packs', 'packs.id', '=', 'payment_orders.pack_id')
            ->where('payment_logs.user_id', $user->id)
            ->where('payment_logs.confirm', true)
            ->whereIn('payment_logs.state', [PaymentLog::PAGADO, PaymentLog::TERMINADO])
            ->latest('payment_logs.created_at')
            ->first(['packs.discount']);
        return max(0, min(100, (float) ($payment?->discount ?? 0)));
    }

    private function denyUnlessAdmin()
    {
        return Auth::user()?->is_admin ? null : $this->sendError('Solo un administrador puede gestionar productos.', [], 403);
    }

    private function storeImage(Request $request): ?int
    {
        if (!$request->hasFile('file')) return null;
        $uploaded = $request->file('file');
        $path = Storage::disk('public')->put('files/products', $uploaded);
        return File::create([
            'path' => $path,
            'name' => $uploaded->getClientOriginalName(),
            'extension' => $uploaded->getClientOriginalExtension(),
            'size' => $uploaded->getSize(),
        ])->id;
    }

    private function deleteImage(int $fileId): void
    {
        $file = File::find($fileId);
        if (!$file) return;
        Storage::disk('public')->delete($file->path);
        $file->delete();
    }
}
