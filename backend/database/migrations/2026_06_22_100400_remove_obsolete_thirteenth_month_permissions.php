<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if(!Schema::hasTable('permissions'))return;
        $obsolete=['thirteenth_month.finalize','thirteenth_month.reports'];
        $ids=DB::table('permissions')->whereIn('slug',$obsolete)->pluck('id');
        if(Schema::hasTable('role_permissions')&&$ids->isNotEmpty())DB::table('role_permissions')->whereIn('permission_id',$ids)->delete();
        DB::table('permissions')->whereIn('slug',$obsolete)->delete();
        DB::table('permissions')->where('slug','thirteenth_month.view')->update(['label'=>'View 13th Month Pay Settings','description'=>'View 13th Month Pay configuration','updated_at'=>now()]);
        DB::table('permissions')->where('slug','thirteenth_month.manage')->update(['label'=>'Manage 13th Month Pay Settings','description'=>'Update 13th Month Pay configuration','updated_at'=>now()]);
    }
    public function down(): void {}
};
