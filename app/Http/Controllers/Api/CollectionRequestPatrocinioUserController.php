<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Exception;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Http\Resources\PaginationCollection;
use Illuminate\Support\Facades\Auth;
use App\Models\CollectionRequestPatrocinioUser;
use App\Services\Core\FileUpload;

class CollectionRequestPatrocinioUserController extends BaseController
{
    //

    private $fileUpload;
    private $fileUploadPath;

    public function __construct() {
        $this->fileUpload = new FileUpload();
        $this->fileUploadPath = 'request';
    }

    public function findAll(Request $request)
    {
        try {
            $user_id = Auth::id();
            $limit = $this->limit;

            $collection = CollectionRequestPatrocinioUser::with(['user.file', 'fileModel']);
            
            if( $request->has('code') ) if( !empty($request->query('code')) ){
                $_code = $request->query('code');
                $collection = $collection->whereHas('user' , function($query) use ($_code) {
                    $query->where("uuid", $_code);
                });
            }

            if( $request->has('name') ) if( !empty($request->query('name')) ){
                $_name = $request->query('name');
                $collection = $collection->whereHas('user' , function($query) use ($_name) {
                    $query->where('name', "like", "%".$_name."%" );
                });
            }

            $collection = $collection->orderBy('created_at', 'desc')->paginate($limit);

            return $this->sendResponse( new PaginationCollection($collection) , 'list');
        } catch (Exception $e) {

            return $this->sendError( $e->getMessage() );
        }
    }

    public function search(Request $request)
    {
        try {
            $user_id = Auth::id();
            $limit = $this->limit;

            $collection = CollectionRequestPatrocinioUser::with(['user']);
            
            if( $request->has('search') ) if( !empty($request->query('search')) ){
                $_name = $request->query('search');
                $collection = $collection->whereHas('user' , function($query) use ($_name) {
                    $query->where('name', "like", "%".$_name."%" )->orWhere("uuid", $_name);
                });
            }

            $collection = $collection->orderBy('created_at', 'desc')->get();

            return $this->sendResponse( $collection , 'list');
        } catch (Exception $e) {

            return $this->sendError( $e->getMessage() );
        }
    }

    public function generate(Request $request)
    {
        try {
            $user_id = Auth::id();

            $dataBody = (object) $request->all();

            if( $dataBody->points == 0 ){
                return $this->sendResponse( array() , 'Los puntos cobrados deben ser mayor a 0' , false);
            }
            
            $collection = CollectionRequestPatrocinioUser::where("user_id", $user_id)->where("state", 1)->first();
            if( $collection != null ){
                return $this->sendResponse( array() , 'Tienes una solicitud pendiente por aprobar' , false);
            }

            CollectionRequestPatrocinioUser::create(array(
                "user_id" => $user_id,
                "points" => $dataBody->points,
                "state" => 1
            ));

            return $this->sendResponse( 1 , 'generate');
        } catch (Exception $e) {

            return $this->sendError( $e->getMessage() );
        }
    }


    public function approve(Request $request)
    {

        try {
            $user_id = Auth::id();

            $dataBody = (object) $request->all();
            DB::beginTransaction();
            $userModel = User::with(['file'])->find($dataBody->userId);

            $collection = CollectionRequestPatrocinioUser::where("user_id", $userModel->id )->where("state", 1)->first();
            if( $collection == null ){
                return $this->sendResponse( array() , 'Necesitas tener una solicitud en estado PENDIENTE' , false);
            }

            $fileId = 0;

            if($request->hasfile('file')) $fileId = $this->fileUpload->upload( $request->file('file') , $this->fileUploadPath);

            CollectionRequestPatrocinioUser::where("user_id", $userModel->id)->where("state", 1)->update(
                array( "state" => $dataBody->approve,"file" => $fileId, "confirm" => date('Y-m-d H:i:s')),
            );
            DB::commit();
            return $this->sendResponse( 1 , 'aprobar');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError( $e->getMessage() );
        }
    }

    public function download(Request $request)
    {
        try {
            $user_id = Auth::id();

            $download = array();

            if( $request->has('file') ) if( !empty( $request->query('file') ) ){
                $download = $this->fileUpload->downloadFileAsBase64( $request->query('file') );
            }

            return $this->sendResponse( $download , 'download');
        } catch (Exception $e) {

            return $this->sendError( $e->getMessage() );
        }
    }
}
