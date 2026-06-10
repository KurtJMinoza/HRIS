import { useEffect, useMemo, useState } from 'react'
import { useParams } from 'react-router-dom'
import { Clock, Send } from 'lucide-react'
import { getPublicRecruitmentExam, submitPublicRecruitmentExam } from '@/api'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { Input } from '@/components/ui/input'

export default function RecruitmentExamPage() {
  const { token } = useParams()
  const [exam, setExam] = useState(null)
  const [answers, setAnswers] = useState({})
  const [files, setFiles] = useState({})
  const [error, setError] = useState('')
  const [submitted, setSubmitted] = useState(false)
  const [remainingSeconds, setRemainingSeconds] = useState(null)

  useEffect(() => {
    let mounted = true
    getPublicRecruitmentExam(token)
      .then((data) => {
        if (!mounted) return
        setExam(data.exam)
        const startedAt = data.exam?.started_at ? new Date(data.exam.started_at).getTime() : Date.now()
        const totalSeconds = Number(data.exam?.duration_minutes || 0) * 60
        setRemainingSeconds(Math.max(0, Math.floor((startedAt + totalSeconds * 1000 - Date.now()) / 1000)))
      })
      .catch((err) => setError(err.message || 'Failed to load exam'))
    return () => { mounted = false }
  }, [token])

  useEffect(() => {
    if (remainingSeconds == null || submitted) return undefined
    const id = setInterval(() => {
      setRemainingSeconds((seconds) => {
        if (seconds == null) return seconds
        return Math.max(0, seconds - 1)
      })
    }, 1000)
    return () => clearInterval(id)
  }, [remainingSeconds, submitted])

  const timerLabel = useMemo(() => {
    if (remainingSeconds == null) return '--:--'
    const minutes = Math.floor(remainingSeconds / 60)
    const seconds = remainingSeconds % 60
    return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
  }, [remainingSeconds])

  async function submit() {
    setError('')
    try {
      const payload = (exam?.questions || []).map((question) => ({
        question_id: question.id,
        answer: answers[question.id] ?? '',
        file: files[question.id] ?? null,
      }))
      await submitPublicRecruitmentExam(token, payload)
      setSubmitted(true)
    } catch (err) {
      setError(err.message || 'Failed to submit exam')
    }
  }

  if (error) {
    return <div className="mx-auto max-w-2xl p-6 text-center text-red-600">{error}</div>
  }
  if (!exam) {
    return <div className="mx-auto max-w-2xl p-6 text-center text-slate-600">Loading exam...</div>
  }
  if (submitted) {
    return <div className="mx-auto max-w-2xl p-6 text-center text-emerald-700">Exam submitted. HR will review your result.</div>
  }

  return (
    <div className="min-h-screen bg-slate-50 px-4 py-8 text-slate-950">
      <div className="mx-auto max-w-3xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <div className="flex flex-col gap-3 border-b border-slate-200 pb-4 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-orange-600">Recruitment Exam</p>
            <h1 className="mt-1 text-2xl font-bold">{exam.title}</h1>
            <p className="mt-1 text-sm text-slate-500">Applicant: {exam.applicant_name}</p>
          </div>
          <div className="inline-flex items-center gap-2 rounded-lg border border-orange-200 bg-orange-50 px-3 py-2 text-sm font-bold text-orange-700">
            <Clock className="size-4" />
            {timerLabel}
          </div>
        </div>

        <div className="mt-5 space-y-5">
          {(exam.questions || []).map((question, index) => (
            <div key={question.id} className="rounded-lg border border-slate-200 p-4">
              <div className="flex items-start justify-between gap-3">
                <p className="font-semibold">{index + 1}. {question.question}</p>
                <span className="shrink-0 rounded bg-slate-100 px-2 py-1 text-xs">{question.points} pt</span>
              </div>
              <p className="mt-1 text-xs text-slate-500">{question.question_type}</p>

              {question.question_type === 'Multiple Choice' || question.question_type === 'True / False' ? (
                <div className="mt-3 grid gap-2">
                  {(question.question_type === 'True / False' ? ['True', 'False'] : question.choices || []).map((choice) => (
                    <label key={choice} className="flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm">
                      <input
                        type="radio"
                        name={`q-${question.id}`}
                        value={choice}
                        checked={answers[question.id] === choice}
                        onChange={(e) => setAnswers((state) => ({ ...state, [question.id]: e.target.value }))}
                      />
                      {choice}
                    </label>
                  ))}
                </div>
              ) : question.question_type === 'File Upload' ? (
                <Input className="mt-3" type="file" onChange={(e) => setFiles((state) => ({ ...state, [question.id]: e.target.files?.[0] || null }))} />
              ) : (
                <Textarea className="mt-3" value={answers[question.id] || ''} onChange={(e) => setAnswers((state) => ({ ...state, [question.id]: e.target.value }))} />
              )}
            </div>
          ))}
        </div>

        <div className="mt-6 flex justify-end">
          <Button className="gap-2 bg-orange-600 text-white hover:bg-orange-700" onClick={submit} disabled={remainingSeconds === 0}>
            <Send className="size-4" />
            Submit Exam
          </Button>
        </div>
      </div>
    </div>
  )
}
