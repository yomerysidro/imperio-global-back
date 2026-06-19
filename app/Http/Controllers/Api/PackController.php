<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Exception;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Pack;
use App\Models\File;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

use App\Models\ResidualPoint;
use App\Models\SponsorshipPoint;
class PackController extends BaseController
{
    //
    public function search()
    {
        try {
            $packList = Pack::with(['file'])->where("state", true)->orderBy('price', 'desc')->get();

            return $this->sendResponse( $packList , 'Lista');
        } catch (Exception $e) {
            return $this->sendError( $e->getMessage() );
        }
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

            $dataBody = (object) $request->all();

            $user_id = Auth::id();

            $fileId = 0;

            if($request->hasfile('file'))
            {
                $filePath = Storage::disk('public')->put('files/ranges', $request->file('file'));
                $fileModel = File::create(array(
                    'path' => $filePath,
                    'name' => $request->file('file')->getClientOriginalName(),
                    'extension' => $request->file('file')->getClientOriginalExtension(),
                    'size' => $request->file('file')->getSize()
                ));
                $fileId = $fileModel->id;
            }

            $packModel = Pack::create(
                array(
                    'title'     => $dataBody->title,
                    'price'     => $dataBody->price,
                    'points'    => $dataBody->points,
                    'image'     => $fileId,
                    'discount'  => $dataBody?->discount ?? 0
                )
            );

            DB::commit();

            return $this->sendResponse( $packModel->id, 'Creado');


        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError( $e->getMessage() );
        }
    }

    public function update( Request $request , string $packId)
    {
        try {

            $validator = Validator::make( $request->all() , [
                'title'    => 'required',
                'price' => 'required|numeric',
                'points'    => 'required|numeric',
                'discount'    => 'required|numeric',
            ]);

            if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

            DB::beginTransaction();

            $dataBody = (object) $request->all();

            $user_id = Auth::id();

            $fileId = 0;

            if($request->hasfile('file'))
            {
                $filePath = Storage::disk('public')->put('files/packs', $request->file('file'));
                $fileModel = File::create(array(
                    'path' => $filePath,
                    'name' => $request->file('file')->getClientOriginalName(),
                    'extension' => $request->file('file')->getClientOriginalExtension(),
                    'size' => $request->file('file')->getSize()
                ));
                $fileId = $fileModel->id;
            }

            Pack::where("id" , $packId)->update(
                array(
                    'title'     => $dataBody->title,
                    'price'     => $dataBody->price,
                    'points'    => $dataBody->points,
                    'discount'  => $dataBody->discount
                )
            );
            if( $fileId > 0 ){
                Pack::where("id" , $packId)->update(
                    array(
                        'image'     => $fileId,
                    )
                );
            }
            DB::commit();

            return $this->sendResponse( $packId, 'Update');


        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError( $e->getMessage() );
        }
    }

    public function residual(Request $request)
    {
        try {
            DB::beginTransaction();
            $user_id = Auth::id();
            $userModel = User::with(['file'])->find($user_id);
            if( !$userModel->is_admin ) return $this->sendError( "No tiene permisos ese usuario" );
            $dataBody = (object) $request->all();

            $ResidualPoint = ResidualPoint::where("id" , 1)->first();

            if( $ResidualPoint == null ){
                ResidualPoint::create(array(
                    'level1'    => $dataBody->level1,
                    'level2'    => $dataBody->level2,
                    'level3'    => $dataBody->level3,
                    'level4'    => $dataBody->level4,
                    'level5'    => $dataBody->level5,
                    'level6'    => $dataBody->level6,
                    'level7'    => $dataBody->level7,
                ));
            }else{
                ResidualPoint::where("id" , 1)->update(array(
                    'level1'    => $dataBody->level1,
                    'level2'    => $dataBody->level2,
                    'level3'    => $dataBody->level3,
                    'level4'    => $dataBody->level4,
                    'level5'    => $dataBody->level5,
                    'level6'    => $dataBody->level6,
                    'level7'    => $dataBody->level7,
                ));
            }

            $residualPointList = ResidualPoint::all();

            DB::commit();
            return $this->sendResponse( $residualPointList , 'Lista');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError( $e->getMessage() );
        }
    }

    public function patrocinio(Request $request)
    {
        try {
            DB::beginTransaction();
            $user_id = Auth::id();
            $userModel = User::with(['file'])->find($user_id);
            if( !$userModel->is_admin ) return $this->sendError( "No tiene permisos ese usuario" );
            $dataBody = (object) $request->all();

            $SponsorshipPoint = SponsorshipPoint::where("pack_id" , $dataBody->pack_id)->first();

            if( $SponsorshipPoint == null ){
                SponsorshipPoint::create(array(
                    'pack_id'    => $dataBody->pack_id,
                    'level1'    => $dataBody->level1,
                    'level2'    => $dataBody->level2,
                    'level3'    => $dataBody->level3,
                    'level4'    => $dataBody->level4,
                    'level5'    => $dataBody->level5
                ));
            }else{
                SponsorshipPoint::where("pack_id" , $dataBody->pack_id)->update(array(
                    'level1'    => $dataBody->level1,
                    'level2'    => $dataBody->level2,
                    'level3'    => $dataBody->level3,
                    'level4'    => $dataBody->level4,
                    'level5'    => $dataBody->level5,
                ));
            }

            $SponsorshipPointList = SponsorshipPoint::with(['pack:id,title'])->get();

            DB::commit();
            return $this->sendResponse( $SponsorshipPointList , 'Lista');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError( $e->getMessage() );
        }
    }

    public function changeStatus( Request $request , string $packId)
    {
        try {

            $validator = Validator::make( $request->all() , [
                'status'    => 'required',
            ]);

            if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

            DB::beginTransaction();

            $dataBody = (object) $request->all();

            Pack::where("id" , $packId)->update(
                array(
                    'state'     => $dataBody->status == 1 ? true : false,
                )
            );

            DB::commit();

            return $this->sendResponse( $packId, 'change status');


        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError( $e->getMessage() );
        }
    }
}
