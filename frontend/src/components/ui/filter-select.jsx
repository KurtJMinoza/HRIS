import * as React from 'react'
import { Loader2 } from 'lucide-react'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { cn } from '@/lib/utils'

export const FILTER_SELECT_TRIGGER_CLASS =
  'h-10 w-full rounded-lg border border-border/80 bg-card text-sm font-medium shadow-sm transition hover:border-brand/35 hover:bg-muted/20 focus-visible:border-brand/40 focus-visible:ring-brand/15 dark:border-white/10 dark:bg-card/80'

export const FILTER_FIELD_LABEL_CLASS =
  'mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-muted-foreground'

const EMPTY_VALUE = '__filter_select_empty__'

function collectOptions(nodes, out) {
  React.Children.forEach(nodes, (child) => {
    if (child == null || child === false) return
    if (!React.isValidElement(child)) return

    if (child.type === 'option') {
      const rawValue = child.props.value
      const isEmptyValue = rawValue === '' || rawValue === undefined || rawValue === null
      out.push({
        value: isEmptyValue ? EMPTY_VALUE : String(rawValue),
        label: child.props.children,
        disabled: Boolean(child.props.disabled) || isEmptyValue,
        hidden: Boolean(child.props.hidden),
      })
      return
    }

    if (child.type === React.Fragment) {
      collectOptions(child.props.children, out)
    }
  })
}

function parseSelectOptions(children) {
  const options = []
  collectOptions(children, options)
  return options.filter((option) => !option.hidden)
}

function resolveSelectValue(value) {
  if (value === '' || value === undefined || value === null) return EMPTY_VALUE
  return String(value)
}

function emitChangeValue(value, onChange) {
  if (!onChange) return
  onChange({ target: { value: value === EMPTY_VALUE ? '' : value } })
}

export function FilterField({ label, htmlFor, className, title, children }) {
  return (
    <div className={cn('min-w-0', className)}>
      {label ? (
        <label htmlFor={htmlFor} className={FILTER_FIELD_LABEL_CLASS} title={title}>
          {label}
        </label>
      ) : null}
      {children}
    </div>
  )
}

export function FilterSelect({
  className,
  triggerClassName,
  contentClassName,
  children,
  loading = false,
  loadingLabel = 'Loading options…',
  placeholder = 'Select…',
  value,
  onChange,
  disabled,
  id,
  ...props
}) {
  const options = React.useMemo(() => parseSelectOptions(children), [children])
  const resolvedValue = resolveSelectValue(value)
  const hasResolvedOption = options.some((option) => option.value === resolvedValue)
  const selectValue = hasResolvedOption ? resolvedValue : undefined
  const emptyMessage = options.find((option) => option.value === EMPTY_VALUE)?.label

  return (
    <Select
      value={selectValue}
      onValueChange={(next) => emitChangeValue(next, onChange)}
      disabled={disabled || loading}
    >
      <SelectTrigger
        id={id}
        className={cn(FILTER_SELECT_TRIGGER_CLASS, triggerClassName, className)}
        aria-label={props['aria-label']}
      >
        {loading ? (
          <span className="flex items-center gap-2 text-muted-foreground">
            <Loader2 className="size-3.5 shrink-0 animate-spin" aria-hidden />
            <span className="truncate">{loadingLabel}</span>
          </span>
        ) : (
          <SelectValue placeholder={emptyMessage || placeholder} />
        )}
      </SelectTrigger>
      <SelectContent
        className={cn(
          'max-h-64 overflow-hidden rounded-xl border border-border/55 bg-card/95 p-1 shadow-lg shadow-black/6 ring-1 ring-black/5 dark:border-border/50 dark:bg-card/90 dark:ring-white/5',
          contentClassName,
        )}
      >
        {loading ? (
          <SelectItem value="__loading" disabled>
            {loadingLabel}
          </SelectItem>
        ) : options.length === 0 ? (
          <SelectItem value="__empty" disabled>
            No options available
          </SelectItem>
        ) : (
          options.map((option) => (
            <SelectItem key={option.value} value={option.value} disabled={option.disabled}>
              {option.label}
            </SelectItem>
          ))
        )}
      </SelectContent>
    </Select>
  )
}
