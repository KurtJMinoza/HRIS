import { useEffect, useMemo, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { AlertTriangle, CheckCircle2, ChevronLeft, ChevronRight, Clock, Flag, Send } from 'lucide-react'
import { getPublicRecruitmentExam, submitPublicRecruitmentExam } from '@/api'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'

export default function RecruitmentExamPage() {
  const { token } = useParams()
  const [exam, setExam] = useState(null)
  const [answers, setAnswers] = useState({})
  const [files, setFiles] = useState({})
  const [currentIndex, setCurrentIndex] = useState(0)
  const [flagged, setFlagged] = useState({})
  const [error, setError] = useState('')
  const [submitted, setSubmitted] = useState(false)
  const [remainingSeconds, setRemainingSeconds] = useState(null)
  const autoSubmittedRef = useRef(false)

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

  async function submit() {
    if (autoSubmittedRef.current && submitted) return
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

  useEffect(() => {
    if (remainingSeconds == null || submitted) return undefined
    const id = setInterval(() => {
      setRemainingSeconds((seconds) => {
        if (seconds == null) return seconds
        if (seconds <= 1 && !autoSubmittedRef.current) {
          autoSubmittedRef.current = true
          window.setTimeout(() => submit(), 0)
        }
        return Math.max(0, seconds - 1)
      })
    }, 1000)
    return () => clearInterval(id)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [remainingSeconds, submitted])

  const questions = exam?.questions || []
  const currentQuestion = questions[currentIndex] || null
  const answeredCount = questions.filter((question) => {
    const value = answers[question.id]
    return Array.isArray(value) ? value.length > 0 : value != null && value !== ''
  }).length
  const progress = questions.length ? Math.round((answeredCount / questions.length) * 100) : 0

  const timerLabel = useMemo(() => {
    if (remainingSeconds == null) return '--:--:--'
    const hours = Math.floor(remainingSeconds / 3600)
    const minutes = Math.floor((remainingSeconds % 3600) / 60)
    const seconds = remainingSeconds % 60
    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
  }, [remainingSeconds])

  if (error) {
    return <div className="mx-auto max-w-2xl p-6 text-center text-red-600">{error}</div>
  }
  if (!exam) {
    return <div className="mx-auto max-w-2xl p-6 text-center text-slate-600">Loading exam...</div>
  }
  if (submitted) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
        <div className="max-w-md rounded-3xl border border-emerald-100 bg-white p-8 text-center shadow-xl">
          <CheckCircle2 className="mx-auto size-12 text-emerald-600" />
          <h1 className="mt-4 text-xl font-extrabold text-slate-950">Assessment submitted</h1>
          <p className="mt-2 text-sm text-slate-500">Your answers were submitted to AGCTek Recruitment. HR will review your result.</p>
        </div>
      </div>
    )
  }

  function updateAnswer(questionId, value) {
    setAnswers((state) => ({ ...state, [questionId]: value }))
  }

  function toggleCheckbox(questionId, choice) {
    setAnswers((state) => {
      const current = Array.isArray(state[questionId]) ? state[questionId] : []
      return {
        ...state,
        [questionId]: current.includes(choice) ? current.filter((item) => item !== choice) : [...current, choice],
      }
    })
  }

  return (
    <div className="min-h-screen bg-slate-100 text-slate-950">
      <header className="sticky top-0 z-30 border-b border-slate-200 bg-white/95 px-4 py-3 shadow-sm backdrop-blur">
        <div className="mx-auto flex max-w-7xl flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          <div className="flex items-center gap-3">
            <div className="leading-none">
              <div className="text-2xl font-black tracking-tight text-slate-950">AGC<span className="text-orange-600">TEK</span></div>
              <div className="text-[9px] font-bold uppercase tracking-[0.2em] text-slate-500">People · Technology · Innovation</div>
            </div>
            <div className="h-9 w-px bg-slate-200" />
            <div>
              <p className="text-[10px] font-bold uppercase tracking-wide text-orange-600">Recruitment Assessment Portal</p>
              <h1 className="text-lg font-extrabold">{exam.title}</h1>
            </div>
          </div>
          <div className="flex flex-wrap items-center gap-3">
            <div className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
              <p className="text-[10px] font-bold uppercase text-slate-400">Question</p>
              <p className="text-sm font-extrabold">{Math.min(currentIndex + 1, questions.length)} of {questions.length}</p>
            </div>
            <div className="rounded-xl border border-orange-200 bg-orange-50 px-3 py-2 text-orange-700">
              <p className="text-[10px] font-bold uppercase">Time Remaining</p>
              <p className="flex items-center gap-1 text-sm font-black"><Clock className="size-4" /> {timerLabel}</p>
            </div>
            <div className="min-w-36 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
              <p className="text-[10px] font-bold uppercase text-slate-400">Progress</p>
              <div className="mt-1 h-2 rounded-full bg-slate-200"><div className="h-2 rounded-full bg-orange-500" style={{ width: `${progress}%` }} /></div>
            </div>
          </div>
        </div>
      </header>

      <main className="mx-auto grid max-w-7xl gap-4 px-4 py-5 xl:grid-cols-[270px_minmax(0,1fr)_250px]">
        <aside className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <h2 className="text-sm font-extrabold">Applicant Information</h2>
          <div className="mt-4 space-y-3 text-xs">
            {[
              ['Applicant Name', exam.applicant_name],
              ['Position Applied', exam.position_applied || '-'],
              ['Company', exam.company || '-'],
              ['Branch', exam.branch || '-'],
              ['Assessment Status', exam.status || 'In Progress'],
              ['Attempt', `Attempt ${exam.attempt_number || 1} of ${exam.max_attempts || 1}`],
            ].map(([label, value]) => (
              <div key={label} className="rounded-xl bg-slate-50 px-3 py-2">
                <p className="text-[10px] font-bold uppercase text-slate-400">{label}</p>
                <p className="mt-1 font-bold text-slate-800">{value}</p>
              </div>
            ))}
          </div>
          {exam.instructions ? <p className="mt-4 rounded-xl border border-orange-100 bg-orange-50 p-3 text-[11px] leading-5 text-orange-800">{exam.instructions}</p> : null}
        </aside>

        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          {currentQuestion ? (
            <>
              <div className="flex items-start justify-between gap-4 border-b border-slate-100 pb-4">
                <div>
                  <p className="text-[10px] font-bold uppercase tracking-wide text-orange-600">{currentQuestion.category || exam.category || 'Assessment Question'}</p>
                  <h2 className="mt-1 text-lg font-extrabold">Question {currentIndex + 1}</h2>
                </div>
                <button type="button" onClick={() => setFlagged((state) => ({ ...state, [currentQuestion.id]: !state[currentQuestion.id] }))} className={cn('inline-flex items-center gap-1 rounded-lg border px-3 py-2 text-xs font-bold', flagged[currentQuestion.id] ? 'border-red-200 bg-red-50 text-red-700' : 'border-slate-200 text-slate-600')}>
                  <Flag className="size-3.5" /> Flag
                </button>
              </div>
              <div className="py-6">
                <p className="text-base font-semibold leading-7 text-slate-900">{currentQuestion.question}</p>
                <p className="mt-2 text-xs text-slate-500">{currentQuestion.question_type} · {currentQuestion.points} point(s)</p>

                {currentQuestion.question_type === 'Multiple Choice' || currentQuestion.question_type === 'True / False' ? (
                  <div className="mt-5 grid gap-3">
                    {(currentQuestion.question_type === 'True / False' ? ['True', 'False'] : currentQuestion.choices || []).map((choice) => (
                      <label key={choice} className={cn('flex items-center gap-3 rounded-xl border px-4 py-3 text-sm font-semibold transition', answers[currentQuestion.id] === choice ? 'border-orange-300 bg-orange-50 text-orange-800' : 'border-slate-200 hover:bg-slate-50')}>
                        <input type="radio" name={`q-${currentQuestion.id}`} value={choice} checked={answers[currentQuestion.id] === choice} onChange={(e) => updateAnswer(currentQuestion.id, e.target.value)} />
                        {choice}
                      </label>
                    ))}
                  </div>
                ) : currentQuestion.question_type === 'Checkbox' ? (
                  <div className="mt-5 grid gap-3">
                    {(currentQuestion.choices || []).map((choice) => (
                      <label key={choice} className="flex items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-sm font-semibold hover:bg-slate-50">
                        <input type="checkbox" checked={(answers[currentQuestion.id] || []).includes(choice)} onChange={() => toggleCheckbox(currentQuestion.id, choice)} />
                        {choice}
                      </label>
                    ))}
                  </div>
                ) : currentQuestion.question_type === 'File Upload' ? (
                  <Input className="mt-5" type="file" onChange={(e) => setFiles((state) => ({ ...state, [currentQuestion.id]: e.target.files?.[0] || null }))} />
                ) : currentQuestion.question_type === 'Identification' || currentQuestion.question_type === 'Short Answer' ? (
                  <Input className="mt-5 h-12" value={answers[currentQuestion.id] || ''} onChange={(e) => updateAnswer(currentQuestion.id, e.target.value)} placeholder="Type your answer" />
                ) : (
                  <Textarea className="mt-5 min-h-40" value={answers[currentQuestion.id] || ''} onChange={(e) => updateAnswer(currentQuestion.id, e.target.value)} placeholder="Type your answer" />
                )}
              </div>
              <div className="flex items-center justify-between border-t border-slate-100 pt-4">
                <Button variant="outline" className="gap-2" onClick={() => setCurrentIndex((index) => Math.max(0, index - 1))} disabled={currentIndex === 0}><ChevronLeft className="size-4" /> Previous</Button>
                {currentIndex === questions.length - 1 ? (
                  <Button className="gap-2 bg-orange-600 text-white hover:bg-orange-700" onClick={submit}>
                    <Send className="size-4" /> Submit Assessment
                  </Button>
                ) : (
                  <Button className="gap-2 bg-orange-600 text-white hover:bg-orange-700" onClick={() => setCurrentIndex((index) => Math.min(questions.length - 1, index + 1))}>Next <ChevronRight className="size-4" /></Button>
                )}
              </div>
            </>
          ) : (
            <div className="py-16 text-center text-sm text-slate-500">No questions are available for this assessment.</div>
          )}
        </section>

        <aside className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <h2 className="text-sm font-extrabold">Question Navigator</h2>
          <div className="mt-4 grid grid-cols-5 gap-2">
            {questions.map((question, index) => {
              const isAnswered = Array.isArray(answers[question.id]) ? answers[question.id].length > 0 : answers[question.id] != null && answers[question.id] !== ''
              return (
                <button
                  key={question.id}
                  type="button"
                  onClick={() => setCurrentIndex(index)}
                  className={cn(
                    'size-9 rounded-lg text-xs font-black ring-1',
                    flagged[question.id] && 'bg-red-50 text-red-700 ring-red-200',
                    !flagged[question.id] && currentIndex === index && 'bg-orange-500 text-white ring-orange-500',
                    !flagged[question.id] && currentIndex !== index && isAnswered && 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                    !flagged[question.id] && currentIndex !== index && !isAnswered && 'bg-slate-100 text-slate-500 ring-slate-200',
                  )}
                >
                  {index + 1}
                </button>
              )
            })}
          </div>
          <div className="mt-5 space-y-2 text-[11px] font-semibold text-slate-600">
            <p><span className="mr-2 inline-block size-3 rounded bg-slate-200" /> Not Answered</p>
            <p><span className="mr-2 inline-block size-3 rounded bg-orange-500" /> Current</p>
            <p><span className="mr-2 inline-block size-3 rounded bg-emerald-200" /> Answered</p>
            <p><span className="mr-2 inline-block size-3 rounded bg-red-200" /> Flagged</p>
          </div>
          <Button className="mt-6 w-full gap-2 bg-orange-600 text-white hover:bg-orange-700" onClick={submit}>
            <Send className="size-4" />
            Submit Assessment
          </Button>
          {remainingSeconds === 0 ? (
            <div className="mt-3 flex gap-2 rounded-xl bg-red-50 p-3 text-xs text-red-700"><AlertTriangle className="size-4 shrink-0" /> Time expired. Auto-submitting...</div>
          ) : null}
        </aside>
      </main>
    </div>
  )
}
