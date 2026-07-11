import { forwardRef, useEffect, useImperativeHandle, useMemo, useRef } from 'react'
import { Model } from 'survey-core'
import { Survey } from 'survey-react-ui'
import 'survey-core/survey-core.min.css'
import { normalizeSurveyJsonExpressions, scoresFromSurvey, surveyDataFromScores, syncModelDataForScoring, unlockEvaluationPrefillQuestions } from '@/lib/surveyConfig'
import { applyWeightedSummaryExpressions } from '@/lib/evaluationScoring'

function applySurveyDataToModel(model, surveyJson, scores, suppressNotifyRef) {
  suppressNotifyRef.current = true
  try {
    model.data = surveyDataFromScores(surveyJson, scores)
    syncModelDataForScoring(surveyJson, model)
  } finally {
    suppressNotifyRef.current = false
  }
}

// Renders the actual evaluation form using the saved SurveyJS definition.
// Responses are converted back into the legacy `scores` shape on user edits
// (or via ref.getScores() on submit) so the backend scoring keeps working.
const EvaluationSurveyForm = forwardRef(function EvaluationSurveyForm(
  { surveyJson, initialScores, onChange, readOnly = false },
  ref,
) {
  const onChangeRef = useRef(onChange)
  onChangeRef.current = onChange
  const suppressNotifyRef = useRef(false)

  const normalizedJson = useMemo(() => {
    let json = normalizeSurveyJsonExpressions(surveyJson)
    json = applyWeightedSummaryExpressions(json)
    if (!readOnly) json = unlockEvaluationPrefillQuestions(json)
    return json
  }, [surveyJson, readOnly])

  const model = useMemo(() => {
    const m = new Model(normalizedJson && Object.keys(normalizedJson).length ? normalizedJson : { pages: [] })
    m.showTitle = false
    m.showCompletedPage = false
    m.showPreviewButton = false
    m.showProgressBar = false
    m.showNavigationButtons = !readOnly
    // ponytail: display mode renders matrix radios unchecked; edit+readOnly shows selections
    m.mode = 'edit'
    m.readOnly = readOnly
    if (readOnly) {
      m.questionsOnPageMode = 'singlePage'
    }
    applySurveyDataToModel(m, normalizedJson, initialScores, suppressNotifyRef)
    return m
    // initialScores is synced in a dedicated effect below (read-only view only)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [normalizedJson, readOnly])

  useImperativeHandle(ref, () => ({
    getScores: () => scoresFromSurvey(normalizedJson, syncModelDataForScoring(normalizedJson, model)),
  }), [model, normalizedJson])

  // Re-apply saved answers when viewing a different evaluation (read-only).
  useEffect(() => {
    if (!readOnly) return
    applySurveyDataToModel(model, normalizedJson, initialScores, suppressNotifyRef)
  }, [model, normalizedJson, initialScores, readOnly])

  useEffect(() => {
    const handler = () => {
      if (suppressNotifyRef.current) return
      onChangeRef.current?.(scoresFromSurvey(normalizedJson, model.data))
    }
    model.onValueChanged.add(handler)
    return () => model.onValueChanged.remove(handler)
  }, [model, normalizedJson])

  if (model.getAllQuestions().length === 0) {
    return (
      <div className="rounded-2xl border border-dashed border-border/60 bg-muted/15 px-6 py-10 text-center text-sm text-muted-foreground">
        This form has no questions yet.
      </div>
    )
  }

  return (
    <div className="evaluation-survey-form rounded-2xl border border-border/70 bg-card p-6 shadow-sm [&_.sd-root-modern]:--sjs-font-family:inherit">
      <Survey model={model} />
    </div>
  )
})

export default EvaluationSurveyForm
