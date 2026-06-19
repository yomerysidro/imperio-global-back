<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use Exception;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Models\Product;
use App\Models\File;
use App\Models\PaymentProductOrder;
use App\Models\PaymentProductOrderDetail;
use App\Models\ProductPointPack;
use App\Services\Flow\FlowPayment;

class ProductController extends BaseController
{
    //

    private $flowPayment;

    public function __construct()
    {
        $this->flowPayment = new FlowPayment();
    }

    public function register( Request $request )
    {
        try {
            $validator = Validator::make( $request->all() , [
                'title'    => 'required',
                'price' => 'required|numeric',
                'points'    => 'required|numeric',
                'file' => 'nullable|file|mimes:png,jpg,jpeg|max:2048',
            ]);
            if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

            DB::beginTransaction();
            $user_id = Auth::id();

            $userModel = User::with(['file'])->find($user_id);

            if( !$userModel->is_admin ) return $this->sendError( "No tiene permisos ese usuario" );

            $fileId = 0;

            $dataBody = (object) $request->all();

            if($request->hasfile('file'))
            {
                $filePath = Storage::disk('public')->put('files/products', $request->file('file'));
                $fileModel = File::create(array(
                    'path' => $filePath,
                    'name' => $request->file('file')->getClientOriginalName(),
                    'extension' => $request->file('file')->getClientOriginalExtension(),
                    'size' => $request->file('file')->getSize()
                ));
                $fileId = $fileModel->id;
            }

            $productModel = Product::create(
                array(
                    'title'     => $dataBody->title,
                    'price'     => $dataBody->price,
                    'points'    => $dataBody->points,
                    'stock'     => $dataBody?->stock ?? 100,
                    'file'      => $fileId,
                    'user_id'   => $user_id
                )
            );

            DB::commit();

            return $this->sendResponse( $productModel->id, 'Creado');

        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError( $e->getMessage() );
        }
    }

    public function update( Request $request , string $productId)
    {
        try {
            $validator = Validator::make( $request->all() , [
                'title'    => 'required',
                'price' => 'required|numeric',
                'points'    => 'required|numeric',
                'file' => 'nullable|file|mimes:png,jpg,jpeg|max:2048',
            ]);

            if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

            DB::beginTransaction();
            $user_id = Auth::id();

            $userModel = User::with(['file'])->find($user_id);

            if( !$userModel->is_admin ) return $this->sendError( "No tiene permisos ese usuario" );

            $fileId = 0;

            $dataBody = (object) $request->all();

            if($request->hasfile('file'))
            {
                $filePath = Storage::disk('public')->put('files/products', $request->file('file'));

                $fileModel = File::create(array(
                    'path' => $filePath,
                    'name' => $request->file('file')->getClientOriginalName(),
                    'extension' => $request->file('file')->getClientOriginalExtension(),
                    'size' => $request->file('file')->getSize()
                ));
                $fileId = $fileModel->id;
            }

            Product::where("id" , $productId)->update(
                array(
                    'title'     => $dataBody->title,
                    'price'     => $dataBody->price,
                    'points'    => $dataBody->points,
                    'stock'     => $dataBody?->stock ?? 100,
                )
            );
            if( $fileId > 0 ){
                Product::where("id" , $productId)->update(
                    array( 'file'      => $fileId )
                );
            }

            DB::commit();

            return $this->sendResponse( $productId, 'Update');

        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError( $e->getMessage() );
        }
    }

    public function search()
    {
        try {
            $productList = Product::with(['file_image'])->orderBy('created_at', 'desc')->get();

            return $this->sendResponse( $productList , 'Lista');
        } catch (Exception $e) {
            return $this->sendError( $e->getMessage() );
        }
    }

    public function points( Request $request )
    {
        try {
            DB::beginTransaction();
            $user_id = Auth::id();
            $userModel = User::with(['file'])->find($user_id);
            if( !$userModel->is_admin ) return $this->sendError( "No tiene permisos ese usuario" );
            $dataBody = (object) $request->all();

            foreach ($dataBody->points as $key => $p) {
                $p = (object) $p;
                $productPointPack = ProductPointPack::where("product_id" , $p->product_id )->where("pack_id" , $p->pack_id)->first();
                if( $productPointPack == null ){
                    ProductPointPack::create(array(
                        'product_id'    => $p->product_id,
                        'pack_id'       => $p->pack_id,
                        'point'         => $p->point
                    ));
                }else{
                    ProductPointPack::where("product_id" , $p->product_id )->where("pack_id" , $p->pack_id)->update(array(
                        'point'  => $p->point
                    ));
                }
            }

            $productPointPackList = ProductPointPack::with(['pack:id,title','product:id,title'])->get();

            DB::commit();
            return $this->sendResponse( $productPointPackList , 'Lista');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError( $e->getMessage() );
        }
    }

    public function pointsSearch( Request $request )
    {
        try {
            $productList = ProductPointPack::with([])->orderBy('created_at', 'desc')->get();

            return $this->sendResponse( $productList , 'Lista');
        } catch (Exception $e) {
            return $this->sendError( $e->getMessage() );
        }
    }


}
