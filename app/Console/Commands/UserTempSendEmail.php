<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Range;
use App\Models\ScheduleCron;
use App\Models\PaymentOrderPoint;
use App\Models\RangeUser;
use App\Models\PaymentProductOrderPoint;
use App\Services\Core\Calculator;
use App\Models\PaymentLog;
use App\Models\UserEmailTemp;
use Exception;

use Carbon\Carbon;

use App\Mail\UsersPointExcel;
use App\Mail\UserPointActive;

use Illuminate\Support\Facades\Mail;

class UserTempSendEmail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:user-temp-send-email';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        //
        $tempCount = UserEmailTemp::where("status" , UserEmailTemp::PENDIENTE)->count();
        if( $tempCount > 0 ){
            $scheduleCron = ScheduleCron::create(array(
                'signature' => "app:user-temp-send-email",
            ));
            try {
                DB::beginTransaction();
    
                $temps = UserEmailTemp::where("status" , UserEmailTemp::PENDIENTE)->get();
                $countSend = 0;
                foreach ($temps as $key => $temp) {
    
                    if( $countSend > 5 ) break;
                    $user = User::where("id", $temp->userId)->first();
                    if( $temp->isAdmin ){
                        $fileAttachment = storage_path("app/{$temp->fileAttachment}");
                        $mailData = [
                            'customer_name' => $user->name,
                            "subject" => $temp->subject,
                            'attach'    => $fileAttachment
                        ];
                        Mail::to($temp->email)->send(new UsersPointExcel($mailData));
                        UserEmailTemp::where("id" , $temp->id)->update(array(
                            "status" => UserEmailTemp::ENVIADO
                        ));
                    }else{
    
                        $body = unserialize($temp->jsonBody);
    
                        $mailData = [
                            'customer_name' => $user->name,
                            "subject" => $temp->subject,
                            "month" => Carbon::createFromDate(null, $temp->month, null)->locale('es')->monthName,
                            "patrocinio" => $body['points']->patrocinio,
                            "compra" => $body['points']->compra,
                            "total" => $body['totalPoint'],
                            "residual" => $body['points']->residual,
                            "personal" => $body['points']->personal,
                            "afiliado" => $body['points']->pointAfiliado,
                            "infinito" => $body['points']->infinito,
                            "range" => $body['range'],
                            "plan" => $body['pack'],
                            "status" => $body['status'],
                        ];

                        // desabilitado - 15-06
    
                        // Mail::to($temp->email)->send(new UserPointActive($mailData));
    
                        // UserEmailTemp::where("id" , $temp->id)->update(array(
                        //     "status" => UserEmailTemp::ENVIADO
                        // ));
                    }
                    $countSend ++;
                }
    
                DB::commit();
    
                ScheduleCron::where("id", $scheduleCron->id)->update(array(
                    "response" => json_encode( array() ),
                    "status" => 2
                ));
    
            }catch (Exception $e){
                DB::rollBack();
                ScheduleCron::where("id", $scheduleCron->id)->update(array(
                    "status" => 3,
                    "response" => $e->getMessage()
                ));
            }
        }
        
    }
}
