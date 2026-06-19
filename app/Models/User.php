<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'uuid',
        'is_admin',
        'address',
        'phone',
        'photo',
        'city',
        'country',
        'gender'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected $appends = ['active', 'package_name'];

    public function getActiveAttribute()
    {
        // Criterio Unificado: ¿Tiene algún pago de servicio o producto en estado 2 (PAGADO) o 6 (TERMINADO) en el mes actual?
        return PaymentLog::where('user_id', $this->id)
            ->whereIn('state', [2, 6])
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->exists();
    }

    public function getPackageNameAttribute()
    {
        $packages = [];
        
        // 1. Membresía de Servicios Digitales (Estado 2 o 6)
        $services = PaymentLog::with('paymentOrder.pack')
            ->where('user_id', $this->id)
            ->whereIn('state', [2, 6])
            ->get();
        foreach ($services as $log) {
            if ($log->paymentOrder && $log->paymentOrder->pack) {
                $packages[] = $log->paymentOrder->pack->title;
            }
        }

        // 2. Packs de Productos (Estado 2, 3 o 6)
        $products = PaymentProductOrder::with('pack')
            ->where('user_id', $this->id)
            ->whereIn('state', [2, 3, 6])
            ->get();
        foreach ($products as $order) {
            if ($order->pack) {
                $packages[] = $order->pack->title;
            }
        }

        // Eliminar duplicados y unir nombres
        $uniquePackages = array_unique($packages);
        return count($uniquePackages) > 0 ? implode(' + ', $uniquePackages) : 'Sin paquetes registrados';
    }

    public static function boot()
    {
        parent::boot();

        // REGLA SENIOR: Limpieza de puntos en cascada al eliminar usuario
        static::deleting(function ($user) {
            // Desactivamos físicamente y ponemos en cero los puntos generados por este usuario
            // para que la calculadora los ignore de inmediato.
            PaymentOrderPoint::where('user_id', $user->id)
                ->orWhere('user_code', $user->uuid)
                ->update([
                    'state' => false,
                    'point' => 0 // Aseguramos que la suma sea 0 físicamente
                ]);
        });
    }

    public function file()
    {
        return $this->hasOne(File::class, 'id', 'photo');
    }

    // App/Models/User.php

    public function paymentActive()
    {
        return $this->hasOne(PaymentLog::class, 'user_id', 'id')
            ->whereIn('state', [2, 6]); // 2: PAGADO, 6: TERMINADO
    }

    public function range()
    {
        return $this->hasOne(RangeUser::class, 'user_id', 'id')->where("status", true);
    }
}
