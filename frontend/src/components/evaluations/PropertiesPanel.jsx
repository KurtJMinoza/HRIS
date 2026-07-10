/* eslint-disable react-refresh/only-export-components */
import { useState, useMemo } from 'react'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import InlineRichTextEditor from './InlineRichTextEditor'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
// ScrollArea fallback: using a simple scrollable div
function ScrollArea({ children, className }) {
  return <div className={cn('overflow-y-auto', className)}>{children}</div>
}
import {
  Settings2,
  Asterisk,
  Weight,
  ListChecks,
  SlidersHorizontal,
  Eye,
  EyeOff,
  FileText,
  HelpCircle,
  Lock,
  Calculator,
  Paperclip,
  MessageSquare,
  PenLine,
  Trash2,
  Copy,
} from 'lucide-react'
import { QUESTION_TYPE_MAP } from './QuestionLibraryPanel'

// ─── Properties Section Component ───────────────────────────────────

function PropertiesSection({ title, icon: Icon, children, className }) {
  return (
    <div className={cn('border-b border-border/50 pb-4', className)}>
      <div className="mb-3 flex items-center gap-2">
        <span className="flex size-6 items-center justify-center rounded-md bg-brand/10 text-brand">
          <Icon className="size-3.5" />
        </span>
        <h4 className="text-xs font-bold uppercase tracking-wide text-foreground">{title}</h4>
      </div>
      {children}
    </div>
  )
}

// ─── Field Row Component ────────────────────────────────────────────

function FieldRow({ label, children, className }) {
  return (
    <div className={cn('space-y-1.5', className)}>
      <Label className="text-[11px] font-semibold text-muted-foreground">{label}</Label>
      {children}
    </div>
  )
}

// ─── Options Editor ─────────────────────────────────────────────────

function OptionsEditor({ options = [], onChange }) {
  const [newOption, setNewOption] = useState('')

  const addOption = () => {
    if (!newOption.trim()) return
    onChange([...options, newOption.trim()])
    setNewOption('')
  }

  return (
    <div className="space-y-1.5">
      {options.length === 0 && (
        <p className="text-[11px] text-muted-foreground italic">No options defined</p>
      )}
      {options.map((opt, idx) => (
        <div key={idx} className="flex items-center gap-1">
          <Input
            value={opt}
            onChange={(e) => {
              const next = [...options]
              next[idx] = e.target.value
              onChange(next)
            }}
            className="h-7 flex-1 rounded-md text-xs"
          />
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            className="size-6 shrink-0 text-destructive"
            onClick={() => onChange(options.filter((_, i) => i !== idx))}
          >
            <Trash2 className="size-3" />
          </Button>
        </div>
      ))}
      <div className="flex items-center gap-1">
        <Input
          value={newOption}
          onChange={(e) => setNewOption(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), addOption())}
          placeholder="Add option..."
          className="h-7 flex-1 rounded-md text-xs"
        />
        <Button type="button" variant="ghost" size="icon-sm" className="size-6 shrink-0" onClick={addOption}>
          <Copy className="size-3" />
        </Button>
      </div>
    </div>
  )
}

// ─── Matrix Rows Editor ─────────────────────────────────────────────

function MatrixRowsEditor({ rows = [], onChange }) {
  const [newRow, setNewRow] = useState('')

  const addRow = () => {
    if (!newRow.trim()) return
    onChange([...rows, newRow.trim()])
    setNewRow('')
  }

  return (
    <div className="space-y-1.5">
      {rows.length === 0 && (
        <p className="text-[11px] text-muted-foreground italic">No rows defined</p>
      )}
      {rows.map((row, idx) => (
        <div key={idx} className="flex items-center gap-1">
          <Input
            value={row}
            onChange={(e) => {
              const next = [...rows]
              next[idx] = e.target.value
              onChange(next)
            }}
            className="h-7 flex-1 rounded-md text-xs"
          />
          <Button type="button" variant="ghost" size="icon-sm" className="size-6 shrink-0 text-destructive" onClick={() => onChange(rows.filter((_, i) => i !== idx))}>
            <Trash2 className="size-3" />
          </Button>
        </div>
      ))}
      <div className="flex items-center gap-1">
        <Input
          value={newRow}
          onChange={(e) => setNewRow(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), addRow())}
          placeholder="Add row..."
          className="h-7 flex-1 rounded-md text-xs"
        />
        <Button type="button" variant="ghost" size="icon-sm" className="size-6 shrink-0" onClick={addRow}>
          <Copy className="size-3" />
        </Button>
      </div>
    </div>
  )
}

