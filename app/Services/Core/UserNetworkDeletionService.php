<?php

namespace App\Services\Core;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class UserNetworkDeletionService
{
    public function __construct(private ?NetworkTreeService $networkTreeService = null)
    {
        $this->networkTreeService ??= new NetworkTreeService();
    }

    public function delete(User $rootUser, bool $withNetwork = true): array
    {
        $networkCodes = $withNetwork
            ? $this->networkTreeService->getAllNetworkUsers($rootUser->uuid)
            : [$rootUser->uuid];
        $networkCodes[] = $rootUser->uuid;

        $users = User::whereIn('uuid', array_values(array_unique($networkCodes)))
            ->get(['id', 'uuid', 'email', 'is_admin']);

        if ($users->contains(fn (User $user) => $user->is_admin
            || in_array(strtoupper($user->uuid), ['DOSB', 'WADZ'], true))) {
            throw new RuntimeException('No se puede eliminar una red que contenga usuarios administrativos o corporativos.');
        }

        $userIds = $users->pluck('id')->all();
        $userCodes = $users->pluck('uuid')->all();
        $userEmails = $users->pluck('email')->filter()->all();

        $directChildCodes = [];
        $replacementSponsorCode = null;
        if (!$withNetwork) {
            $directChildCodes = array_values(array_filter(
                $this->networkTreeService->directUserCodes($rootUser->uuid),
                fn ($code) => User::where('uuid', $code)->exists()
            ));
            $replacementSponsorCode = $this->networkTreeService->sponsorCode($rootUser->uuid);

            if ($directChildCodes && (!$replacementSponsorCode
                || !User::where('uuid', $replacementSponsorCode)->exists())) {
                throw new RuntimeException(
                    'No se puede eliminar solo este usuario porque no tiene un patrocinador válido al cual reasignar su red.'
                );
            }
        }

        DB::transaction(function () use (
            $rootUser,
            $withNetwork,
            $directChildCodes,
            $replacementSponsorCode,
            $userIds,
            $userCodes,
            $userEmails
        ): void {
            if (!$withNetwork && $directChildCodes) {
                foreach ($directChildCodes as $childCode) {
                    DB::table('sponsor_relations')->updateOrInsert(
                        ['user_code' => $childCode],
                        [
                            'sponsor_code' => $replacementSponsorCode,
                            'source' => 'parent_reassignment',
                            'state' => true,
                            'updated_at' => now(),
                        ]
                    );
                }

                $childOrderIds = DB::table('payment_order_points')
                    ->whereIn('user_code', $directChildCodes)
                    ->where('type', 'B')
                    ->where('sponsor_code', $rootUser->uuid)
                    ->pluck('payment_order_id')->all();

                DB::table('payment_order_points')
                    ->whereIn('user_code', $directChildCodes)
                    ->where('type', 'B')
                    ->where('sponsor_code', $rootUser->uuid)
                    ->update(['sponsor_code' => $replacementSponsorCode]);
                DB::table('payment_orders')->whereIn('id', $childOrderIds)
                    ->update(['sponsor_code' => $replacementSponsorCode]);
                DB::table('guests_token_users')
                    ->whereIn('guest_user_code', $directChildCodes)
                    ->where('sponsor_user_code', $rootUser->uuid)
                    ->update(['sponsor_user_code' => $replacementSponsorCode]);
            }

            $paymentOrderIds = DB::table('payment_logs')->whereIn('user_id', $userIds)
                ->pluck('payment_order_id')->all();
            if (Schema::hasColumn('payment_orders', 'user_id')) {
                $paymentOrderIds = array_merge(
                    $paymentOrderIds,
                    DB::table('payment_orders')->whereIn('user_id', $userIds)->pluck('id')->all()
                );
            }
            $paymentOrderIds = array_values(array_unique($paymentOrderIds));

            $productOrderIds = DB::table('payment_product_orders')->whereIn('user_id', $userIds)
                ->pluck('id')->all();

            $inviteIds = DB::table('invite_users')
                ->whereIn('sponsor_user_id', $userIds)
                ->orWhereIn('sponsor_user_code', $userCodes)
                ->pluck('id')->all();

            DB::table('guests_token_users')->whereIn('invite_user_id', $inviteIds)
                ->orWhereIn('sponsor_user_code', $userCodes)
                ->orWhereIn('guest_user_code', $userCodes)
                ->delete();
            DB::table('invite_users')->whereIn('id', $inviteIds)->delete();

            DB::table('sponsor_relations')->whereIn('user_code', $userCodes)
                ->orWhereIn('sponsor_code', $userCodes)->delete();

            if (Schema::hasTable('manual_reactivations')) {
                DB::table('manual_reactivations')->whereIn('user_id', $userIds)
                    ->orWhereIn('activated_by', $userIds)->delete();
                DB::table('manual_reactivations')->whereIn('deactivated_by', $userIds)
                    ->update(['deactivated_by' => null]);
            }

            DB::table('payment_order_points')
                ->whereIn('payment_order_id', $paymentOrderIds)
                ->orWhereIn('user_id', $userIds)
                ->orWhereIn('user_code', $userCodes)
                ->orWhereIn('sponsor_code', $userCodes)
                ->orWhereIn('source_user_code', $userCodes)
                ->delete();

            DB::table('payment_product_order_points')
                ->whereIn('payment_product_order_id', $productOrderIds)
                ->orWhereIn('user_id', $userIds)->delete();
            DB::table('payment_product_order_details')
                ->whereIn('payment_product_order_id', $productOrderIds)->delete();
            DB::table('payment_product_orders')->whereIn('id', $productOrderIds)->delete();

            DB::table('payment_order_range_histories')
                ->whereIn('payment_order_id', $paymentOrderIds)
                ->orWhereIn('user_id', $userIds)->delete();
            DB::table('payment_logs')->whereIn('user_id', $userIds)
                ->orWhereIn('payment_order_id', $paymentOrderIds)->delete();
            DB::table('payment_orders')->whereIn('id', $paymentOrderIds)->delete();

            $this->deleteByUserIds('range_users', 'user_id', $userIds);
            $this->deleteByUserIds('verification_code_users', 'user_id', $userIds);
            $this->deleteByUserIds('collection_request_patrocinio_users', 'user_id', $userIds);
            $this->deleteByUserIds('sessions', 'user_id', $userIds);
            $this->deleteByUserIds('oauth_auth_codes', 'user_id', $userIds);
            if (Schema::hasTable('oauth_access_tokens')) {
                $accessTokenIds = DB::table('oauth_access_tokens')->whereIn('user_id', $userIds)
                    ->pluck('id')->all();
                if (Schema::hasTable('oauth_refresh_tokens')) {
                    DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $accessTokenIds)->delete();
                }
                DB::table('oauth_access_tokens')->whereIn('id', $accessTokenIds)->delete();
            }
            $this->deleteByUserIds('oauth_clients', 'user_id', $userIds);
            $this->deleteByUserIds('user_email_temps', 'userId', $userIds);
            if (Schema::hasTable('password_reset_tokens')) {
                DB::table('password_reset_tokens')->whereIn('email', $userEmails)->delete();
            }

            DB::table('users')->whereIn('id', $userIds)->delete();
        });

        Cache::forget('existing_user_uuids');
        ActivationService::clearCache();

        return [
            'deleted_count' => count($userIds),
            'deleted_user_codes' => array_values($userCodes),
            'reassigned_children_count' => count($directChildCodes),
            'replacement_sponsor_code' => $replacementSponsorCode,
        ];
    }

    private function deleteByUserIds(string $table, string $column, array $userIds): void
    {
        if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
            DB::table($table)->whereIn($column, $userIds)->delete();
        }
    }
}
