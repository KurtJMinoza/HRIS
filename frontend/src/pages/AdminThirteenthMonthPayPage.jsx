import { useEffect, useMemo, useState } from 'react'
import { companyLogoUrl, getCompanies, getThirteenthMonthSettings, saveThirteenthMonthSettings } from '@/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { useToast } from '@/components/ui/use-toast'
import { Building2, Calculator, CalendarRange, Loader2, Save } from 'lucide-react'

const months=['January','February','March','April','May','June','July','August','September','October','November','December']
const currentYear=new Date().getFullYear()
const selectTriggerClass='h-11 rounded-xl border-border/90 bg-background px-3.5 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition-all hover:border-[#f4511e]/50 hover:bg-muted/30 focus-visible:border-[#f4511e] focus-visible:ring-[#f4511e]/15'
const selectContentClass='rounded-xl border-border/80 bg-popover p-1.5 shadow-[0_14px_38px_rgba(15,23,42,0.16)]'
const selectItemClass='min-h-10 rounded-lg px-3 py-2.5 pr-9 font-medium focus:bg-[#fff1eb] focus:text-[#e84817] dark:focus:bg-[#3a1d14] dark:focus:text-[#ff8a61]'

export default function AdminThirteenthMonthPayPage(){
 const {toast}=useToast()
 const [companies,setCompanies]=useState([]),[scope,setScope]=useState('all'),[companyIds,setCompanyIds]=useState([])
 const [basis,setBasis]=useState('basic'),[coverage,setCoverage]=useState('dec_nov'),[referenceYear,setReferenceYear]=useState(String(currentYear))
 const [startMonth,setStartMonth]=useState('1'),[startYear,setStartYear]=useState(String(currentYear)),[endMonth,setEndMonth]=useState('12'),[endYear,setEndYear]=useState(String(currentYear))
 const [active,setActive]=useState(true),[settings,setSettings]=useState(null),[loading,setLoading]=useState(true),[saving,setSaving]=useState(false)

 useEffect(()=>{(async()=>{try{const [companyData,settingData]=await Promise.all([getCompanies({fresh:true}),getThirteenthMonthSettings()]);setCompanies(companyData.companies||[]);const s=settingData.settings;if(s){setSettings(s);setScope(s.company_scope_type||'all');setCompanyIds((s.company_ids||[]).map(String));setBasis(s.basis_type||'basic');setCoverage(s.coverage_type||'dec_nov');setStartMonth(String(s.coverage_start_month));setStartYear(String(s.coverage_start_year));setEndMonth(String(s.coverage_end_month));setEndYear(String(s.coverage_end_year));setReferenceYear(String(s.coverage_end_year));setActive(Boolean(s.is_active))}}catch(e){toast({title:'13th Month Pay',description:e.message,variant:'destructive'})}finally{setLoading(false)}})()},[])

 const resolved=useMemo(()=>{
  const y=Number(referenceYear)||currentYear
  if(coverage==='dec_nov')return{sm:12,sy:y-1,em:11,ey:y}
  if(coverage==='calendar_year')return{sm:1,sy:y,em:12,ey:y}
  if(coverage==='first_half')return{sm:1,sy:y,em:6,ey:y}
  if(coverage==='second_half')return{sm:7,sy:y,em:12,ey:y}
  return{sm:Number(startMonth),sy:Number(startYear),em:Number(endMonth),ey:Number(endYear)}
 },[coverage,referenceYear,startMonth,startYear,endMonth,endYear])

 const save=async()=>{setSaving(true);try{const data=await saveThirteenthMonthSettings({company_scope_type:scope,company_ids:scope==='specific'?companyIds.map(Number):null,basis_type:basis,coverage_type:coverage,coverage_start_month:resolved.sm,coverage_start_year:resolved.sy,coverage_end_month:resolved.em,coverage_end_year:resolved.ey,is_active:active});setSettings(data.settings);toast({title:'Settings saved',description:'These settings will be used when Generate Payslips includes 13th Month Pay.'})}catch(e){toast({title:'Could not save settings',description:e.message,variant:'destructive'})}finally{setSaving(false)}}

 if(loading)return <div className="flex min-h-72 items-center justify-center"><Loader2 className="h-6 w-6 animate-spin text-brand"/></div>
 return (
  <div className="min-h-full bg-background px-4 py-6 sm:px-6 lg:px-8">
   <div className="mx-auto max-w-[1480px]">
    <header className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
     <div>
      <h1 className="text-[26px] font-bold tracking-[-0.025em] text-foreground">13th Month Pay</h1>
      <p className="mt-1 text-sm text-muted-foreground">Configure how 13th Month Pay is calculated when it is included during payslip generation.</p>
     </div>
     <Button onClick={save} disabled={saving} className="h-11 self-end rounded-lg bg-[#f4511e] px-6 text-white shadow-sm hover:bg-[#df4518] sm:self-auto">
      {saving?<Loader2 className="size-4 animate-spin"/>:<Save className="size-4"/>}Save Settings
     </Button>
    </header>

    <div className="mt-5 border-t border-border pt-4">
     <Card className="rounded-xl border-border bg-card shadow-[0_1px_3px_rgba(15,23,42,0.04)]">
      <CardHeader className="px-6 pb-0 pt-6 sm:px-7">
       <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
         <CardTitle className="text-base font-bold">Configuration</CardTitle>
         <CardDescription className="mt-1 text-sm">Amounts are calculated only when a payroll draft is generated with Include 13th Month Pay.</CardDescription>
        </div>
        <div className="flex items-start gap-4">
         <div className="text-right">
          <Label htmlFor="setting-active" className="text-sm font-bold">Module Status</Label>
          <p className="mt-1 text-xs text-muted-foreground">{active?'Active':'Inactive'}</p>
         </div>
         <Switch id="setting-active" checked={active} onCheckedChange={setActive} className="data-[state=checked]:bg-[#f4511e]"/>
        </div>
       </div>
      </CardHeader>

      <CardContent className="space-y-8 px-6 pb-8 pt-7 sm:px-7">
       <Section number="1" title="Company Scope" description="Choose the company(ies) to include in 13th Month Pay computation.">
        <Select value={scope} onValueChange={setScope}>
         <SelectTrigger className={`${selectTriggerClass} w-full sm:w-64`}><SelectValue/></SelectTrigger>
         <SelectContent position="popper" align="start" className={selectContentClass}><SelectItem className={selectItemClass} value="all">All Companies</SelectItem><SelectItem className={selectItemClass} value="specific">Specific Companies</SelectItem></SelectContent>
        </Select>
        {scope==='specific'&&(
         <div className="grid gap-3 rounded-xl border border-border bg-muted/20 p-3 sm:grid-cols-2 lg:grid-cols-3">
          {companies.map(company=>(
           <label key={company.id} className={`group flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition-all ${companyIds.includes(String(company.id))?'border-[#f4511e]/50 bg-[#fff7f3] shadow-sm dark:bg-[#2a1813]':'border-transparent bg-background hover:border-border hover:shadow-sm'}`}>
            <CompanyLogo company={company}/>
            <span className="min-w-0 flex-1 truncate text-sm font-semibold">{company.name}</span>
            <Checkbox checked={companyIds.includes(String(company.id))} onCheckedChange={checked=>setCompanyIds(old=>checked?[...new Set([...old,String(company.id)])]:old.filter(id=>id!==String(company.id)))} className="data-[state=checked]:border-[#f4511e] data-[state=checked]:bg-[#f4511e]"/>
           </label>
          ))}
         </div>
        )}
       </Section>

       <Section number="2" title="Computation Basis" description="Select the basis for computing the 13th Month Pay.">
        <div className="flex items-center gap-4">
         <Select value={basis} onValueChange={setBasis}>
          <SelectTrigger className={`${selectTriggerClass} w-full sm:w-80`}><SelectValue/></SelectTrigger>
          <SelectContent position="popper" align="start" className={selectContentClass}><SelectItem className={selectItemClass} value="basic">Basic Pay - DOLE Standard</SelectItem><SelectItem className={selectItemClass} value="gross">Gross Pay - Company Policy</SelectItem></SelectContent>
         </Select>
         <span title="Finalized eligible earnings are divided by 12" className="grid size-5 place-items-center rounded-full border border-muted-foreground text-[11px] font-bold text-muted-foreground">i</span>
        </div>
       </Section>

       <Section number="3" title="Coverage Period" description="Set the period for the 13th Month Pay coverage.">
        <div className="space-y-2">
         <Label className="text-xs font-semibold">Coverage Type</Label>
         <Select value={coverage} onValueChange={setCoverage}>
          <SelectTrigger className={`${selectTriggerClass} w-full sm:w-72`}><SelectValue/></SelectTrigger>
          <SelectContent position="popper" align="start" className={selectContentClass}><SelectItem className={selectItemClass} value="dec_nov">Dec Previous Year - Nov Current Year</SelectItem><SelectItem className={selectItemClass} value="calendar_year">Jan - Dec Current Year</SelectItem><SelectItem className={selectItemClass} value="first_half">First Half: Jan - Jun</SelectItem><SelectItem className={selectItemClass} value="second_half">Second Half: Jul - Dec</SelectItem><SelectItem className={selectItemClass} value="custom">Custom Month Range</SelectItem></SelectContent>
         </Select>
        </div>
        {coverage==='custom'?(
         <div className="grid max-w-4xl gap-3 sm:grid-cols-[1fr_.78fr_auto_1fr_.78fr] sm:items-end">
          <MonthField label="" value={startMonth} set={setStartMonth}/>
          <Input aria-label="Start year" className="h-10 rounded-lg shadow-none" type="number" value={startYear} onChange={event=>setStartYear(event.target.value)}/>
          <span className="hidden pb-3 text-xs font-semibold sm:block">To</span>
          <MonthField label="To" value={endMonth} set={setEndMonth}/>
          <Input aria-label="End year" className="h-10 rounded-lg shadow-none" type="number" value={endYear} onChange={event=>setEndYear(event.target.value)}/>
         </div>
        ):(
         <div className="max-w-52 space-y-2">
          <Label className="text-xs font-semibold">Reference Year</Label>
          <Input className="h-10 rounded-lg shadow-none" type="number" min="2000" max="2100" value={referenceYear} onChange={event=>setReferenceYear(event.target.value)}/>
         </div>
        )}
        <div className="flex max-w-4xl items-center gap-2 rounded-lg border border-[#ffc7b3] bg-[#fff7f3] px-4 py-3 text-sm font-medium text-[#e84817] dark:border-[#74321d] dark:bg-[#2a1813] dark:text-[#ff8a61]">
         <span className="grid size-4 shrink-0 place-items-center rounded-full border border-current text-[10px] font-bold">i</span>
         Effective coverage: {months[resolved.sm-1]} {resolved.sy} - {months[resolved.em-1]} {resolved.ey}
        </div>
       </Section>

       <div className="border-t border-border pt-4 text-xs text-muted-foreground">
        {settings?.updated_at?<>Last updated {new Date(settings.updated_at).toLocaleString()}{settings.updated_by_user?.name?` by ${settings.updated_by_user.name}`:''}</>:'Settings have not been saved yet.'}
       </div>
      </CardContent>
     </Card>
    </div>
   </div>
  </div>
 )
}

