import { useCallback, useEffect, useState } from 'react'
import { getMyPayslipPdfBlob, getMyPayslips } from '@/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/components/ui/use-toast'
import { useAuth } from '@/contexts/AuthContext'
import { Download, Eye, Loader2 } from 'lucide-react'

function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.click()
  URL.revokeObjectURL(url)
}

export function EmployeePayslipsPanel() {
  const { toast } = useToast()
  const { user } = useAuth()
  const canDownloadPayslip = new Set(user?.permissions ?? []).has('payslip.download')
  const [loading, setLoading] = useState(true)
  const [rows, setRows] = useState([])
  const [previewUrl, setPreviewUrl] = useState(null)
  const [previewTitle, setPreviewTitle] = useState('')
  const [previewLoading, setPreviewLoading] = useState(false)
  const [previewOpen, setPreviewOpen] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await getMyPayslips({ per_page: 30 })
      setRows(Array.isArray(res?.data) ? res.data : [])
    } catch (e) {
      toast({ title: 'Payslips', description: e.message || 'Could not load payslips', variant: 'destructive' })
      setRows([])
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => {
    load()
  }, [load])

  const openPreview = async (row) => {
    setPreviewOpen(true)
    setPreviewLoading(true)
    setPreviewTitle(`${row.pay_period_start} → ${row.pay_period_end}`)
    try {
      if (previewUrl) URL.revokeObjectURL(previewUrl)
      const blob = await getMyPayslipPdfBlob(row.id)
      const url = URL.createObjectURL(blob)
      setPreviewUrl(url)
    } catch (e) {
      toast({ title: 'Preview', description: e.message, variant: 'destructive' })
      setPreviewOpen(false)
    } finally {
      setPreviewLoading(false)
    }
  }

  return (
    <>
      <Card className="border border-border/60 shadow-sm">
        <CardHeader>
          <CardTitle>Payslips</CardTitle>
          <CardDescription>
            Payslips appear here after HR finalizes payroll and uses Send. Download or preview in the browser.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {loading ? (
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
              <Loader2 className="h-4 w-4 animate-spin" /> Loading payslips…
            </p>
          ) : rows.length === 0 ? (
            <p className="text-sm text-muted-foreground">No payslips yet. HR will publish them after payroll runs.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Pay period</TableHead>
                  <TableHead>Pay date</TableHead>
                  <TableHead className="text-right">Net pay</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((r) => (
                  <TableRow key={r.id}>
                    <TableCell className="text-sm">
                      {r.pay_period_start} → {r.pay_period_end}
                    </TableCell>
                    <TableCell className="text-sm">{r.pay_date || '—'}</TableCell>
                    <TableCell className="text-right">
                      ₱{Number(r.net_pay).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="flex justify-end gap-2">
                        <Button type="button" size="sm" variant="outline" onClick={() => openPreview(r)}>
                          <Eye className="mr-1 h-4 w-4" />
                          View
                        </Button>
                        {canDownloadPayslip && (
                          <Button
                            type="button"
                            size="sm"
                            onClick={async () => {
                              try {
                                const blob = await getMyPayslipPdfBlob(r.id)
                                downloadBlob(blob, `payslip-${r.id}.pdf`)
                              } catch (e) {
                                toast({ title: 'Download', description: e.message, variant: 'destructive' })
                              }
                            }}
                          >
                            <Download className="mr-1 h-4 w-4" />
                            PDF
                          </Button>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Dialog
        open={previewOpen}
        onOpenChange={(o) => {
          if (!o) {
            setPreviewOpen(false)
            if (previewUrl) URL.revokeObjectURL(previewUrl)
            setPreviewUrl(null)
          }
        }}
      >
        <DialogContent className="max-h-[90vh] w-full max-w-4xl overflow-hidden">
          <DialogHeader>
            <DialogTitle>Payslip {previewTitle}</DialogTitle>
          </DialogHeader>
          {previewLoading ? (
            <div className="flex justify-center py-12">
              <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
            </div>
          ) : previewUrl ? (
            <iframe title="Payslip preview" src={previewUrl} className="h-[72vh] w-full rounded border" />
          ) : null}
        </DialogContent>
      </Dialog>
    </>
  )
}
