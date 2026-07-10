/* eslint-disable react-refresh/only-export-components */
import { useState, useMemo } from 'react'
import { useDraggable } from '@dnd-kit/core'
import { cn } from '@/lib/utils'
import {
  Search,
  GripVertical,
  Type,
  AlignLeft,
  List,
  ListChecks,
  Star,
  SlidersHorizontal,
  ThumbsUp,
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
  PenLine,
  User,
  Building2,
  FileText,
  FunctionSquare,
  Heading,
  Minus,
  BookOpen,
  Table2,
  Grid3X3,
  MessageSquare,
  Smile,
  LayoutGrid,
  HelpCircle,
} from 'lucide-react'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
// ScrollArea fallback: using a simple scrollable div
function ScrollArea({ children, className }) {
  return <div className={cn('overflow-y-auto', className)}>{children}</div>
}

// ─── Question Type Definitions ───────────────────────────────────────

export const QUESTION_TYPE_DEFINITIONS = [
  { id: 'section', label: 'Section', icon: Heading, category: 'structure', description: 'A section heading with weight' },
  { id: 'subsection', label: 'Subsection', icon: Heading, category: 'structure', description: 'A subsection divider' },
  { id: 'title', label: 'Title', icon: Type, category: 'structure', description: 'Large text title block' },
  { id: 'paragraph', label: 'Paragraph', icon: AlignLeft, category: 'structure', description: 'Descriptive text block' },
  { id: 'instruction', label: 'Instruction', icon: BookOpen, category: 'structure', description: 'Instructional text for respondents' },
  { id: 'divider', label: 'Divider', icon: Minus, category: 'structure', description: 'Horizontal separator line' },

  { id: 'rating', label: 'Rating (1-5)', icon: Star, category: 'rating', description: 'Classic 1-5 star rating' },
  { id: 'rating_10', label: 'Rating (1-10)', icon: Star, category: 'rating', description: 'Extended 1-10 rating scale' },
  { id: 'star_rating', label: 'Star Rating', icon: Star, category: 'rating', description: 'Visual star selection' },
  { id: 'nps', label: 'NPS Score', icon: HelpCircle, category: 'rating', description: 'Net Promoter Score (0-10)' },
  { id: 'slider', label: 'Slider', icon: SlidersHorizontal, category: 'rating', description: 'Interactive slider control' },
  { id: 'emoji', label: 'Emoji Rating', icon: Smile, category: 'rating', description: 'Emoji-based satisfaction' },
  { id: 'thumbs', label: 'Thumbs Up/Down', icon: ThumbsUp, category: 'rating', description: 'Binary thumbs rating' },
  { id: 'yes_no', label: 'Yes/No', icon: CheckCircle2, category: 'choice', description: 'Simple yes or no' },
  { id: 'pass_fail', label: 'Pass/Fail', icon: XCircle, category: 'choice', description: 'Pass or fail assessment' },

  { id: 'likert', label: 'Likert Scale', icon: LayoutGrid, category: 'choice', description: 'Agreement scale (Strongly Disagree - Strongly Agree)' },
  { id: 'multiple_choice', label: 'Multiple Choice', icon: List, category: 'choice', description: 'Single selection from options' },
  { id: 'checkbox', label: 'Checkboxes', icon: ListChecks, category: 'choice', description: 'Multi-select from options' },
  { id: 'dropdown', label: 'Dropdown', icon: List, category: 'choice', description: 'Dropdown selection list' },
  { id: 'matrix', label: 'Matrix/Rating Scale', icon: Grid3X3, category: 'rating', description: 'Multi-row rating grid' },
  { id: 'score_table', label: 'Score Table', icon: Table2, category: 'rating', description: 'Weighted scoring table' },

  { id: 'short_answer', label: 'Short Answer', icon: PenLine, category: 'text', description: 'Single line text input' },
  { id: 'long_answer', label: 'Long Answer', icon: AlignLeft, category: 'text', description: 'Multi-line text area' },
  { id: 'rich_text', label: 'Rich Text', icon: FileText, category: 'text', description: 'Formatted text editor' },
  { id: 'number', label: 'Numeric', icon: Hash, category: 'text', description: 'Number input with validation' },
  { id: 'percentage', label: 'Percentage', icon: Percent, category: 'text', description: 'Percentage input (0-100)' },
  { id: 'currency', label: 'Currency', icon: DollarSign, category: 'text', description: 'Monetary value input' },
  { id: 'email', label: 'Email', icon: Mail, category: 'text', description: 'Email address input' },
  { id: 'phone', label: 'Phone', icon: Phone, category: 'text', description: 'Phone number input' },
  { id: 'date', label: 'Date', icon: Calendar, category: 'text', description: 'Date picker' },
  { id: 'time', label: 'Time', icon: Clock, category: 'text', description: 'Time picker' },

  { id: 'file_upload', label: 'File Upload', icon: Paperclip, category: 'media', description: 'Attachment upload' },
  { id: 'signature', label: 'Signature', icon: PenLine, category: 'media', description: 'Digital signature pad' },
  { id: 'image', label: 'Image', icon: FileText, category: 'media', description: 'Display an image' },
  { id: 'video', label: 'Video', icon: FileText, category: 'media', description: 'Embed a video' },
  { id: 'pdf_viewer', label: 'PDF Viewer', icon: FileText, category: 'media', description: 'Embed a PDF document' },

  { id: 'employee_lookup', label: 'Employee Lookup', icon: User, category: 'lookup', description: 'Search and select employee' },
  { id: 'manager_lookup', label: 'Manager Lookup', icon: User, category: 'lookup', description: 'Search and select manager' },
  { id: 'department_lookup', label: 'Department Lookup', icon: Building2, category: 'lookup', description: 'Search and select department' },
  { id: 'branch_lookup', label: 'Branch Lookup', icon: Building2, category: 'lookup', description: 'Search and select branch' },
  { id: 'company_lookup', label: 'Company Lookup', icon: Building2, category: 'lookup', description: 'Search and select company' },

  { id: 'formula', label: 'Computed Formula', icon: FunctionSquare, category: 'advanced', description: 'Power BI-style formula' },
  { id: 'signature_block', label: 'Signature Block', icon: PenLine, category: 'advanced', description: 'Multi-party signature block' },
  { id: 'comment_block', label: 'Comment Block', icon: MessageSquare, category: 'advanced', description: 'Structured comment section' },
]

