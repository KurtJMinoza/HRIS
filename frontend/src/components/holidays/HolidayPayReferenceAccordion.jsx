import { Badge } from '@/components/ui/badge'
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion'
import { ExternalLink } from 'lucide-react'

/**
 * DOLE-aligned reference for admins. Aligns with backend PhPayrollReference + Labor Code Arts. 87, 93, 94.
 * (Full “Policy and Procedure Manual” PDF text was not provided — this is the condensed rules-engine view.)
 */
export function HolidayPayReferenceAccordion() {
  return (
    <div className="rounded-xl border border-indigo-500/10 bg-gradient-to-br from-indigo-500/[0.03] via-teal-500/[0.02] to-transparent p-4 dark:from-indigo-500/10 dark:via-teal-950/20">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-bold tracking-tight text-foreground">
          Holiday Pay Rules Reference (DOLE / Labor Code — 2026)
        </h3>
        <a
          href="https://www.dole.gov.ph/"
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:underline dark:text-teal-400"
        >
          DOLE website
          <ExternalLink className="size-3.5" aria-hidden />
        </a>
      </div>
      <p className="mb-4 text-xs leading-relaxed text-muted-foreground">
        This panel summarizes statutory premium factors used by the payroll rules engine. For individual cases, refer to
        proclamations, CBAs, and internal policy. Multipliers below apply to the <strong>first 8 hours</strong> (ordinary
        workday) unless otherwise noted.
      </p>

      <Accordion type="multiple" className="w-full rounded-lg border border-border/50 bg-card/50">
        <AccordionItem value="legal">
          <AccordionTrigger className="px-3 text-xs @sm:text-sm">Legal basis &amp; hierarchy</AccordionTrigger>
          <AccordionContent className="space-y-2 px-3 text-xs leading-relaxed">
            <ul className="list-disc space-y-1 pl-4">
              <li>Philippine Labor Code — Articles 87, 93, 94 (holiday &amp; premium pay).</li>
              <li>DOLE Omnibus Rules implementing the Labor Code.</li>
              <li>Company policy / CBA may add benefits beyond the statutory floor.</li>
            </ul>
            <p className="text-muted-foreground">
              Rules engine order: holiday type → rest day flag → attendance session → segment net minutes (regular / OT / night
              differential).
            </p>
          </AccordionContent>
        </AccordionItem>

        <AccordionItem value="matrix">
          <AccordionTrigger className="px-3 text-xs @sm:text-sm">
            First 8 hours — matrix (key statutory rates)
          </AccordionTrigger>
          <AccordionContent className="px-3">
            <div className="max-w-full overflow-x-auto rounded-lg border border-border/60">
              <table className="w-full min-w-[520px] text-left text-xs">
                <thead className="bg-muted/50 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
                  <tr>
                    <th className="px-3 py-2">Holiday type</th>
                    <th className="px-3 py-2">Rest day</th>
                    <th className="px-3 py-2">Worked</th>
                    <th className="px-3 py-2">First 8h</th>
                    <th className="px-3 py-2">Note</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border/60 text-foreground">
                  <tr>
                    <td className="px-3 py-2">Ordinary day</td>
                    <td className="px-3 py-2">No</td>
                    <td className="px-3 py-2">Yes</td>
                    <td className="px-3 py-2">
                      <Badge className="bg-emerald-600 text-white">100%</Badge>
                    </td>
                    <td className="px-3 py-2 text-muted-foreground">—</td>
                  </tr>
                  <tr>
                    <td className="px-3 py-2">Regular Holiday</td>
                    <td className="px-3 py-2">No</td>
                    <td className="px-3 py-2">Yes</td>
                    <td className="px-3 py-2">
                      <Badge className="bg-teal-700 text-white">200%</Badge>
                    </td>
                    <td className="px-3 py-2 text-muted-foreground">Ordinary day</td>
                  </tr>
                  <tr>
                    <td className="px-3 py-2">Regular Holiday</td>
                    <td className="px-3 py-2">Yes</td>
                    <td className="px-3 py-2">Yes</td>
                    <td className="px-3 py-2">
                      <Badge className="bg-teal-700 text-white">260%</Badge>
                    </td>
                    <td className="px-3 py-2 text-muted-foreground">RH + RD</td>
                  </tr>
                  <tr>
                    <td className="px-3 py-2">Special Holiday</td>
                    <td className="px-3 py-2">No</td>
                    <td className="px-3 py-2">Yes</td>
                    <td className="px-3 py-2">
                      <Badge className="bg-amber-600 text-white">130%</Badge>
                    </td>
                    <td className="px-3 py-2 text-muted-foreground">SNW</td>
                  </tr>
                  <tr>
                    <td className="px-3 py-2">Special Holiday</td>
                    <td className="px-3 py-2">Yes</td>
                    <td className="px-3 py-2">Yes</td>
                    <td className="px-3 py-2">
                      <Badge className="bg-amber-600 text-white">150%</Badge>
                    </td>
                    <td className="px-3 py-2 text-muted-foreground">SNW + RD</td>
                  </tr>
                  <tr>
                    <td className="px-3 py-2">Special Holiday</td>
                    <td className="px-3 py-2">No</td>
                    <td className="px-3 py-2">No</td>
                    <td className="px-3 py-2">—</td>
                    <td className="px-3 py-2 text-muted-foreground">Typically no pay unless policy/CBA</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </AccordionContent>
        </AccordionItem>

        <AccordionItem value="ot">
          <AccordionTrigger className="px-3 text-xs @sm:text-sm">Overtime (beyond 8h) — rule codes</AccordionTrigger>
          <AccordionContent className="space-y-2 px-3 text-xs leading-relaxed">
            <p>
              OT rates use the payroll rules engine (`ORD`, `RD`, `RH`, `RHRD`, `SH`, `SHRD`, `DH`, `DHRD`). Example: RH
              ordinary OT often <strong>2.60×</strong>; RH + rest day OT higher — verify live config in <strong>Admin → Payroll</strong>.
            </p>
            <p className="text-muted-foreground">
              Night differential (+10% on applicable hours) applies on top of the correct base when configured.
            </p>
          </AccordionContent>
        </AccordionItem>

        <AccordionItem value="terms">
          <AccordionTrigger className="px-3 text-xs @sm:text-sm">Terminology</AccordionTrigger>
          <AccordionContent className="space-y-2 px-3 text-xs leading-relaxed">
            <p>
              <strong>Regular Holiday (RH):</strong> statutory list / proclamations — premium pay if worked.
            </p>
            <p>
              <strong>Special Non-Working (SNW):</strong> typically 130% if worked; unworked often unpaid unless monthly-paid rules
              or policy.
            </p>
            <p>
              <strong>Special Working Day:</strong> declared by government for certain dates — treated as an ordinary working day
              for statutory premium (no RH/SNW premium).
            </p>
          </AccordionContent>
        </AccordionItem>
      </Accordion>
    </div>
  )
}
