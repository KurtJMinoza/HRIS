import { useEffect, useMemo, useRef } from 'react'
import { Model } from 'survey-core'
import { Survey } from 'survey-react-ui'
import { scoresFromSurvey, surveyDataFromScores } from '@/lib/surveyConfig'

// Renders the actual evaluation form using the saved SurveyJS definition.
// Responses are converted back into the legacy `scores` shape on every change
// so the existing backend scoring/validation keeps working.
export default function EvaluationSurveyForm({ surveyJson, initialScores, onChange, readOnly = false }) {
  const onChangeRef = useRef(onChange)
  onChangeRef.current = onChange

  const model = useMemo(() => {
    const m = new Model(surveyJson && Object.keys(surveyJson).length ? surveyJson : { pages: [] })
    m.showTitle = false
    m.showCompletedPage = false
    m.showPreviewButton = false
    m.mode = readOnly ? 'display' : 'edit'
    m.data = surveyDataFromScores(surveyJson, initialScores)
    return m
    // initialScores is only used to seed the model on first build
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [surveyJson, readOnly])

  useEffect(() => {
    const handler = () => onChangeRef.current(scoresFromSurvey(surveyJson, model.data))
    model.onValueChanged.add(handler)
    return () => model.onValueChanged.remove(handler)
  }, [model, surveyJson])

  if (model.getAllQuestions().length === 0) {
    return (
      <div className="rounded-2xl border border-dashed border-border/60 bg-muted/15 px-6 py-10 text-center text-sm text-muted-foreground">
        This form has no questions yet.
      </div>
    )
  }

  return (
    <div className="rounded-2xl border border-border/70 bg-card p-6 shadow-sm">
      <Survey model={model} />
    </div>
  )
}
