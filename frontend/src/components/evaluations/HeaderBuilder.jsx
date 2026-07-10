/* eslint-disable react-refresh/only-export-components */
// (useMemo intentionally omitted; not needed in this component)
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Switch } from '@/components/ui/switch'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import {
  Image, Type, Droplets, Layers, Calendar,
  AlignLeft, AlignCenter, AlignRight, Eye, EyeOff,
} from 'lucide-react'

function HeaderSection({ title, icon: Icon, children }) {
  return (
    <div className="rounded-lg border border-border/70 bg-card">
      <div className="flex items-center gap-2 border-b border-border/50 bg-muted/20 px-4 py-2.5">
        <span className="flex size-7 items-center justify-center rounded-md bg-brand/10 text-brand">
          <Icon className="size-3.5" />
        </span>
        <h4 className="text-xs font-bold uppercase tracking-wide text-foreground">{title}</h4>
      </div>
      <div className="space-y-3 p-4">{children}</div>
    </div>
  )
}

function FieldRow({ label, children, className }) {
  return (
    <div className={cn('space-y-1.5', className)}>
      <Label className="text-[11px] font-semibold text-muted-foreground">{label}</Label>
      {children}
    </div>
  )
}

export default function HeaderBuilder({ config = {}, onChange }) {
  const patch = (partial) => onChange?.({ ...config, ...partial })

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <span className="flex size-9 items-center justify-center rounded-lg bg-brand/10 text-brand">
          <Layers className="size-4" />
        </span>
        <div>
          <h3 className="text-sm font-bold text-foreground">Header Builder</h3>
          <p className="text-xs text-muted-foreground">Design the evaluation document header — logo, title, watermark, and background.</p>
        </div>
      </div>

      <div className="grid gap-4 @md:grid-cols-2">
        {/* ─── Logo Section ─── */}
        <HeaderSection title="Logo" icon={Image}>
          <FieldRow label="Company Logo URL">
            <div className="flex items-center gap-2">
              <Input
                value={config.logo_url || ''}
                onChange={(e) => patch({ logo_url: e.target.value })}
                className="h-9 rounded-lg text-sm"
                placeholder="https://example.com/logo.png"
              />
              <Button type="button" variant="outline" size="icon-sm" className="size-9 shrink-0 rounded-lg" onClick={() => patch({ show_logo: !config.show_logo })}>
                {config.show_logo !== false ? <Eye className="size-4" /> : <EyeOff className="size-4" />}
              </Button>
            </div>
          </FieldRow>
          <div className="flex items-center gap-3">
            <FieldRow label="Logo Width (px)" className="flex-1">
              <Input
                type="number"
                min="40"
                max="300"
                value={config.logo_width ?? 120}
                onChange={(e) => patch({ logo_width: Number(e.target.value) })}
                className="h-9 rounded-lg text-sm"
              />
            </FieldRow>
            <FieldRow label="Position" className="flex-1">
              <Select value={config.logo_position || 'left'} onValueChange={(v) => patch({ logo_position: v })}>
                <SelectTrigger className="h-9 rounded-lg"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="left"><div className="flex items-center gap-2"><AlignLeft className="size-4" /> Left</div></SelectItem>
                  <SelectItem value="center"><div className="flex items-center gap-2"><AlignCenter className="size-4" /> Center</div></SelectItem>
                  <SelectItem value="right"><div className="flex items-center gap-2"><AlignRight className="size-4" /> Right</div></SelectItem>
                </SelectContent>
              </Select>
            </FieldRow>
          </div>
        </HeaderSection>

        {/* ─── Title Section ─── */}
        <HeaderSection title="Title & Heading" icon={Type}>
          <FieldRow label="Evaluation Title">
            <Input
              value={config.title || ''}
              onChange={(e) => patch({ title_text: e.target.value })}
              className="h-9 rounded-lg text-sm font-bold"
              placeholder="e.g. 360-Degree Performance Feedback Survey"
            />
          </FieldRow>
          <FieldRow label="Subtitle">
            <Input
              value={config.subtitle || ''}
              onChange={(e) => patch({ subtitle: e.target.value })}
              className="h-9 rounded-lg text-sm"
              placeholder="e.g. STRICTLY CONFIDENTIAL"
            />
          </FieldRow>
          <div className="flex items-center gap-3">
            <FieldRow label="Title Color" className="flex-1">
              <div className="flex items-center gap-2">
                <Input
                  type="color"
                  value={config.title_color || '#000000'}
                  onChange={(e) => patch({ title_color: e.target.value })}
                  className="h-9 w-12 rounded-lg p-1"
                />
                <Input
                  value={config.title_color || '#000000'}
                  onChange={(e) => patch({ title_color: e.target.value })}
                  className="h-9 flex-1 rounded-lg font-mono text-xs"
                />
              </div>
            </FieldRow>
            <FieldRow label="Size" className="w-24">
              <Select value={config.title_size || '2xl'} onValueChange={(v) => patch({ title_size: v })}>
                <SelectTrigger className="h-9 rounded-lg"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="lg">Large</SelectItem>
                  <SelectItem value="xl">X-Large</SelectItem>
                  <SelectItem value="2xl">2X-Large</SelectItem>
                  <SelectItem value="3xl">3X-Large</SelectItem>
                </SelectContent>
              </Select>
            </FieldRow>
          </div>
          <label className="flex items-center justify-between text-xs pt-1">
            <span className="font-medium text-muted-foreground">Show confidential badge</span>
            <Switch checked={config.show_confidential_badge !== false} onCheckedChange={(v) => patch({ show_confidential_badge: v })} />
          </label>
        </HeaderSection>

        {/* ─── Watermark ─── */}
        <HeaderSection title="Watermark" icon={Droplets}>
          <FieldRow label="Watermark Text">
            <Input
              value={config.watermark_text || ''}
              onChange={(e) => patch({ watermark_text: e.target.value })}
              className="h-9 rounded-lg text-sm"
              placeholder="e.g. CONFIDENTIAL"
            />
          </FieldRow>
          <div className="flex items-center gap-3">
            <FieldRow label="Color" className="flex-1">
              <div className="flex items-center gap-2">
                <Input
                  type="color"
                  value={config.watermark_color || '#cccccc'}
                  onChange={(e) => patch({ watermark_color: e.target.value })}
                  className="h-9 w-12 rounded-lg p-1"
                />
                <Input
                  value={config.watermark_color || '#cccccc'}
                  onChange={(e) => patch({ watermark_color: e.target.value })}
                  className="h-9 flex-1 rounded-lg font-mono text-xs"
                />
              </div>
            </FieldRow>
            <FieldRow label="Opacity" className="w-24">
              <Input
                type="number"
                min="0"
                max="100"
                value={config.watermark_opacity ?? 15}
                onChange={(e) => patch({ watermark_opacity: Number(e.target.value) })}
                className="h-9 rounded-lg text-sm"
              />
            </FieldRow>
          </div>
          <label className="flex items-center justify-between text-xs">
            <span className="font-medium text-muted-foreground">Enable watermark</span>
            <Switch checked={config.watermark_enabled || false} onCheckedChange={(v) => patch({ watermark_enabled: v })} />
          </label>
        </HeaderSection>

        {/* ─── Background ─── */}
        <HeaderSection title="Background" icon={Layers}>
          <FieldRow label="Background Image URL">
            <Input
              value={config.background_url || ''}
              onChange={(e) => patch({ background_url: e.target.value })}
              className="h-9 rounded-lg text-sm"
              placeholder="https://example.com/bg.png"
            />
          </FieldRow>
          <FieldRow label="Background Color">
            <div className="flex items-center gap-2">
              <Input
                type="color"
                value={config.background_color || '#ffffff'}
                onChange={(e) => patch({ background_color: e.target.value })}
                className="h-9 w-12 rounded-lg p-1"
              />
              <Input
                value={config.background_color || '#ffffff'}
                onChange={(e) => patch({ background_color: e.target.value })}
                className="h-9 flex-1 rounded-lg font-mono text-xs"
              />
            </div>
          </FieldRow>
          <label className="flex items-center justify-between text-xs">
            <span className="font-medium text-muted-foreground">Use custom background</span>
            <Switch checked={config.background_enabled || false} onCheckedChange={(v) => patch({ background_enabled: v })} />
          </label>
        </HeaderSection>

        {/* ─── Date & Info ─── */}
        <HeaderSection title="Evaluation Date & Info" icon={Calendar}>
          <FieldRow label="Date Format">
            <Select value={config.date_format || 'MMMM D, YYYY'} onValueChange={(v) => patch({ date_format: v })}>
              <SelectTrigger className="h-9 rounded-lg"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="MMMM D, YYYY">January 15, 2026</SelectItem>
                <SelectItem value="MMM D, YYYY">Jan 15, 2026</SelectItem>
                <SelectItem value="YYYY-MM-DD">2026-01-15</SelectItem>
                <SelectItem value="DD/MM/YYYY">15/01/2026</SelectItem>
                <SelectItem value="MM/DD/YYYY">01/15/2026</SelectItem>
              </SelectContent>
            </Select>
          </FieldRow>
          <div className="flex items-center gap-3">
            <label className="flex items-center justify-between text-xs flex-1">
              <span className="font-medium text-muted-foreground">Show evaluation period</span>
              <Switch checked={config.show_period !== false} onCheckedChange={(v) => patch({ show_period: v })} />
            </label>
            <label className="flex items-center justify-between text-xs flex-1">
              <span className="font-medium text-muted-foreground">Show date prepared</span>
              <Switch checked={config.show_date_prepared !== false} onCheckedChange={(v) => patch({ show_date_prepared: v })} />
            </label>
          </div>
        </HeaderSection>
      </div>

      {/* ─── Preview ─── */}
      <div className="overflow-hidden rounded-lg border border-border/70 bg-card">
        <div className="border-b border-border/50 bg-muted/20 px-4 py-2.5">
          <p className="text-xs font-bold uppercase tracking-wide text-muted-foreground">Preview</p>
        </div>
        <div
          className="relative overflow-hidden px-8 py-6 text-center"
          style={{
            backgroundColor: config.background_enabled ? (config.background_color || '#ffffff') : undefined,
            backgroundImage: config.background_url ? `url(${config.background_url})` : undefined,
            backgroundSize: 'cover',
            backgroundPosition: 'center',
          }}
        >
          {/* Watermark */}
          {config.watermark_enabled && config.watermark_text && (
            <div
              className="pointer-events-none absolute inset-0 flex items-center justify-center select-none"
              style={{ opacity: (config.watermark_opacity ?? 15) / 100 }}
            >
              <span
                className="text-5xl font-black tracking-[0.3em] -rotate-20"
                style={{ color: config.watermark_color || '#cccccc' }}
              >
                {config.watermark_text}
              </span>
            </div>
          )}

          {/* Logo */}
          {config.show_logo !== false && config.logo_url && (
            <div className={cn(
              'mb-4',
              config.logo_position === 'center' && 'flex justify-center',
              config.logo_position === 'right' && 'flex justify-end',
            )}>
              <img
                src={config.logo_url}
                alt="Company logo"
                style={{ width: config.logo_width || 120, height: 'auto' }}
                className="object-contain"
              />
            </div>
          )}

          {/* Title */}
          <h2
            className={cn(
              'font-black tracking-tight',
              config.title_size === 'lg' && 'text-lg',
              config.title_size === 'xl' && 'text-xl',
              config.title_size === '2xl' && 'text-2xl',
              config.title_size === '3xl' && 'text-3xl',
            )}
            style={{ color: config.title_color || '#000000' }}
          >
            {config.title_text || 'Performance Evaluation Form'}
          </h2>

          {/* Subtitle */}
          {config.subtitle && (
            <p className="mt-1 text-sm font-bold uppercase tracking-[0.15em]" style={{ color: config.title_color || '#666' }}>
              {config.subtitle}
            </p>
          )}

          {/* Confidential Badge */}
          {config.show_confidential_badge !== false && (
            <div className="mt-3 inline-flex items-center gap-1.5 rounded-full border border-red-500/30 bg-red-50 px-3 py-1 text-[10px] font-bold uppercase tracking-[0.12em] text-red-600 dark:bg-red-500/10 dark:text-red-400">
              <span className="size-1.5 rounded-full bg-red-500" />
              Strictly Confidential
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

// ─── Default Header Config ─────────────────────────────────────────

export const DEFAULT_HEADER_CONFIG = {
  show_logo: true,
  logo_url: '',
  logo_width: 120,
  logo_position: 'left',
  title_text: 'Performance Evaluation Form',
  subtitle: '',
  title_color: '#000000',
  title_size: '2xl',
  show_confidential_badge: true,
  watermark_enabled: false,
  watermark_text: 'CONFIDENTIAL',
  watermark_color: '#cccccc',
  watermark_opacity: 15,
  background_enabled: false,
  background_url: '',
  background_color: '#ffffff',
  date_format: 'MMMM D, YYYY',
  show_period: true,
  show_date_prepared: true,
}