// ─── Score Table Columns Editor ─────────────────────────────────────

function ScoreTableColumnsEditor({ columns = [], onChange }) {
  const [newCol, setNewCol] = useState('')

  const addCol = () => {
    if (!newCol.trim()) return
    onChange([...columns, { label: newCol.trim(), weight: 0 }])
    setNewCol('')
  }

  return (
    <div className="space-y-1.5">
      {columns.map((col, idx) => (
        <div key={idx} className="flex items-center gap-1">
          <Input
            value={col.label}
            onChange={(e) => {
              const next = [...columns]
              next[idx] = { ...next[idx], label: e.target.value }
              onChange(next)
            }}
            className="h-7 flex-1 rounded-md text-xs"
            placeholder="Column name"
          />
          <Input
            type="number"
            min="0"
            max="100"
            value={col.weight}
            onChange={(e) => {
              const next = [...columns]
              next[idx] = { ...next[idx], weight: Number(e.target.value) }
              onChange(next)
            }}
            className="h-7 w-14 rounded-md text-xs text-center"
            placeholder="Wt"
          />
          <Button type="button" variant="ghost" size="icon-sm" className="size-6 shrink-0 text-destructive" onClick={() => onChange(columns.filter((_, i) => i !== idx))}>
            <Trash2 className="size-3" />
          </Button>
        </div>
      ))}
      <div className="flex items-center gap-1">
        <Input
          value={newCol}
          onChange={(e) => setNewCol(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), addCol())}
          placeholder="Add column..."
          className="h-7 flex-1 rounded-md text-xs"
        />
        <Button type="button" variant="ghost" size="icon-sm" className="size-6 shrink-0" onClick={addCol}>
          <Copy className="size-3" />
        </Button>
      </div>
    </div>
  )
}

// ─── Main Properties Panel ──────────────────────────────────────────