const CATEGORIES = [
  { id: 'structure', label: 'Structure', icon: Type },
  { id: 'rating', label: 'Rating & Scale', icon: Star },
  { id: 'choice', label: 'Choice', icon: List },
  { id: 'text', label: 'Text & Input', icon: PenLine },
  { id: 'media', label: 'Media & Files', icon: Paperclip },
  { id: 'lookup', label: 'Lookup', icon: User },
  { id: 'advanced', label: 'Advanced', icon: FunctionSquare },
]

// ─── Draggable Question Item ────────────────────────────────────────

function DraggableQuestionItem({ definition }) {
  const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({
    id: `palette-${definition.id}`,
    data: { type: 'new_question', questionType: definition.id },
  })

  const style = transform ? {
    transform: `translate3d(${transform.x}px, ${transform.y}px, 0)`,
    zIndex: 999,
  } : undefined

  const Icon = definition.icon

  return (
    <div
      ref={setNodeRef}
      style={style}
      {...listeners}
      {...attributes}
      className={cn(
        'flex cursor-grab items-center gap-2.5 rounded-lg border border-border/60 bg-card px-3 py-2.5 text-sm transition-all hover:border-brand/40 hover:bg-brand/5 hover:shadow-sm active:cursor-grabbing',
        isDragging && 'opacity-50 shadow-lg ring-2 ring-brand/30',
      )}
    >
      <GripVertical className="size-3.5 shrink-0 text-muted-foreground/60" />
      <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-brand/10 text-brand">
        <Icon className="size-3.5" />
      </span>
      <div className="min-w-0 flex-1">
        <p className="truncate text-xs font-semibold text-foreground">{definition.label}</p>
        <p className="truncate text-[10px] text-muted-foreground">{definition.description}</p>
      </div>
    </div>
  )
}

// ─── Question Library Panel ─────────────────────────────────────────

export default function QuestionLibraryPanel({ className }) {
  const [search, setSearch] = useState('')
  const [activeCategory, setActiveCategory] = useState(null)

  const filteredDefinitions = useMemo(() => {
    let items = QUESTION_TYPE_DEFINITIONS
    if (activeCategory) {
      items = items.filter(d => d.category === activeCategory)
    }
    if (search.trim()) {
      const q = search.toLowerCase()
      items = items.filter(d =>
        d.label.toLowerCase().includes(q) ||
        d.description.toLowerCase().includes(q)
      )
    }
    return items
  }, [search, activeCategory])

  return (
    <div className={cn('flex flex-col overflow-hidden', className)}>
      {/* Search */}
      <div className="shrink-0 border-b border-border/50 p-3">
        <div className="relative">
          <Search className="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
          <Input
            type="search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search components..."
            className="h-8 rounded-lg pl-8 text-xs"
          />
        </div>
      </div>

      {/* Category Pills */}
      <div className="shrink-0 flex flex-wrap gap-1 border-b border-border/50 p-2">
        {CATEGORIES.map(cat => {
          const Icon = cat.icon
          const isActive = activeCategory === cat.id
          return (
            <button
              key={cat.id}
              type="button"
              onClick={() => setActiveCategory(isActive ? null : cat.id)}
              className={cn(
                'inline-flex items-center gap-1 rounded-md px-2 py-1 text-[10px] font-semibold transition',
                isActive
                  ? 'bg-brand text-brand-foreground'
                  : 'bg-muted text-muted-foreground hover:bg-muted/70 hover:text-foreground',
              )}
            >
              <Icon className="size-3" />
              {cat.label}
              {isActive && (
                <span className="ml-0.5 rounded-full bg-white/20 px-1 text-[9px]">
                  {filteredDefinitions.length}
                </span>
              )}
            </button>
          )
        })}
      </div>

      {/* Scrollable list */}
      <ScrollArea className="flex-1">
        <div className="space-y-1 p-2">
          {filteredDefinitions.length === 0 ? (
            <div className="px-3 py-8 text-center text-xs text-muted-foreground">
              No components match your search.
            </div>
          ) : (
            filteredDefinitions.map(def => (
              <DraggableQuestionItem key={def.id} definition={def} />
            ))
          )}
        </div>
      </ScrollArea>

      {/* Footer */}
      <div className="shrink-0 border-t border-border/50 p-2 text-center text-[10px] text-muted-foreground">
        Drag components onto the canvas
      </div>
    </div>
  )
}

// ─── Export the lookup map for quick access ─────────────────────────

export const QUESTION_TYPE_MAP = Object.fromEntries(
  QUESTION_TYPE_DEFINITIONS.map(def => [def.id, def])
)
