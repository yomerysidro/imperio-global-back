<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseController;
use App\Models\ActivationRule;
use App\Models\CommissionRule;
use App\Models\Range;
use App\Models\RangeRequirement;
use App\Models\RangeRule;
use App\Models\ReactivationRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MlmRuleController extends BaseController
{
    public function index()
    {
        return $this->sendResponse([
            'activation_rules' => ActivationRule::orderBy('id')->get(),
            'reactivation_rules' => ReactivationRule::orderBy('id')->get(),
            'ranges' => Range::with(['rule.requirements.requiredRange'])->orderBy('order')->get(),
            'commission_rules' => CommissionRule::with('minimumRange')->orderBy('bonus_type')->orderBy('level')->get(),
        ], 'Configuracion MLM');
    }

    public function update(Request $request)
    {
        if (!Auth::user()?->is_admin) return $this->sendError('No tiene permisos ese usuario');
        $validator = Validator::make($request->all(), [
            'activation_rules' => 'sometimes|array|min:1',
            'activation_rules.*.name' => 'required_with:activation_rules|string',
            'activation_rules.*.minimum_points' => 'required_with:activation_rules|numeric|min:0',
            'activation_rules.*.minimum_amount' => 'required_with:activation_rules|numeric|min:0',
            'activation_rules.*.minimum_products' => 'required_with:activation_rules|integer|min:0',
            'reactivation_rules' => 'sometimes|array|min:1',
            'reactivation_rules.*.category' => 'required_with:reactivation_rules|in:product,service',
            'reactivation_rules.*.name' => 'required_with:reactivation_rules|string',
            'reactivation_rules.*.minimum_points' => 'required_with:reactivation_rules|numeric|min:0',
            'reactivation_rules.*.minimum_amount' => 'required_with:reactivation_rules|numeric|min:0',
            'reactivation_rules.*.minimum_products' => 'required_with:reactivation_rules|integer|min:0',
            'range_rules' => 'sometimes|array',
            'range_rules.*.range_id' => 'required_with:range_rules|exists:ranges,id',
            'range_rules.*.required_points' => 'required_with:range_rules|numeric|min:0',
            'range_rules.*.required_active_lines' => 'required_with:range_rules|integer|min:0',
            'range_rules.*.depth_from' => 'required_with:range_rules|integer|min:1',
            'range_rules.*.depth_to' => 'required_with:range_rules|integer|min:1',
            'range_rules.*.infinity_percentage' => 'required_with:range_rules|numeric|between:0,100',
            'range_rules.*.requirements' => 'sometimes|array',
            'range_rules.*.requirements.*.required_range_id' => 'required|exists:ranges,id',
            'range_rules.*.requirements.*.required_count' => 'required|integer|min:1',
            'range_rules.*.requirements.*.minimum_distinct_lines' => 'required|integer|min:1',
            'commission_rules' => 'sometimes|array',
            'commission_rules.*.bonus_type' => 'required_with:commission_rules|in:sponsorship,residual',
            'commission_rules.*.category' => 'nullable|in:product,service',
            'commission_rules.*.level' => 'required_with:commission_rules|integer|min:1',
            'commission_rules.*.percentage' => 'required_with:commission_rules|numeric|between:0,100',
        ]);
        if ($validator->fails()) return $this->sendError($validator->errors());
        foreach ($request->input('range_rules', []) as $rule) {
            if ((int) $rule['depth_to'] < (int) $rule['depth_from']) {
                return $this->sendError('depth_to debe ser mayor o igual que depth_from');
            }
        }

        DB::transaction(function () use ($request) {
            foreach ($request->input('activation_rules', []) as $item) {
                isset($item['id']) ? ActivationRule::whereKey($item['id'])->update($item) : ActivationRule::create($item);
            }
            foreach ($request->input('reactivation_rules', []) as $item) {
                ReactivationRule::updateOrCreate(['category' => $item['category']], $item);
            }
            foreach ($request->input('range_rules', []) as $item) {
                $requirements = $item['requirements'] ?? null;
                unset($item['requirements']);
                $rule = RangeRule::updateOrCreate(['range_id' => $item['range_id']], $item);
                Range::whereKey($rule->range_id)->update(['points' => $rule->required_points, 'childs' => $rule->required_active_lines]);
                if ($requirements !== null) {
                    RangeRequirement::where('range_id', $rule->range_id)->delete();
                    foreach ($requirements as $requirement) {
                        RangeRequirement::create($requirement + ['range_id' => $rule->range_id]);
                    }
                }
            }
            foreach ($request->input('commission_rules', []) as $item) {
                if ($item['bonus_type'] === CommissionRule::RESIDUAL && empty($item['category'])) {
                    $item['category'] = ReactivationRule::PRODUCT;
                }
                CommissionRule::updateOrCreate([
                    'bonus_type' => $item['bonus_type'], 'category' => $item['category'] ?? null,
                    'pack_id' => $item['pack_id'] ?? null, 'level' => $item['level'],
                ], $item);
            }
        });
        return $this->index();
    }
}