export default function PropertiesPanel({
  question,
  section,
  sectionIndex,
  questionIndex,
  onUpdateQuestion,
  onUpdateSection,
  onDuplicate,
  onDelete,
  className,
}) {
  if (!question && !section) {
    return (
      <div className={cn('flex flex-col items-center justify-center px-4 py-16 text-center', className)}>
        <Settings2 className="mx-auto mb-3 size-10 text-muted-foreground/40" strokeWidth={1.5} />
        <p className="text-sm font-semibold text-muted-foreground">No Selection</p>
        <p className="mt-1 max-w-[14rem] text-[11px] leading-relaxed text-muted-foreground/60">
          Click on a question or section to view and edit its properties.
        </p>
      </div>
    )
  }

  const def = question ? QUESTION_TYPE_MAP[question.builder_type] : null
  const Icon = def?.icon || Settings2

  // Build properties based on what's selected
  const showOptions = question && ['multiple_choice', 'checkbox', 'dropdown', 'likert', 'yes_no', 'pass_fail', 'thumbs'].includes(question.builder_type)
  const showMatrixRows = question && ['matrix', 'score_table'].includes(question.builder_type)
  const showScoreColumns = question && question.builder_type === 'score_table'
  const showSliderMarks = question && question.builder_type === 'slider'
  const showMaxValue = question && ['rating', 'rating_10', 'nps', 'slider'].includes(question.builder_type)
  const showNpsLabels = question && question.builder_type === 'nps'

  return (
    <div className={cn('flex flex-col overflow-hidden', className)}>
      {/* Properties Header */}
      <div className="flex items-center justify-between border-b border-border/50 px-4 py-2.5">
        <div className="flex items-center gap-2">
          <h3 className="text-sm font-bold text-foreground">Properties</h3>
        </div>
        {question && (
          <div className="flex items-center gap-0.5">
            <Button type="button" variant="ghost" size="icon-sm" className="size-7" onClick={onDuplicate}>
              <Copy className="size-3.5" />
            </Button>
            <Button type="button" variant="ghost" size="icon-sm" className="size-7 text-destructive" onClick={onDelete}>
              <Trash2 className="size-3.5" />
            </Button>
          </div>
        )}
      </div>

      <ScrollArea className="flex-1">
        <div className="space-y-4 p-4">
          {/* ─── Question Properties ─── */}
          {question && (
            <>
              {/* Type Badge */}
              <div className="mb-1 flex items-center gap-2">
                <span className="flex size-7 items-center justify-center rounded-md bg-brand/10 text-brand">
                  <Icon className="size-3.5" />
                </span>
                <div>
                  <p className="text-sm font-semibold text-foreground">{def?.label || 'Question'}</p>
                  <p className="text-[10px] text-muted-foreground">{def?.category ? `${def.category} type` : ''}</p>
                </div>
              </div>

              <PropertiesSection title="Content" icon={FileText}>
                <FieldRow label="Question Title">
                  <Input
                    value={question.title || ''}
                    onChange={(e) => onUpdateQuestion?.(sectionIndex, questionIndex, { title: e.target.value })}
                    className="h-8 rounded-md text-sm"
                    placeholder="Enter question title"
                  />
                </FieldRow>
                <FieldRow label="Description / Guidance" className="mt-2">
                  <InlineRichTextEditor
                    content={question.description || ''}
                    onChange={(html) => onUpdateQuestion?.(sectionIndex, questionIndex, { description: html })}
                    placeholder="Optional description or guidance text (supports rich text)"
                    minHeight="4rem"
                    compact
                  />
                </FieldRow>
                <FieldRow label="Placeholder" className="mt-2">
                  <Input
                    value={question.placeholder || ''}
                    onChange={(e) => onUpdateQuestion?.(sectionIndex, questionIndex, { placeholder: e.target.value })}
                    className="h-8 rounded-md text-sm"
                    placeholder="Placeholder text"
                  />
                </FieldRow>
                <FieldRow label="Tooltip / Help Text" className="mt-2">
                  <Input
                    value={question.tooltip || ''}
                    onChange={(e) => onUpdateQuestion?.(sectionIndex, questionIndex, { tooltip: e.target.value })}
                    className="h-8 rounded-md text-sm"
                    placeholder="Help text shown on hover"
                  />
                </FieldRow>
              </PropertiesSection>

              <PropertiesSection title="Behavior" icon={SlidersHorizontal}>
                <div className="space-y-3">
                  <label className="flex items-center justify-between text-xs">
                    <span className="flex items-center gap-1.5 font-medium text-muted-foreground">
                      <Asterisk className="size-3" /> Required
                    </span>
                    <Switch
                      checked={Boolean(question.required)}
                      onCheckedChange={(v) => onUpdateQuestion?.(sectionIndex, questionIndex, { required: v })}
                    />
                  </label>

                  <label className="flex items-center justify-between text-xs">
                    <span className="flex items-center gap-1.5 font-medium text-muted-foreground">
                      <Eye className="size-3" /> Read Only
                    </span>
                    <Switch
                      checked={Boolean(question.read_only)}
                      onCheckedChange={(v) => onUpdateQuestion?.(sectionIndex, questionIndex, { read_only: v })}
                    />
                  </label>

                  {question.builder_type === 'file_upload' && (
                    <label className="flex items-center justify-between text-xs">
                      <span className="flex items-center gap-1.5 font-medium text-muted-foreground">
                        <Paperclip className="size-3" /> Required Attachment
                      </span>
                      <Switch
                        checked={Boolean(question.required_attachment)}
                        onCheckedChange={(v) => onUpdateQuestion?.(sectionIndex, questionIndex, { required_attachment: v })}
                      />
                    </label>
                  )}

                  {question.builder_type === 'comment_block' && (
                    <label className="flex items-center justify-between text-xs">
                      <span className="flex items-center gap-1.5 font-medium text-muted-foreground">
                        <MessageSquare className="size-3" /> Required Comment
                      </span>
                      <Switch
                        checked={Boolean(question.required_comment)}
                        onCheckedChange={(v) => onUpdateQuestion?.(sectionIndex, questionIndex, { required_comment: v })}
                      />
                    </label>
                  )}

                  {question.builder_type === 'signature' && (
                    <label className="flex items-center justify-between text-xs">
                      <span className="flex items-center gap-1.5 font-medium text-muted-foreground">
                        <PenLine className="size-3" /> Required Signature
                      </span>
                      <Switch
                        checked={Boolean(question.required_signature)}
                        onCheckedChange={(v) => onUpdateQuestion?.(sectionIndex, questionIndex, { required_signature: v })}
                      />
                    </label>
                  )}
                </div>
              </PropertiesSection>

              <PropertiesSection title="Scoring" icon={Weight}>
                <FieldRow label="Points / Weight">
                  <Input
                    type="number"
                    min="0"
                    max="1000"
                    value={question.weight || 0}
                    onChange={(e) => onUpdateQuestion?.(sectionIndex, questionIndex, { weight: Number(e.target.value) })}
                    className="h-8 rounded-md text-sm"
                  />
                </FieldRow>

                {showMaxValue && (
                  <FieldRow label="Maximum Value" className="mt-2">
                    <div className="flex items-center gap-2">
                      <Input
                        type="number"
                        min="1"
                        max="100"
                        value={question.max || 5}
                        onChange={(e) => onUpdateQuestion?.(sectionIndex, questionIndex, { max: Number(e.target.value) })}
                        className="h-8 rounded-md text-sm"
                      />
                      <span className="text-xs text-muted-foreground">
                        {question.builder_type === 'nps' ? '0-10' : `1-${question.max || 5}`}
                      </span>
                    </div>
                  </FieldRow>
                )}

                {question.builder_type === 'formula' && (
                  <FieldRow label="Scoring Rule" className="mt-2">
                    <Input
                      value={question.formula || ''}
                      onChange={(e) => onUpdateQuestion?.(sectionIndex, questionIndex, { formula: e.target.value })}
                      className="h-8 rounded-md text-sm font-mono"
                      placeholder="e.g. (Section1 + Section2) / 2"
                    />
                  </FieldRow>
                )}

                <label className="mt-2 flex items-center justify-between text-xs">
                  <span className="flex items-center gap-1.5 font-medium text-muted-foreground">
                    <Calculator className="size-3" /> Calculated
                  </span>
                  <Switch
                    checked={Boolean(question.calculated)}
                    onCheckedChange={(v) => onUpdateQuestion?.(sectionIndex, questionIndex, { calculated: v })}
                  />
                </label>
              </PropertiesSection>

              <PropertiesSection title="Options" icon={ListChecks}>
                {showOptions && (
                  <>
                    <FieldRow label="Choices / Options">
                      <OptionsEditor
                        options={question.options || []}
                        onChange={(options) => onUpdateQuestion?.(sectionIndex, questionIndex, { options })}
                      />
                    </FieldRow>
                    <FieldRow label="Default Value" className="mt-2">
                      <Input
                        value={question.default_value || ''}
                        onChange={(e) => onUpdateQuestion?.(sectionIndex, questionIndex, { default_value: e.target.value })}
                        className="h-8 rounded-md text-sm"
                        placeholder="Default selection"
                      />
                    </FieldRow>
                  </>
                )}

                {showMatrixRows && (
                  <>
                    <FieldRow label="Row Labels">
                      <MatrixRowsEditor
                        rows={question.rows || []}
                        onChange={(rows) => onUpdateQuestion?.(sectionIndex, questionIndex, { rows })}
                      />
                    </FieldRow>
                    <FieldRow label="Column Count" className="mt-2">
                      <Input
                        type="number"
                        min="2"
                        max="10"
                        value={question.max || 5}
                        onChange={(e) => onUpdateQuestion?.(sectionIndex, questionIndex, { max: Number(e.target.value) })}
                        className="h-8 rounded-md text-sm"
                      />
                    </FieldRow>
                  </>
                )}

                {showScoreColumns && (
                  <FieldRow label="Score Columns">
                    <ScoreTableColumnsEditor
                      columns={question.columns || []}
                      onChange={(columns) => onUpdateQuestion?.(sectionIndex, questionIndex, { columns })}
                    />
                  </FieldRow>
                )}

                {showSliderMarks && (
                  <>
                    <FieldRow label="Minimum">
                      <Input
                        type="number"
                        value={question.min ?? 0}
                        onChange={(e) => onUpdateQuestion?.(sectionIndex, questionIndex, { min: Number(e.target.value) })}
                        className="h-8 rounded-md text-sm"
                      />
                    </FieldRow>
                    <FieldRow label="Maximum" className="mt-2">
                      <Input
                        type="number"
                        value={question.max ?? 10}
                        onChange={(e) => onUpdateQuestion?.(sectionIndex, questionIndex, { max: Number(e.target.value) })}
                        className="h-8 rounded-md text-sm"
                      />
                    </FieldRow>
                    <FieldRow label="Step" className="mt-2">
                      <Input
                        type="number"
                        min="0.1"
                        step="0.1"
                        value={question.step ?? 1}
                        onChange={(e) => onUpdateQuestion?.(sectionIndex, questionIndex, { step: Number(e.target.value) })}
                        className="h-8 rounded-md text-sm"
                      />
                    </FieldRow>
                  </>
                )}

                {showNpsLabels && (
                  <>
                    <FieldRow label="Detractor Label">
                      <Input
                        value={question.detractor_label || 'Not Likely'}
                        onChange={(e) => onUpdateQuestion?.(sectionIndex, questionIndex, { detractor_label: e.target.value })}
                        className="h-8 rounded-md text-sm"
                      />
                    </FieldRow>
                    <FieldRow label="Promoter Label" className="mt-2">
                      <Input
                        value={question.promoter_label || 'Extremely Likely'}
                        onChange={(e) => onUpdateQuestion?.(sectionIndex, questionIndex, { promoter_label: e.target.value })}
                        className="h-8 rounded-md text-sm"
                      />
                    </FieldRow>
                  </>
                )}
              </PropertiesSection>

              <PropertiesSection title="Conditional Logic" icon={Eye}>
                <FieldRow label="Visible If">
                  <Input
                    value={question.visible_if || ''}
                    onChange={(e) => onUpdateQuestion?.(sectionIndex, questionIndex, { visible_if: e.target.value })}
                    className="h-8 rounded-md text-sm font-mono"
                    placeholder="e.g. question_1 == 'Yes'"
                  />
                </FieldRow>
                <FieldRow label="Hidden If" className="mt-2">
                  <Input
                    value={question.hidden_if || ''}
                    onChange={(e) => onUpdateQuestion?.(sectionIndex, questionIndex, { hidden_if: e.target.value })}
                    className="h-8 rounded-md text-sm font-mono"
                    placeholder="e.g. department != 'IT'"
                  />
                </FieldRow>
              </PropertiesSection>

              <PropertiesSection title="Validation" icon={HelpCircle}>
                {['number', 'percentage', 'currency'].includes(question.builder_type) && (
                  <>
                    <FieldRow label="Min Value">
                      <Input
                        type="number"
                        value={question.min ?? ''}
                        onChange={(e) => onUpdateQuestion?.(sectionIndex, questionIndex, { min: e.target.value ? Number(e.target.value) : null })}
                        className="h-8 rounded-md text-sm"
                      />
                    </FieldRow>
                    <FieldRow label="Max Value" className="mt-2">
                      <Input
                        type="number"
                        value={question.max ?? ''}
                        onChange={(e) => onUpdateQuestion?.(sectionIndex, questionIndex, { max: e.target.value ? Number(e.target.value) : null })}
                        className="h-8 rounded-md text-sm"
                      />
                    </FieldRow>
                  </>
                )}
                {question.builder_type === 'short_answer' && (
                  <>
                    <FieldRow label="Max Characters">
                      <Input
                        type="number"
                        min="0"
                        value={question.max_chars ?? ''}
                        onChange={(e) => onUpdateQuestion?.(sectionIndex, questionIndex, { max_chars: e.target.value ? Number(e.target.value) : null })}
                        className="h-8 rounded-md text-sm"
                      />
                    </FieldRow>
                  </>
                )}
                {question.builder_type === 'file_upload' && (
                  <>
                    <FieldRow label="Allowed File Types">
                      <Input
                        value={question.allowed_file_types || ''}
                        onChange={(e) => onUpdateQuestion?.(sectionIndex, questionIndex, { allowed_file_types: e.target.value })}
                        className="h-8 rounded-md text-sm"
                        placeholder="e.g. .pdf,.doc,.docx"
                      />
                    </FieldRow>
                    <FieldRow label="Max File Size (MB)" className="mt-2">
                      <Input
                        type="number"
                        min="1"
                        value={question.max_file_size ?? 10}
                        onChange={(e) => onUpdateQuestion?.(sectionIndex, questionIndex, { max_file_size: Number(e.target.value) })}
                        className="h-8 rounded-md text-sm"
                      />
                    </FieldRow>
                  </>
                )}
              </PropertiesSection>
            </>
          )}

          {/* ─── Section Properties ─── */}
          {section && !question && (
            <>
              <div className="mb-1 flex items-center gap-2">
                <span className="flex size-7 items-center justify-center rounded-md bg-brand/10 text-brand">
                  <ListChecks className="size-3.5" />
                </span>
                <div>
                  <p className="text-sm font-semibold text-foreground">Section Properties</p>
                  <p className="text-[10px] text-muted-foreground">Section {sectionIndex !== undefined ? `#${sectionIndex + 1}` : ''}</p>
                </div>
              </div>

              <PropertiesSection title="Section" icon={FileText}>
                <FieldRow label="Section Title">
                  <Input
                    value={section.title || ''}
                    onChange={(e) => onUpdateSection?.(sectionIndex, { title: e.target.value })}
                    className="h-8 rounded-md text-sm"
                  />
                </FieldRow>
                <FieldRow label="Description" className="mt-2">
                  <InlineRichTextEditor
                    content={section.description || ''}
                    onChange={(html) => onUpdateSection?.(sectionIndex, { description: html })}
                    placeholder="Section description (supports rich text)"
                    minHeight="4rem"
                    compact
                  />
                </FieldRow>
              </PropertiesSection>

              <PropertiesSection title="Scoring" icon={Weight}>
                <FieldRow label="Section Weight (%)">
                  <div className="flex items-center gap-2">
                    <Input
                      type="number"
                      min="0"
                      max="100"
                      value={section.weight || 0}
                      onChange={(e) => onUpdateSection?.(sectionIndex, { weight: Number(e.target.value) })}
                      className="h-8 rounded-md text-sm"
                    />
                    <span className="text-xs text-muted-foreground">%</span>
                  </div>
                </FieldRow>
              </PropertiesSection>
            </>
          )}

          {/* Spacer for scrolling */}
          <div className="h-8" />
        </div>
      </ScrollArea>
    </div>
  )
}
