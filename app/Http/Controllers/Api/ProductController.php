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
        if (!$user?->is_admin) $query->where('state', true);

        $products = $query->get()->map(fn (Product $product) => $this->catalogProduct($product, $user));
        return $this->sendResponse($products, 'Productos obtenidos correctamente.');
    }

    public function show(string $productId)
    {
        $user = Auth::guard('api')->user();
        $product = Product::with('file_image')->find($productId);
        if (!$product || (!$product->state && !$user?->is_admin)) {
            return $this->sendError('Producto no encontrado.', [], 404);
        }

        return $this->sendResponse($this->catalogProduct($product, $user), 'Producto obtenido correctamente.');
    }

    public function store(Request $request)
    {
        if ($response = $this->denyUnlessAdmin()) return $response;
        $validator = Validator::make($request->all(), $this->rules());
        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        $product = DB::transaction(fn () => Product::create([
            'title' => trim($request->title),
            'price' => $request->price,
            'points' => $request->points,
            'stock' => $request->stock,
            'file' => $this->storeImage($request),
            'user_id' => Auth::id(),
            'state' => $request->boolean('state', false),
        ]));

        return $this->sendResponse($product->load('file_image'), 'Producto creado correctamente.');
    }

    public function update(Request $request, string $productId)
    {
        if ($response = $this->denyUnlessAdmin()) return $response;
        $product = Product::find($productId);
        if (!$product) return $this->sendError('Producto no encontrado.', [], 404);

        $validator = Validator::make($request->all(), $this->rules(true));
        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        $oldFileId = $product->file;
        $newFileId = null;
        DB::transaction(function () use ($request, $product, &$newFileId) {
            $data = $request->only(['title', 'price', 'points', 'stock']);
            if ($request->has('state')) $data['state'] = $request->boolean('state');
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
            'file' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ];
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
