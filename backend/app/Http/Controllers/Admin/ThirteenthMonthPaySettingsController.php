<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ThirteenthMonthSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThirteenthMonthPaySettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $settings=ThirteenthMonthSetting::with('updatedByUser:id,name')->latest('updated_at')->first();
        return response()->json(['settings'=>$settings]);
    }

    public function update(Request $request): JsonResponse
    {
        $v=$request->validate([
            'company_scope_type'=>['required','in:all,specific'],
            'company_ids'=>['nullable','array'],'company_ids.*'=>['integer','exists:companies,id'],
            'basis_type'=>['required','in:basic,gross'],
            'coverage_type'=>['required','in:dec_nov,calendar_year,first_half,second_half,custom'],
            'coverage_start_month'=>['required','integer','between:1,12'],
            'coverage_start_year'=>['required','integer','between:2000,2100'],
            'coverage_end_month'=>['required','integer','between:1,12'],
            'coverage_end_year'=>['required','integer','between:2000,2100'],
            'is_active'=>['nullable','boolean'],
        ]);
        if($v['company_scope_type']==='specific' && empty($v['company_ids'])) abort(422,'Select at least one company.');
        $start=\Carbon\Carbon::create($v['coverage_start_year'],$v['coverage_start_month'],1);
        $end=\Carbon\Carbon::create($v['coverage_end_year'],$v['coverage_end_month'],1)->endOfMonth();
        abort_if($end->lt($start),422,'Coverage end month must not be before start month.');

        $setting=ThirteenthMonthSetting::query()->latest('id')->first() ?? new ThirteenthMonthSetting;
        $setting->fill($v);
        $setting->company_ids=$v['company_scope_type']==='specific'?array_values(array_map('intval',$v['company_ids'])):null;
        $setting->is_active=(bool)($v['is_active']??true);
        $setting->updated_by=$request->user()?->id;
        $setting->save();

        return response()->json(['message'=>'13th Month Pay settings saved.','settings'=>$setting->fresh('updatedByUser:id,name')]);
    }
}
