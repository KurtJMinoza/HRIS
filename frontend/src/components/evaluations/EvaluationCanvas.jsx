/* eslint-disable react-refresh/only-export-components */
import { useMemo, useCallback } from 'react'
import {
  useDroppable,
  useDraggable,
  DndContext,
  closestCenter,
  PointerSensor,
  useSensor,
  useSensors,
  DragOverlay,
} from '@dnd-kit/core'
import {
  SortableContext,
  verticalListSortingStrategy,
  useSortable,
  arrayMove,
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import InlineRichTextEditor from './InlineRichTextEditor'
import RichTextContent from './RichTextContent'
import { Switch } from '@/components/ui/switch'
import {
  GripVertical,
  Plus,
  Trash2,
  Copy,
  MoveUp,
  MoveDown,
  Star,
  StarHalf,
  ListChecks,
  ThumbsUp,
  ThumbsDown,
  SlidersHorizontal,
  Smile,
  HelpCircle,
  Grid3X3,
  Table2,
  FunctionSquare,
  MessageSquare,
  PenLine,
  CheckCircle2,
  XCircle,
  Hash,
  Percent,
  DollarSign,
  Calendar,
  Clock,
  Mail,
  Phone,
  Paperclip,
  User,
  Building2,
  FileText,
  BookOpen,
  Heading,
  Minus,
  AlignLeft,
  LayoutGrid,
  Type,
} from 'lucide-react'
import { QUESTION_TYPE_MAP } from './QuestionLibraryPanel'

// Default Likert options
const LIKERT_OPTIONS = [
  'Strongly Disagree',
  'Disagree',
  'Neutral',
  'Agree',
  'Strongly Agree',
]

const DEFAULT_OPTIONS = {
  yes_no: ['Yes', 'No'],
  pass_fail: ['Pass', 'Fail'],
  thumbs: ['👍', '👎'],
  nps: Array.from({ length: 11 }, (_, i) => String(i)),
  rating: ['1', '2', '3', '4', '5'],
  rating_10: ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10'],
  emoji: ['😡', '😟', '😐', '🙂', '😍'],
}

// ─── Sortable Question Item ─────────────────────────────────────────

function QuestionItem({ question, questionIndex, sectionIndex, onUpdate, onDelete, onDuplicate, onMoveUp, onMoveDown, onSelect, isSelected }) {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({
    id: `q-${sectionIndex}-${questionIndex}`,
    data: { type: 'question', sectionIndex, questionIndex },
  })

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
  }

  const def = QUESTION_TYPE_MAP[question.builder_type]
  const Icon = def?.icon || Star

  const typeLabel = def?.label || question.builder_type?.replace(/_/g, ' ') || 'Question'

  const renderPreview = () => {
    switch (question.builder_type) {
      case 'rating':
      case 'rating_10':
        return (
          <div className="flex items-center gap-1.5">
            {Array.from({ length: Math.min(question.max || 5, 5) }, (_, i) => (
              <Star key={i} className="size-5 text-amber-400 fill-amber-400" />
            ))}
            <span className="ml-1 text-xs text-muted-foreground">1–{question.max || 5}</span>
          </div>
        )
      case 'star_rating':
        return (
          <div className="flex items-center gap-1">
            {[1, 2, 3, 4, 5].map(i => (
              <Star key={i} className="size-6 text-amber-400 fill-amber-400" />
            ))}
          </div>
        )
      case 'nps':
        return (
          <div className="flex items-center gap-1">
            {[0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10].map(i => (
              <span key={i} className="flex size-6 items-center justify-center rounded-md border border-border/60 bg-muted/30 text-[10px] font-bold text-muted-foreground">{i}</span>
            ))}
          </div>
        )
      case 'likert':
        return (
          <div className="flex items-center gap-2">
            {LIKERT_OPTIONS.map(opt => (
              <span key={opt} className="rounded-md border border-border/60 bg-muted/30 px-2 py-1 text-[10px] text-muted-foreground">{opt}</span>
            ))}
          </div>
        )
      case 'multiple_choice':
      case 'checkbox':
        return (
          <div className="flex flex-wrap gap-1.5">
            {(question.options || ['Option 1', 'Option 2', 'Option 3']).slice(0, 3).map((opt, i) => (
              <span key={i} className="rounded-md border border-border/60 bg-muted/30 px-2.5 py-1 text-[10px] text-muted-foreground">
                {question.builder_type === 'checkbox' ? '☐' : '○'} {opt}
              </span>
            ))}
            {(question.options || []).length > 3 && (
              <Badge variant="outline" className="rounded-md text-[10px]">+{question.options.length - 3}</Badge>
            )}
          </div>
        )
      case 'matrix':
        return (
          <div className="overflow-hidden rounded-md border border-border/60">
            <table className="w-full text-[10px]">
              <thead>
                <tr className="bg-muted/30">
                  <th className="p-1.5 text-left font-medium text-muted-foreground">Criteria</th>
                  {[1, 2, 3, 4, 5].map(i => <th key={i} className="p-1.5 text-center font-medium text-muted-foreground w-8">{i}</th>)}
                </tr>
              </thead>
              <tbody>
                {(question.rows || ['Row 1', 'Row 2']).slice(0, 2).map((row, i) => (
                  <tr key={i} className="border-t border-border/40">
                    <td className="p-1.5 text-left text-muted-foreground">{row}</td>
                    {[1, 2, 3, 4, 5].map(j => (
                      <td key={j} className="p-1.5 text-center">
                        <span className="inline-flex size-4 items-center justify-center rounded-full border border-border/60" />
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      case 'yes_no':
        return (
          <div className="flex items-center gap-2">
            <span className="inline-flex items-center gap-1 rounded-md border border-emerald-500/30 bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
              <CheckCircle2 className="size-3.5" /> Yes
            </span>
            <span className="inline-flex items-center gap-1 rounded-md border border-red-500/30 bg-red-50 px-2.5 py-1 text-[11px] font-semibold text-red-700 dark:bg-red-500/10 dark:text-red-400">
              <XCircle className="size-3.5" /> No
            </span>
          </div>
        )
      case 'thumbs':
        return (
          <div className="flex items-center gap-2">
            <span className="rounded-md border border-emerald-500/30 bg-emerald-50 px-2 py-1 dark:bg-emerald-500/10">
              <ThumbsUp className="size-4 text-emerald-600 dark:text-emerald-400" />
            </span>
            <span className="rounded-md border border-red-500/30 bg-red-50 px-2 py-1 dark:bg-red-500/10">
              <ThumbsDown className="size-4 text-red-600 dark:text-red-400" />
            </span>
          </div>
        )
      case 'slider':
        return (
          <div className="relative h-4 rounded-full bg-muted">
            <div className="absolute left-1/4 top-0 h-full w-1/2 rounded-full bg-brand/50" />
          </div>
        )
      case 'emoji':
        return (
          <div className="flex items-center gap-1">
            {['😡', '😟', '😐', '🙂', '😍'].map((e, i) => (
              <span key={i} className="text-lg">{e}</span>
            ))}
          </div>
        )
      case 'short_answer':
        return <div className="h-7 w-full max-w-xs rounded-md border border-border/60 bg-muted/20 px-2" />
      case 'long_answer':
        return <div className="h-14 w-full max-w-sm rounded-md border border-border/60 bg-muted/20" />
      case 'number':
      case 'percentage':
      case 'currency':
      case 'email':
      case 'phone':
        return <div className="h-7 w-32 rounded-md border border-border/60 bg-muted/20 px-2" />
      case 'date':
        return <div className="flex items-center gap-2"><Calendar className="size-3.5 text-muted-foreground" /><span className="text-xs text-muted-foreground">Pick a date...</span></div>
      case 'time':
        return <div className="flex items-center gap-2"><Clock className="size-3.5 text-muted-foreground" /><span className="text-xs text-muted-foreground">Select time...</span></div>
      case 'file_upload':
        return <div className="flex items-center gap-2 rounded-md border border-dashed border-border/60 p-2 text-xs text-muted-foreground"><Paperclip className="size-3.5" /> Click to upload file</div>
      case 'signature':
        return <div className="h-12 rounded-md border border-dashed border-border/60 bg-muted/10" />
      case 'formula':
        return <div className="flex items-center gap-2 rounded-md border border-blue-500/30 bg-blue-50 p-2 text-xs font-mono text-blue-700 dark:bg-blue-500/10 dark:text-blue-400"><FunctionSquare className="size-3.5" /> = (Section1 × 0.7) + (Section2 × 0.3)</div>
      case 'score_table':
        return <div className="overflow-hidden rounded-md border border-border/60 text-[10px]"><table className="w-full"><thead><tr className="bg-muted/30"><th className="p-1.5 text-left">Criterion</th><th className="p-1.5 text-center">Score</th><th className="p-1.5 text-center">Weight</th><th className="p-1.5 text-center">Total</th></tr></thead><tbody><tr className="border-t border-border/40"><td className="p-1.5 text-muted-foreground">—</td><td className="p-1.5 text-center text-muted-foreground">—</td><td className="p-1.5 text-center text-muted-foreground">—</td><td className="p-1.5 text-center text-muted-foreground">—</td></tr></tbody></table></div>
      case 'section':
        return <div className="flex items-center gap-2 text-sm font-bold text-foreground"><Heading className="size-4 text-brand" /> Section Heading</div>
      case 'title':
        return <div className="text-lg font-black text-foreground">Title Text</div>
      case 'paragraph':
      case 'instruction':
        return <div className="text-xs leading-relaxed text-muted-foreground">Descriptive text or instruction for the respondent...</div>
      case 'divider':
        return <div className="border-t border-border/60" />
      case 'employee_lookup':
      case 'manager_lookup':
        return <div className="flex items-center gap-2 rounded-md border border-border/60 p-2 text-xs text-muted-foreground"><User className="size-3.5" /> Search and select...</div>
      case 'comment_block':
        return <div className="rounded-md border border-border/60 bg-muted/15 p-3 text-xs text-muted-foreground"><MessageSquare className="size-3.5 mb-1" /> Comment section</div>
      default:
        return <div className="h-7 w-full rounded-md border border-border/60 bg-muted/20" />
    }
  }

  return (
    <div
      ref={setNodeRef}
      style={style}
      className={cn(
        'group relative rounded-lg border bg-card transition-all',
        isDragging ? 'z-50 opacity-80 shadow-lg ring-2 ring-brand/20' : 'hover:border-border',
        isSelected ? 'border-brand/50 ring-1 ring-brand/20 shadow-sm' : 'border-border/70',
      )}
      onClick={() => onSelect?.(sectionIndex, questionIndex)}
    >
      <div className="flex items-start gap-2 p-3">
        {/* Drag Handle */}
        <button
          type="button"
          className="mt-1 cursor-grab touch-none text-muted-foreground/40 opacity-0 transition-opacity hover:text-muted-foreground group-hover:opacity-100"
          {...attributes}
          {...listeners}
        >
          <GripVertical className="size-4" />
        </button>

        {/* Question Content */}
        <div className="min-w-0 flex-1 space-y-2">
          <div className="flex items-center gap-2">
            <span className="flex size-6 shrink-0 items-center justify-center rounded-md bg-brand/10 text-brand">
              <Icon className="size-3.5" />
            </span>
            <span className="rounded-md bg-muted px-2 py-0.5 text-[10px] font-semibold text-muted-foreground">{typeLabel}</span>
            {question.required && (
              <Badge variant="outline" className="rounded-md text-[9px] px-1.5 py-0 text-red-500 border-red-500/30 bg-red-500/5">Required</Badge>
            )}
            {question.weight > 0 && (
              <Badge variant="outline" className="rounded-md text-[9px] px-1.5 py-0">{question.weight} pts</Badge>
            )}
          </div>

          {/* Question Title */}
          <Input
            value={question.title || ''}
            onChange={(e) => onUpdate?.(sectionIndex, questionIndex, { title: e.target.value })}
            placeholder="Enter your question..."
            className="h-8 rounded-md border-border/50 bg-transparent text-sm font-medium placeholder:text-muted-foreground/50"
            onClick={(e) => e.stopPropagation()}
          />

          {/* Question Preview */}
          <div className="pointer-events-none">{renderPreview()}</div>

          {/* Description */}
          {question.description && (
            <RichTextContent
              content={question.description}
              className="text-[11px] leading-relaxed text-muted-foreground"
            />
          )}
        </div>

        {/* Actions (appear on hover) */}
        <div className="flex shrink-0 items-center gap-0.5 opacity-0 transition-opacity group-hover:opacity-100">
          <Button type="button" variant="ghost" size="icon-sm" className="size-7" onClick={(e) => { e.stopPropagation(); onMoveUp?.(sectionIndex, questionIndex) }}>
            <MoveUp className="size-3.5" />
          </Button>
          <Button type="button" variant="ghost" size="icon-sm" className="size-7" onClick={(e) => { e.stopPropagation(); onMoveDown?.(sectionIndex, questionIndex) }}>
            <MoveDown className="size-3.5" />
          </Button>
          <Button type="button" variant="ghost" size="icon-sm" className="size-7" onClick={(e) => { e.stopPropagation(); onDuplicate?.(sectionIndex, questionIndex) }}>
            <Copy className="size-3.5" />
          </Button>
          <Button type="button" variant="ghost" size="icon-sm" className="size-7 text-destructive" onClick={(e) => { e.stopPropagation(); onDelete?.(sectionIndex, questionIndex) }}>
            <Trash2 className="size-3.5" />
          </Button>
        </div>
      </div>
    </div>
  )
}

// ─── Sortable Section Item ──────────────────────────────────────────

function SectionItem({ section, sectionIndex, onUpdate, onAddQuestion, onDuplicateSection, onDeleteSection, questions, selectedQuestion, onSelectQuestion }) {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({
    id: `section-${sectionIndex}`,
    data: { type: 'section', sectionIndex },
  })

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
  }

  const questionIds = questions.map((_, qIdx) => `q-${sectionIndex}-${qIdx}`)

  const handleDragEnd = useCallback((event) => {
    const { active, over } = event
    if (!over || active.id === over.id) return
    const oldIndex = questionIds.indexOf(active.id)
    const newIndex = questionIds.indexOf(over.id)
    if (oldIndex !== -1 && newIndex !== -1) {
      const newQuestions = arrayMove(questions, oldIndex, newIndex)
      onUpdate?.(sectionIndex, { questions: newQuestions })
    }
  }, [questionIds, questions, sectionIndex, onUpdate])

  return (
    <div
      ref={setNodeRef}
      style={style}
      className={cn(
        'rounded-xl border bg-card shadow-sm transition-all',
        isDragging ? 'z-50 opacity-70 shadow-lg ring-2 ring-brand/20' : 'border-border/70',
        section.archived && 'border-dashed opacity-60',
      )}
    >
      {/* Section Header */}
      <div className="flex items-center gap-2 border-b border-border/50 bg-muted/20 px-4 py-3">
        <button
          type="button"
          className="cursor-grab touch-none text-muted-foreground/40 opacity-0 transition-opacity hover:text-muted-foreground group-hover:opacity-100"
          {...attributes}
          {...listeners}
        >
          <GripVertical className="size-4" />
        </button>
        <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-brand/10 text-xs font-bold text-brand">{sectionIndex + 1}</span>
        <Input
          value={section.title}
          onChange={(e) => onUpdate?.(sectionIndex, { title: e.target.value })}
          className="h-8 min-w-[10rem] flex-1 rounded-md border-border/50 text-sm font-semibold"
          placeholder="Section title"
        />
        <div className="flex items-center gap-1.5 rounded-md border border-border/60 bg-background px-2.5 py-1">
          <span className="text-[10px] text-muted-foreground">Weight</span>
          <Input
            type="number"
            min="0"
            max="100"
            value={section.weight}
            onChange={(e) => onUpdate?.(sectionIndex, { weight: Number(e.target.value) })}
            className="h-7 w-14 border-0 bg-transparent p-0 text-right text-sm font-bold focus-visible:ring-0"
          />
          <span className="text-[10px] text-muted-foreground">%</span>
        </div>
        <div className="flex items-center gap-0.5">
          <Button type="button" variant="ghost" size="icon-sm" className="size-7" onClick={() => onDuplicateSection?.(sectionIndex)}>
            <Copy className="size-3.5" />
          </Button>
          <Button type="button" variant="ghost" size="icon-sm" className="size-7 text-destructive" onClick={() => onDeleteSection?.(sectionIndex)}>
            <Trash2 className="size-3.5" />
          </Button>
        </div>
      </div>

      {/* Section Description */}
      <div className="px-4 pt-3">
        <InlineRichTextEditor
          content={section.description || ''}
          onChange={(html) => onUpdate?.(sectionIndex, { description: html })}
          placeholder="Section description (optional — supports rich text)"
          minHeight="2.5rem"
          compact
          className="mb-0"
        />
      </div>

      {/* Questions Area */}
      <div className="space-y-2 p-3">
        {questions.length === 0 ? (
          <div className="flex flex-col items-center justify-center rounded-lg border border-dashed border-border/50 bg-muted/10 px-4 py-6 text-center">
            <p className="text-xs text-muted-foreground">No questions yet. Drag questions from the library or click Add.</p>
          </div>
        ) : (
          <DndContext
            sensors={useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 8 } }))}
            collisionDetection={closestCenter}
            onDragEnd={handleDragEnd}
          >
            <SortableContext items={questionIds} strategy={verticalListSortingStrategy}>
              <div className="space-y-2">
                {questions.map((question, qIdx) => (
                  <QuestionItem
                    key={`q-${sectionIndex}-${qIdx}`}
                    question={question}
                    questionIndex={qIdx}
                    sectionIndex={sectionIndex}
                    onUpdate={onUpdate}
                    onDelete={(sIdx, qIdx) => {
                      const filtered = questions.filter((_, i) => i !== qIdx)
                      onUpdate?.(sIdx, { questions: filtered })
                    }}
                    onDuplicate={(sIdx, qIdx) => {
                      const newQuestions = [...questions]
                      newQuestions.splice(qIdx + 1, 0, { ...JSON.parse(JSON.stringify(questions[qIdx])) })
                      onUpdate?.(sIdx, { questions: newQuestions })
                    }}
                    onMoveUp={(sIdx, qIdx) => {
                      if (qIdx <= 0) return
                      const newQuestions = arrayMove(questions, qIdx, qIdx - 1)
                      onUpdate?.(sIdx, { questions: newQuestions })
                    }}
                    onMoveDown={(sIdx, qIdx) => {
                      if (qIdx >= questions.length - 1) return
                      const newQuestions = arrayMove(questions, qIdx, qIdx + 1)
                      onUpdate?.(sIdx, { questions: newQuestions })
                    }}
                    onSelect={onSelectQuestion}
                    isSelected={selectedQuestion?.sectionIndex === sectionIndex && selectedQuestion?.questionIndex === qIdx}
                  />
                ))}
              </div>
            </SortableContext>
          </DndContext>
        )}

        {/* Add Question Button */}
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="h-8 w-full rounded-lg border border-dashed border-border/60 text-xs text-muted-foreground hover:border-brand/40 hover:text-brand"
          onClick={() => {
            const newQuestions = [...questions, {
              title: '',
              builder_type: 'rating',
              type: 'rating',
              max: 5,
              required: false,
              weight: 0,
              description: '',
              options: [],
            }]
            onUpdate?.(sectionIndex, { questions: newQuestions })
          }}
        >
          <Plus className="size-3.5 mr-1" />
          Add Question
        </Button>
      </div>
    </div>
  )
}

// ─── Question Drop Zone for NEW questions ───────────────────────────

function CanvasDropZone({ id, children, className }) {
  const { setNodeRef, isOver } = useDroppable({ id, data: { type: 'canvas' } })

  return (
    <div
      ref={setNodeRef}
      className={cn(
        'transition-all duration-150',
        isOver && 'bg-brand/5 ring-2 ring-brand/30 ring-dashed rounded-xl',
        className,
      )}
    >
      {children}
    </div>
  )
}

// ─── Main Evaluation Canvas ─────────────────────────────────────────

export default function EvaluationCanvas({
  sections = [],
  onUpdateSection,
  onAddSection,
  onDuplicateSection,
  onDeleteSection,
  onSelectQuestion,
  selectedQuestion,
  totalWeight,
  className,
}) {
  const sectionIds = sections.map((_, idx) => `section-${idx}`)

  const handleDragEnd = useCallback((event) => {
    const { active, over } = event
    if (!over || active.id === over.id) return
    const oldIndex = sectionIds.indexOf(active.id)
    const newIndex = sectionIds.indexOf(over.id)
    if (oldIndex !== -1 && newIndex !== -1) {
      const newSections = arrayMove(sections, oldIndex, newIndex)
      // Propagate the new order
      newSections.forEach((section, idx) => {
        onUpdateSection(idx, section)
      })
    }
  }, [sectionIds, sections, onUpdateSection])

  return (
    <div className={cn('flex flex-col', className)}>
      {/* Canvas Header */}
      <div className="flex items-center justify-between border-b border-border/50 px-4 py-2.5">
        <div className="flex items-center gap-2">
          <h3 className="text-sm font-bold text-foreground">Builder Canvas</h3>
          <Badge variant="outline" className="rounded-md text-[10px]">{sections.length} sections</Badge>
          <Badge
            variant="outline"
            className={cn(
              'rounded-md text-[10px]',
              totalWeight === 100 ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700' : 'border-amber-500/30 bg-amber-500/10 text-amber-700',
            )}
          >
            {totalWeight}%
          </Badge>
        </div>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8 gap-1 rounded-lg border-brand/35 text-xs text-brand hover:bg-brand/10"
          onClick={onAddSection}
        >
          <Plus className="size-3.5" />
          Add Section
        </Button>
      </div>

      {/* Canvas Content */}
      <CanvasDropZone id="canvas-drop-zone" className="flex-1">
        {sections.length === 0 ? (
          <div className="flex min-h-[400px] flex-col items-center justify-center px-6 py-16 text-center">
            <div className="mb-4 flex size-16 items-center justify-center rounded-full bg-brand/10 text-brand">
              <LayoutGrid className="size-8" strokeWidth={1.5} />
            </div>
            <p className="text-base font-semibold text-foreground">Start building your evaluation form</p>
            <p className="mt-1.5 max-w-xs text-sm text-muted-foreground">
              Drag question components from the library on the left, or add a section to begin.
            </p>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="mt-4 gap-1.5 rounded-lg"
              onClick={onAddSection}
            >
              <Plus className="size-4" />
              Add First Section
            </Button>
          </div>
        ) : (
          <DndContext
            sensors={useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 8 } }))}
            collisionDetection={closestCenter}
            onDragEnd={handleDragEnd}
          >
            <SortableContext items={sectionIds} strategy={verticalListSortingStrategy}>
              <div className="space-y-3 p-3">
                {sections.map((section, idx) => (
                  <SectionItem
                    key={`section-${idx}`}
                    section={section}
                    sectionIndex={idx}
                    questions={section.questions || []}
                    onUpdate={onUpdateSection}
                    onAddQuestion={(sIdx) => {
                      const sec = sections[sIdx]
                      const newQuestions = [...(sec.questions || []), {
                        title: '',
                        builder_type: 'rating',
                        type: 'rating',
                        max: 5,
                        required: false,
                        weight: 0,
                        description: '',
                        options: [],
                      }]
                      onUpdateSection(sIdx, { questions: newQuestions })
                    }}
                    onDuplicateSection={(sIdx) => {
                      const newSection = JSON.parse(JSON.stringify(sections[sIdx]))
                      newSection.title = `${newSection.title || 'Section'} (Copy)`
                      const result = [...sections]
                      result.splice(sIdx + 1, 0, newSection)
                      result.forEach((sec, idx) => onUpdateSection(idx, sec))
                    }}
                    onDeleteSection={(sIdx) => {
                      const result = sections.filter((_, i) => i !== sIdx)
                      result.forEach((sec, idx) => onUpdateSection(idx, sec))
                    }}
                    onSelectQuestion={onSelectQuestion}
                    selectedQuestion={selectedQuestion}
                  />
                ))}
              </div>
            </SortableContext>
          </DndContext>
        )}
      </CanvasDropZone>
    </div>
  )
}
