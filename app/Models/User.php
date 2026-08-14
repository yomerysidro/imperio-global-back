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

    /**
     * Determina si el usuario está activo
     * Los usuarios especiales (DOSB, WAdz) siempre están activos
     */
    public function getActiveAttribute()
    {
        // USUARIOS ESPECIALES: SIEMPRE ACTIVOS
        return app(\App\Services\Core\ActivationService::class)->isActive($this);
        /* Legacy activation logic retained temporarily below; unreachable by design.
        $specialUsers = ['DOSB', 'WAdz'];
        
        if (in_array($this->uuid, $specialUsers)) {
            return true;
        }
        
        // Criterio Unificado: ¿Tiene algún pago de servicio o producto en estado 2 (PAGADO) o 6 (TERMINADO) en el mes actual?
        $hasService = PaymentLog::where('user_id', $this->id)
            ->whereIn('state', [2, 6])
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->exists();

        $hasProduct = PaymentProductOrder::where('user_id', $this->id)
            ->whereIn('state', [2, 3, 6])
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->exists();

        return $hasService || $hasProduct; */
    }

    public function getPackageNameAttribute()
    {
        if (strtoupper($this->uuid) === 'DOSB') {
            return 'Corporativo';
        }

        $packagesByCategory = [];
        $rememberLatestPack = function ($pack, $createdAt) use (&$packagesByCategory): void {
            $category = mb_strtoupper(trim((string) ($pack->category ?: 'SIN_CATEGORIA')));
            $timestamp = $createdAt?->getTimestamp() ?? 0;
            if (!isset($packagesByCategory[$category]) || $timestamp >= $packagesByCategory[$category]['timestamp']) {
                $packagesByCategory[$category] = [
                    'title' => $pack->title,
                    'timestamp' => $timestamp,
                ];
            }
        };
        
        // 1. Membresía de Servicios Digitales (Estado 2 o 6)
        $services = PaymentLog::with('paymentOrder.pack')
            ->where('user_id', $this->id)
            ->whereIn('state', [PaymentLog::PAGADO, PaymentLog::TERMINADO])
            ->orderBy('created_at')
            ->get();
        foreach ($services as $log) {
            if ($log->paymentOrder && $log->paymentOrder->pack) {
                $pack = $log->paymentOrder->pack;
                $rememberLatestPack($pack, $log->created_at);
            }
        }

        // 2. Packs de Productos (Estado 2, 3 o 6)
        $products = PaymentProductOrder::with('pack')
            ->where('user_id', $this->id)
            ->whereIn('state', [
                PaymentProductOrder::PAGADO,
                PaymentProductOrder::ENVIADO,
                PaymentProductOrder::TERMINADO,
            ])
            ->orderBy('created_at')
            ->get();
        foreach ($products as $order) {
            if ($order->pack) {
                $rememberLatestPack($order->pack, $order->created_at);
            }
        }

        // Dentro de una categoria prevalece el paquete mas reciente. Los
        // paquetes de categorias diferentes si se presentan en conjunto.
        $uniquePackages = array_values(array_unique(array_column($packagesByCategory, 'title')));
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
