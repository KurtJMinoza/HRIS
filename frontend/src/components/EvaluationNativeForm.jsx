import { forwardRef, useCallback, useEffect, useImperativeHandle, useMemo, useState } from 'react'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { cn } from '@/lib/utils'
import {
  INFO_FIELD_LABELS,
  answersFromScores,
  packScores,
} from '@/lib/evaluationFormDefinition'
import { computeFromDefinition, ratingLabelFromPercentage } from '@/lib/evaluationScoring'

function RatingButtons({ max, value, onChange, readOnly }) {
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      {Array.from({ length: max }, (_, i) => {
        const n = i + 1
        const active = Number(value) >= n
        return (
          <button
            key={n}
            type="button"
            disabled={readOnly}
            onClick={() => onChange(n)}
            className={cn(
              'size-10 rounded-xl text-sm font-bold transition-all',
              active
                ? 'bg-brand text-brand-foreground shadow-sm scale-105'
                : 'bg-muted text-muted-foreground hover:bg-muted/80',
              readOnly && 'cursor-default opacity-90',
            )}
          >
            {n}
          </button>
        )
      })}
    </div>
  )
}

function MatrixRows({ question, answers, onChange, readOnly }) {
  const rows = question.rows || []
  const raw = answers[question.id]
  const getVal = (i) => {
    if (Array.isArray(raw)) return raw[i]
    if (raw && typeof raw === 'object') return raw[i] ?? raw[String(i)]
    return undefined
  }
  const setVal = (i, v) => {
    const next = Array.isArray(raw) ? [...raw] : { ...(typeof raw === 'object' ? raw : {}) }
    if (Array.isArray(next)) next[i] = v
    else next[String(i)] = v
    onChange(question.id, next)
  }
  return (
    <div className="space-y-3">
      {rows.map((row, i) => (
        <div key={i} className="flex flex-col gap-2 rounded-xl bg-muted/30 px-4 py-3 @sm:flex-row @sm:items-center @sm:justify-between">
          <span className="text-sm font-medium text-foreground">{row}</span>
          <RatingButtons
            max={Number(question.max) || 5}
            value={getVal(i)}
            onChange={(v) => setVal(i, v)}
            readOnly={readOnly}
          />
        </div>
      ))}
    </div>
  )
}

const EvaluationNativeForm = forwardRef(function EvaluationNativeForm(
  { definition, initialScores, onChange, readOnly = false },
  ref,
) {
  const [answers, setAnswers] = useState(() => answersFromScores(initialScores))

  useEffect(() => {
    setAnswers(answersFromScores(initialScores))
  }, [initialScores])

  const computed = useMemo(() => computeFromDefinition(definition, answers), [definition, answers])

  const setAnswer = useCallback((id, value) => {
    setAnswers((prev) => {
      const next = { ...prev, [id]: value }
      onChange?.(packScores(definition, next, computeFromDefinition(definition, next)))
      return next
    })
  }, [definition, onChange])

  useImperativeHandle(ref, () => ({
    getScores: () => packScores(definition, answers, computed),
  }), [definition, answers, computed])

  if (!definition?.sections?.length) {
    return (
      <div className="rounded-2xl border border-dashed border-border/60 bg-muted/15 px-6 py-10 text-center text-sm text-muted-foreground">
        This form has no sections yet.
      </div>
    )
  }

  const scaleMax = Number(definition.scale?.max) || 5

  return (
    <div className="space-y-6">
      {definition.intro_html ? (
        <div
          className="prose prose-sm max-w-none rounded-2xl border border-border/60 bg-muted/20 px-5 py-4 dark:prose-invert"
          dangerouslySetInnerHTML={{ __html: definition.intro_html }}
        />
      ) : null}

      {(definition.info_fields || []).length > 0 && (
        <div className="grid gap-4 rounded-2xl border border-border/70 bg-card p-5 @md:grid-cols-2">
          {(definition.info_fields || []).map((key) => (
            <div key={key} className="space-y-1.5">
              <Label className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {INFO_FIELD_LABELS[key] || key}
              </Label>
              <Input
                value={answers[key] ?? ''}
                readOnly={readOnly}
                onChange={(e) => setAnswer(key, e.target.value)}
                className="h-10 rounded-lg"
              />
            </div>
          ))}
        </div>
      )}

      {definition.sections.map((section) => (
        <div key={section.id} className="rounded-2xl border border-border/70 bg-card p-6 shadow-sm">
          <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
            <h4 className="text-sm font-bold text-foreground">{section.title}</h4>
            {Number(section.weight) > 0 && (
              <Badge variant="outline" className="rounded-full text-xs font-normal">
                {section.weight}% weight
                {computed?.section_scores?.[section.id] != null && (
                  <span className="ml-1 tabular-nums text-brand">
                    · {computed.section_scores[section.id]}%
                  </span>
                )}
              </Badge>
            )}
          </div>
          <div className="space-y-4">
            {(section.questions || []).map((q) => (
              <div key={q.id} className="rounded-xl bg-muted/25 px-4 py-3">
                <p className="mb-3 text-sm font-medium text-foreground">
                  {q.title}
                  {q.required && !readOnly ? <span className="text-destructive"> *</span> : null}
                </p>
                {q.type === 'matrix' ? (
                  <MatrixRows question={q} answers={answers} onChange={setAnswer} readOnly={readOnly} />
                ) : q.type === 'rating' ? (
                  <RatingButtons
                    max={Number(q.max) || scaleMax}
                    value={answers[q.id]}
                    onChange={(v) => setAnswer(q.id, v)}
                    readOnly={readOnly}
                  />
                ) : (
                  <Textarea
                    value={answers[q.id] ?? ''}
                    readOnly={readOnly}
                    onChange={(e) => setAnswer(q.id, e.target.value)}
                    className="min-h-20 rounded-lg"
                    placeholder="Enter your response..."
                  />
                )}
              </div>
            ))}
          </div>
        </div>
      ))}

      {(definition.comments || []).map((c) => (
        <div key={c.id} className="rounded-2xl border border-border/70 bg-card p-6 shadow-sm">
          <Label className="mb-2 block text-sm font-bold text-foreground">{c.title}</Label>
          <Textarea
            value={answers[c.id] ?? ''}
            readOnly={readOnly}
            onChange={(e) => setAnswer(c.id, e.target.value)}
            className="min-h-24 rounded-lg"
            placeholder="Enter comments..."
          />
        </div>
      ))}

      {(definition.signatures || []).map((s) => (
        <div key={s.id} className="rounded-2xl border border-border/70 bg-card p-6 shadow-sm">
          <Label className="mb-2 block text-sm font-bold text-foreground">{s.title}</Label>
          <Input
            value={answers[s.id] ?? ''}
            readOnly={readOnly}
            onChange={(e) => setAnswer(s.id, e.target.value)}
            placeholder={readOnly ? '—' : 'Type full name as signature'}
            className="h-10 rounded-lg font-serif italic"
          />
        </div>
      ))}

      {computed && Number(computed.overall_percentage) > 0 && (
        <div className="rounded-2xl border border-brand/30 bg-brand/5 px-5 py-4">
          <p className="text-xs font-bold uppercase tracking-wide text-muted-foreground">Overall score</p>
          <p className="mt-1 text-2xl font-bold tabular-nums text-brand">
            {computed.overall_percentage}%
            <span className="ml-2 text-base font-semibold text-foreground">
              ({ratingLabelFromPercentage(computed.overall_percentage)})
            </span>
          </p>
        </div>
      )}
    </div>
  )
})

export default EvaluationNativeForm
