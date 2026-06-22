<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('thirteenth_month_runs') && !Schema::hasTable('thirteenth_month_configurations')) {
            Schema::rename('thirteenth_month_runs','thirteenth_month_configurations');
            Schema::table('thirteenth_month_configurations', function(Blueprint $t){
                $t->renameColumn('coverage_start','coverage_start_date');
                $t->renameColumn('coverage_end','coverage_end_date');
                $t->renameColumn('total_basic_basis','total_basis_amount');
                $t->renameColumn('total_13th_month','total_payable_amount');
                $t->string('company_scope_type',16)->default('specific');
                $t->json('company_ids')->nullable();
            });
            foreach(DB::table('thirteenth_month_configurations')->get(['id','company_id','basis_type']) as $row){
                DB::table('thirteenth_month_configurations')->where('id',$row->id)->update([
                    'company_ids'=>json_encode([(int)$row->company_id]),
                    'basis_type'=>$row->basis_type==='gross_pay'?'gross':'basic',
                ]);
            }
        }

        if (Schema::hasTable('thirteenth_month_employees') && !Schema::hasTable('thirteenth_month_employee_results')) {
            Schema::rename('thirteenth_month_employees','thirteenth_month_employee_results');
            Schema::table('thirteenth_month_employee_results', function(Blueprint $t){
                $t->renameColumn('run_id','configuration_id');
                $t->renameColumn('basis_amount_used','basis_amount');
                $t->foreignId('company_id')->nullable()->after('employee_id')->constrained('companies')->nullOnDelete();
                $t->string('status',16)->default('draft')->after('eligibility_status');
            });
            DB::table('thirteenth_month_employee_results as r')
                ->join('thirteenth_month_configurations as c','c.id','=','r.configuration_id')
                ->update(['r.company_id'=>DB::raw('c.company_id'),'r.status'=>DB::raw('c.status')]);
        }

        if (!Schema::hasTable('thirteenth_month_payslip_inclusions')) {
            Schema::create('thirteenth_month_payslip_inclusions', function(Blueprint $t){
                $t->id();
                $t->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
                $t->foreignId('configuration_id')->constrained('thirteenth_month_configurations')->cascadeOnDelete();
                $t->foreignId('payroll_run_id')->constrained('payroll_batch_runs')->cascadeOnDelete();
                $t->foreignId('payslip_id')->nullable()->constrained('payslips')->nullOnDelete();
                $t->string('component_code',32)->default('13TH_MONTH_PAY');
                $t->decimal('amount',16,2);
                $t->timestamps();
                $t->unique(['employee_id','configuration_id','payroll_run_id','component_code'],'tm_payslip_inclusion_unique');
            });
        }
    }

    public function down(): void {}
};