function Section({number,title,description,children}){
 return <section className="grid gap-3 sm:grid-cols-[24px_minmax(0,1fr)]">
  <span className="grid size-6 place-items-center rounded-full bg-[#f4511e] text-xs font-bold text-white">{number}</span>
  <div><h3 className="text-sm font-bold">{title}</h3><p className="mt-1 text-xs text-muted-foreground">{description}</p><div className="mt-3 space-y-4">{children}</div></div>
 </section>
}

function MonthField({label,value,set}){
 return <div className="space-y-2">{label&&<Label className="text-xs font-semibold sm:hidden">{label}</Label>}<Select value={value} onValueChange={set}><SelectTrigger className={`${selectTriggerClass} w-full`}><SelectValue/></SelectTrigger><SelectContent position="popper" align="start" className={selectContentClass}>{months.map((month,index)=><SelectItem className={selectItemClass} key={month} value={String(index+1)}>{month}</SelectItem>)}</SelectContent></Select></div>
}

function CompanyLogo({company}){
 const [failed,setFailed]=useState(false)
 const src=companyLogoUrl(company)
 const initials=String(company?.name||'Company').split(/\s+/).filter(Boolean).slice(0,2).map(word=>word[0]).join('').toUpperCase()
 return <span className="grid size-11 shrink-0 place-items-center overflow-hidden rounded-xl border border-border bg-card shadow-sm">
  {src&&!failed?<img src={src} alt={`${company.name} logo`} className="size-full object-contain p-1.5" onError={()=>setFailed(true)}/>:initials?<span className="text-xs font-bold text-[#e84817]">{initials}</span>:<Building2 className="size-5 text-muted-foreground"/>}
 </span>
}
