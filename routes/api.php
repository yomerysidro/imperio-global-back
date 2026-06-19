<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\LoginController;
use App\Http\Controllers\Api\PackController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\PaymentOrderController;
use App\Http\Controllers\Api\OptionController;
use App\Http\Controllers\Api\PaymentOrderPointController;
use App\Http\Controllers\Api\IzipayController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PaymentProductOrderController;
use App\Http\Controllers\Api\RangeController;
use App\Http\Controllers\Api\CollectionRequestPatrocinioUserController;
use App\Http\Controllers\Api\Services\ServiceOrderController;

Route::prefix('v1')->group(function () {

    // --- GRUPO PÚBLICO ---
    Route::post('login', [LoginController::class, 'login']);
    Route::post('reset-password', [LoginController::class, 'resetPassword']);
    Route::post('auth/register', [LoginController::class, 'register']);
    Route::post('auth/recover-password', [LoginController::class, 'recover']);
    Route::post('auth/validate-code/{uuid}', [LoginController::class, 'validate']);
    Route::get('auth/export', [UserController::class, 'export']);

    Route::post('payment/flow/confirm/{uuid}', [PaymentOrderController::class, 'flowConfirm']);
    Route::post('payment/izipay/ipn', [IzipayController::class, 'notificationIpn']);
    Route::get('payment/reset/{code}', [PaymentOrderController::class, 'cancelAllPaymentByUser']);
    Route::get('payment/reset', [PaymentOrderController::class, 'cancelAllPayment']);
    Route::get('payment/delete/{code}', [PaymentOrderController::class, 'deleteAllPaymentByUser']);

    Route::get('option/search', [OptionController::class, 'search']);
    Route::get('pack/search', [PackController::class, 'search']);
    Route::post('users/invited-verify', [UserController::class, 'invitedVerify']);

    // --- GRUPO PROTEGIDO ---
    Route::middleware('auth:api')->group(function () {

        // --- BLOQUE USUARIOS ---
        Route::prefix('users')->group(function () {

            
            Route::get('find-all', [UserController::class, 'findAll']);
            Route::get('search', [UserController::class, 'search']);
            Route::get('cash-flow', [UserController::class, 'cashFlowFilter']);
            Route::get('payments/find-all', [UserController::class, 'paymentsAll']);
            Route::get('invited-user', [UserController::class, 'invitedUserCode']);
            Route::get('list-tree', [UserController::class, 'treeList']);

            // Rutas de acción (POST/PUT)
            Route::post('modify', [UserController::class, 'modifyUser']);
            Route::post('change-sponsor', [UserController::class, 'changeSponsor']);
            Route::post('reset', [UserController::class, 'resetPoint']);
            Route::post('reset-all', [UserController::class, 'resetAll']);
            Route::post('reset-all-points', [UserController::class, 'resetAllPoint']);
            Route::post('desactive', [UserController::class, 'desactive']);
            Route::post('active-residual', [UserController::class, 'activeResidual']);
            Route::post('reset-send-email', [UserController::class, 'resetUserToTemp']);
            Route::post('pdf-finance', [UserController::class, 'exportPdfFinance']);
            Route::post('excel-finance', [UserController::class, 'exportExcelFinance']);
            Route::post('pdf-profile', [UserController::class, 'exportPdfProfile']);
            Route::post('create-user', [UserController::class, 'createUser']);




            // LA RUTA QUE ME PEDISTE (DE PRIMERA)
            Route::get('{id}', [UserController::class, 'show'])->whereNumber('id');            // Rutas de búsqueda y listado

            // Invitaciones
            Route::post('generate-invited', [UserController::class, 'invitedLink']);
            Route::post('invited-email', [UserController::class, 'invitedLinkEmail']);
            Route::post('invited-confirm', [UserController::class, 'invitedConfirm']);
            Route::post('invited-removed', [UserController::class, 'invitedUserCodeRemove']);

            // Solicitudes
            Route::prefix('request-patrocinio')->group(function () {
                Route::get('find-all', [CollectionRequestPatrocinioUserController::class, 'findAll']);
                Route::post('generate', [CollectionRequestPatrocinioUserController::class, 'generate']);
                Route::get('search', [CollectionRequestPatrocinioUserController::class, 'search']);
                Route::post('approve', [CollectionRequestPatrocinioUserController::class, 'approve']);
                Route::get('download', [CollectionRequestPatrocinioUserController::class, 'download']);
            });
        });

        // --- BLOQUE OTROS ---
        Route::get('auth', [UserController::class, 'auth']);
        Route::get('auth/search', [UserController::class, 'search']);
        Route::put('auth/update', [UserController::class, 'authUpdate']);
        Route::post('auth/update/avatar', [UserController::class, 'authUpdateAvatar']);

        Route::post('service/buy', [ServiceOrderController::class, 'buyService']);

        Route::prefix('payment')->group(function () {
            Route::post('flow', [PaymentOrderController::class, 'flowCreate']);
            Route::post('flow/create-offline', [PaymentOrderController::class, 'flowCreateOffline']);
            Route::post('flow/confirm-offline/{uuid}', [PaymentOrderController::class, 'flowCreateConfirmOffline']);
            Route::post('izipay/create', [PaymentOrderController::class, 'createPaymentIzipay']);
            Route::post('izipay/confirm', [PaymentOrderController::class, 'confirmPaymentIzipay']);
            Route::post('cash-pre', [PaymentOrderController::class, 'paymentCash']);
            Route::post('cash-confirm', [PaymentOrderController::class, 'paymentCashConfirm']);
            Route::post('offline', [PaymentOrderController::class, 'paymentOffline']);
        });

        Route::prefix('product')->group(function () {
            Route::post('/', [ProductController::class, 'register']);
            Route::get('search', [ProductController::class, 'search']);
            Route::post('update/{productId}', [ProductController::class, 'update']);
            Route::post('points', [ProductController::class, 'points']);
            Route::get('points/search', [ProductController::class, 'pointsSearch']);
            Route::post('payment/offline', [PaymentProductOrderController::class, 'paymentOffline']);
            Route::post('payment/offline-confirm', [PaymentProductOrderController::class, 'paymentOfflineConfirm']);
            Route::get('payment/find-all', [PaymentProductOrderController::class, 'findAll']);
            Route::get('payment/search', [PaymentProductOrderController::class, 'search']);
            Route::get('payment/points', [PaymentProductOrderController::class, 'points']);
            Route::post('payment/flow', [PaymentProductOrderController::class, 'flowCreate']);
            Route::post('payment/izipay-create', [PaymentProductOrderController::class, 'izipayCreate']);
            Route::post('payment/izipay-confirm', [PaymentProductOrderController::class, 'izipayConfirmPayment']);
            Route::post('payment/change-state', [PaymentProductOrderController::class, 'changeState']);
            Route::get('payment-detail/find-all', [PaymentProductOrderController::class, 'findAllDetails']);
            Route::post('cash-pre', [PaymentProductOrderController::class, 'paymentCash']);
            Route::post('cash-confirm', [PaymentProductOrderController::class, 'paymentCashConfirm']);
        });

        Route::get('points/list', [PaymentOrderPointController::class, 'points']);
        Route::get('points/users', [PaymentOrderPointController::class, 'pointsUser']);

        Route::prefix('pack')->group(function () {
            Route::post('/', [PackController::class, 'register']);
            Route::post('update/{packId}', [PackController::class, 'update']);
            Route::post('residual', [PackController::class, 'residual']);
            Route::post('patrocinio', [PackController::class, 'patrocinio']);
            Route::post('state/{packId}', [PackController::class, 'changeStatus']);
        });

        Route::prefix('option')->group(function () {
            Route::get('search', [OptionController::class, 'search']);
            Route::post('truncate', [OptionController::class, 'truncate']);
            Route::post('create', [OptionController::class, 'create']);
            Route::post('reboot', [OptionController::class, 'reboot']);
        });

        Route::prefix('range')->group(function () {
            Route::post('/', [RangeController::class, 'register']);
            Route::get('search', [RangeController::class, 'list']);
            Route::post('update/{id}', [RangeController::class, 'update']);
            Route::post('users', [RangeController::class, 'users']);
            Route::post('user/{userCode}', [RangeController::class, 'usersByCode']);
        });
    });
});
