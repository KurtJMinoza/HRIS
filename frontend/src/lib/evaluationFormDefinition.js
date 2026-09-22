/** Native evaluation form definition (replaces SurveyJS). */

export const FORM_DEFINITION_VERSION = 2

export const DEFAULT_INFO_FIELDS = [
  'employee_name',
  'position',
  'department',
  'evaluation_period',
  'evaluator_name',
  'relationship',
]

export const INFO_FIELD_LABELS = {
  employee_name: 'Employee Name',
  position: 'Position',
  department: 'Department',
  evaluation_period: 'Evaluation Period',
  evaluator_name: 'Evaluator Name',
  relationship: 'Relationship',
}

export const DEFAULT_SCALE = { min: 1, max: 5 }

export const PRESET_360 = {
  version: FORM_DEFINITION_VERSION,
  intro_html: '<p style="text-align:center;font-weight:bold;color:#dc2626">STRICTLY CONFIDENTIAL</p><p style="text-align:center">360-DEGREE PERFORMANCE FEEDBACK SURVEY</p>',
  scale: DEFAULT_SCALE,
  info_fields: [...DEFAULT_INFO_FIELDS],
  sections: [
    {
      id: 'quality',
      title: 'A. Quality of Work',
      weight: 15,
      questions: [
        { id: 'quality_0', title: 'Produces accurate and high-quality work.', type: 'rating', max: 5, required: true },
        { id: 'quality_1', title: 'Pays attention to detail.', type: 'rating', max: 5, required: true },
        { id: 'quality_2', title: 'Meets quality standards consistently.', type: 'rating', max: 5, required: true },
      ],
    },
    {
      id: 'productivity',
      title: 'B. Productivity & Results',
      weight: 15,
      questions: [
        { id: 'productivity_0', title: 'Completes assignments on time.', type: 'rating', max: 5, required: true },
        { id: 'productivity_1', title: 'Manages workload effectively.', type: 'rating', max: 5, required: true },
        { id: 'productivity_2', title: 'Achieves agreed targets.', type: 'rating', max: 5, required: true },
      ],
    },
    {
      id: 'accountability',
      title: 'C. Accountability & Reliability',
      weight: 15,
      questions: [
        { id: 'accountability_0', title: 'Takes ownership of responsibilities.', type: 'rating', max: 5, required: true },
        { id: 'accountability_1', title: 'Follows through on commitments.', type: 'rating', max: 5, required: true },
        { id: 'accountability_2', title: 'Demonstrates dependability.', type: 'rating', max: 5, required: true },
      ],
    },
    {
      id: 'communication',
      title: 'D. Communication & Collaboration',
      weight: 15,
      questions: [
        { id: 'communication_0', title: 'Communicates clearly and professionally.', type: 'rating', max: 5, required: true },
        { id: 'communication_1', title: 'Works well with others.', type: 'rating', max: 5, required: true },
        { id: 'communication_2', title: 'Responds promptly to requests.', type: 'rating', max: 5, required: true },
      ],
    },
    {
      id: 'problem_solving',
      title: 'E. Problem Solving & Initiative',
      weight: 10,
      questions: [
        { id: 'problem_solving_0', title: 'Identifies issues proactively.', type: 'rating', max: 5, required: true },
        { id: 'problem_solving_1', title: 'Proposes practical solutions.', type: 'rating', max: 5, required: true },
        { id: 'problem_solving_2', title: 'Shows initiative beyond assigned tasks.', type: 'rating', max: 5, required: true },
      ],
    },
    {
      id: 'core_values',
      title: 'Core Values',
      weight: 30,
      questions: [
        { id: 'core_value_0', title: 'Integrity and honesty.', type: 'rating', max: 5, required: true },
        { id: 'core_value_1', title: 'Respect for others.', type: 'rating', max: 5, required: true },
        { id: 'core_value_2', title: 'Teamwork and collaboration.', type: 'rating', max: 5, required: true },
        { id: 'core_value_3', title: 'Customer focus.', type: 'rating', max: 5, required: true },
        { id: 'core_value_4', title: 'Innovation and improvement.', type: 'rating', max: 5, required: true },
        { id: 'core_value_5', title: 'Accountability.', type: 'rating', max: 5, required: true },
        { id: 'core_value_6', title: 'Excellence in work.', type: 'rating', max: 5, required: true },
      ],
    },
  ],
  comments: [
    { id: 'strengths', title: 'Strengths / Positive Observations', type: 'text', required: false },
    { id: 'improvements', title: 'Areas for Improvement', type: 'text', required: false },
  ],
  signatures: [
    { id: 'evaluator_signature', title: 'Evaluator Signature', required: true },
  ],
}

let idCounter = 0
export function nextQuestionId(prefix = 'q') {
  idCounter += 1
  return `${prefix}_${Date.now()}_${idCounter}`
}

export function createEmptyDefinition(overrides = {}) {
  return {
    version: FORM_DEFINITION_VERSION,
    intro_html: '',
    scale: { ...DEFAULT_SCALE },
    info_fields: [...DEFAULT_INFO_FIELDS],
    sections: [
      {
        id: nextQuestionId('section'),
        title: 'Section 1',
        weight: 100,
        questions: [
          { id: nextQuestionId('rating'), title: 'Rating question', type: 'rating', max: 5, required: true },
        ],
      },
    ],
    comments: [],
    signatures: [],
    ...overrides,
  }
}

export function isDefinitionV2(raw) {
  return raw && typeof raw === 'object' && raw.version === FORM_DEFINITION_VERSION && Array.isArray(raw.sections)
}

function slugId(name, fallback) {
  const base = String(name || fallback || 'item')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_|_$/g, '')
  return base || nextQuestionId('item')
}

function matrixRowsFromSurvey(el) {
  const rows = Array.isArray(el?.rows) ? el.rows : []
  return rows.map((row, i) => {
    if (typeof row === 'string') return row
    if (row && typeof row === 'object') return row.text || row.value || `Row ${i + 1}`
    return `Row ${i + 1}`
  })
}

function surveyElementToQuestion(el) {
  if (!el || el.type === 'html' || el.type === 'expression') return null
  const id = el.name || nextQuestionId('q')
  if (el.type === 'rating') {
    return {
      id,
      title: el.title || el.name || 'Rating',
      type: 'rating',
      max: Number(el.rateMax || el.rateCount || 5),
      required: el.isRequired !== false,
    }
  }
  if (el.type === 'matrix') {
    return {
      id,
      title: el.title || el.name || 'Matrix',
      type: 'matrix',
      max: Number(el.rateMax || 5),
      rows: matrixRowsFromSurvey(el),
      required: el.isRequired !== false,
    }
  }
  if (el.type === 'comment' || el.type === 'text') {
    return { id, title: el.title || el.name || 'Comment', type: 'text', required: el.isRequired === true }
  }
  if (el.type === 'signaturepad') {
    return { id, title: el.title || el.name || 'Signature', type: 'signature', required: el.isRequired !== false }
  }
  if (el.type === 'radiogroup' || el.type === 'dropdown') {
    return { id, title: el.title || el.name || 'Question', type: 'text', required: el.isRequired === true }
  }
  return null
}

/** Convert SurveyJS JSON into native definition v2. */
export function surveyJsonToFormDefinition(surveyJson, meta = {}) {
  if (!surveyJson || !Array.isArray(surveyJson.pages)) return null

  const sections = []
  const comments = []
  const signatures = []
  let introParts = []

  for (const page of surveyJson.pages) {
    const elements = Array.isArray(page.elements) ? page.elements : []
    const panels = elements.filter((el) => el?.type === 'panel')

    const pageHtml = elements.filter((el) => el?.type === 'html')
    for (const html of pageHtml) {
      if (html.html) introParts.push(html.html)
    }

    const processElements = (els, panelMeta = null) => {
      for (const el of els) {
        if (!el) continue
        if (el.type === 'panel') {
          const questions = (el.elements || [])
            .map(surveyElementToQuestion)
            .filter(Boolean)
          if (questions.length > 0) {
            sections.push({
              id: slugId(el.name, el.title),
              title: el.title || el.name || 'Section',
              weight: Number(el.weight) || 0,
              questions,
            })
          }
          continue
        }
        const q = surveyElementToQuestion(el)
        if (!q) continue
        if (q.type === 'signature') {
          signatures.push({ id: q.id, title: q.title, required: q.required })
        } else if (q.type === 'text' && !panelMeta) {
          comments.push({ id: q.id, title: q.title, type: 'text', required: q.required })
        } else if (!panelMeta) {
          sections.push({
            id: slugId(el.name, el.title),
            title: page.title || 'Section',
            weight: 100,
            questions: [q],
          })
        }
      }
    }

    if (panels.length > 0) {
      for (const panel of panels) processElements([panel])
    } else {
      const loose = elements.filter((el) => el?.type !== 'html' && el?.type !== 'expression')
      const ratingQs = loose.map(surveyElementToQuestion).filter(Boolean)
      if (ratingQs.length > 0) {
        sections.push({
          id: slugId(page.name, page.title),
          title: page.title || page.name || 'Section',
          weight: 0,
          questions: ratingQs.filter((q) => q.type === 'rating' || q.type === 'matrix'),
        })
        for (const q of ratingQs) {
          if (q.type === 'text') comments.push({ id: q.id, title: q.title, type: 'text', required: q.required })
          if (q.type === 'signature') signatures.push({ id: q.id, title: q.title, required: q.required })
        }
      }
    }
  }

  const scored = sections.filter((s) => Number(s.weight) > 0)
  if (scored.length > 0) {
    const total = scored.reduce((sum, s) => sum + Number(s.weight || 0), 0)
    if (total !== 100 && total > 0) {
      const factor = 100 / total
      let running = 0
      scored.forEach((s, i) => {
        if (i === scored.length - 1) s.weight = 100 - running
        else {
          s.weight = Math.round(Number(s.weight) * factor)
          running += s.weight
        }
      })
    }
  }

  return {
    version: FORM_DEFINITION_VERSION,
    intro_html: introParts.join('') || meta.intro_html || '',
    scale: DEFAULT_SCALE,
    info_fields: [...DEFAULT_INFO_FIELDS],
    sections,
    comments,
    signatures,
  }
}

/** Normalize legacy array sections into v2 definition. */
export function legacySectionsToDefinition(sections, meta = {}) {
  if (!Array.isArray(sections) || sections.length === 0) return createEmptyDefinition(meta)
  return {
    version: FORM_DEFINITION_VERSION,
    intro_html: meta.intro_html || '',
    scale: meta.scale || { ...DEFAULT_SCALE },
    info_fields: meta.info_fields || [...DEFAULT_INFO_FIELDS],
    sections: sections.map((section, si) => ({
      id: section.id || slugId(section.title, `section_${si}`),
      title: section.title || `Section ${si + 1}`,
      weight: Number(section.weight) || 0,
      questions: (section.questions || []).map((q, qi) => ({
        id: q.id || slugId(q.title, `q_${si}_${qi}`),
        title: q.title || 'Question',
        type: q.type === 'text' ? 'text' : (q.type === 'matrix' ? 'matrix' : 'rating'),
        max: Number(q.max || 5),
        rows: q.rows || [],
        required: q.required !== false,
      })),
    })),
    comments: meta.comments || [],
    signatures: meta.signatures || [],
  }
}

export function resolveFormDefinition(form) {
  const raw = form?.sections
  if (isDefinitionV2(raw)) {
    return {
      ...form,
      definition: raw,
    }
  }
  if (form?.survey_json && Array.isArray(form.survey_json.pages) && form.survey_json.pages.length > 0) {
    const converted = surveyJsonToFormDefinition(form.survey_json, {
      intro_html: form.description || '',
    })
    if (converted) {
      return { ...form, definition: converted, _convertedFromSurvey: true }
    }
  }
  if (Array.isArray(raw) && raw.length > 0) {
    return {
      ...form,
      definition: legacySectionsToDefinition(raw, { intro_html: form.description || '' }),
    }
  }
  return { ...form, definition: createEmptyDefinition({ intro_html: form?.description || '' }) }
}

export function hasFormDefinition(form) {
  if (!form) return false
  const def = resolveFormDefinition(form).definition
  return (def.sections?.length || 0) > 0
}

export function scoredSections(definition) {
  return (definition?.sections || []).filter((s) => Number(s.weight) > 0)
}

export function sectionsWeightTotal(definition) {
  return scoredSections(definition).reduce((sum, s) => sum + Number(s.weight || 0), 0)
}

export function validateDefinitionWeights(definition) {
  const total = sectionsWeightTotal(definition)
  const scored = scoredSections(definition)
  if (scored.length === 0) return { ok: false, total, message: 'Add at least one scored section with weight.' }
  if (total !== 100) return { ok: false, total, message: `Section weights must sum to 100% (currently ${total}%).` }
  return { ok: true, total }
}

export function countDefinitionQuestions(definition) {
  const sectionQs = (definition?.sections || []).reduce((n, s) => n + (s.questions?.length || 0), 0)
  const comments = definition?.comments?.length || 0
  const sigs = definition?.signatures?.length || 0
  return sectionQs + comments + sigs
}

export function getFormStats(form) {
  const { definition } = resolveFormDefinition(form || {})
  return {
    sectionCount: definition.sections?.length || 0,
    questionCount: countDefinitionQuestions(definition),
    isNative: true,
  }
}

export function defaultEvaluationPeriod(at = new Date()) {
  const month = at.getMonth() + 1
  const quarter = Math.ceil(month / 3)
  return `Q${quarter} ${at.getFullYear()}`
}

const SUPERVISOR_HR_ROLES = new Set([
  'admin_hr', 'company_head', 'area_head', 'branch_head',
  'department_head', 'division_head', 'section_unit_head',
])

export function buildEvaluationPrefillContext({ employee, evaluator, hrRole } = {}) {
  const department = employee?.departmentRelation?.name
    || employee?.department
    || employee?.branch?.name
    || employee?.company?.name
    || ''
  return {
    employee_name: [employee?.first_name, employee?.last_name].filter(Boolean).join(' ') || employee?.name || '',
    position: employee?.position || '',
    department,
    evaluation_period: defaultEvaluationPeriod(),
    evaluator_name: evaluator?.name || [evaluator?.first_name, evaluator?.last_name].filter(Boolean).join(' ') || '',
    relationship: hrRole && SUPERVISOR_HR_ROLES.has(hrRole) ? 'Immediate Supervisor' : '',
  }
}

export function buildPrefilledAnswers(definition, context = {}, existing = {}) {
  const answers = { ...(existing || {}) }
  for (const key of definition?.info_fields || DEFAULT_INFO_FIELDS) {
    if (answers[key] != null && answers[key] !== '') continue
    const val = context[key]
    if (val != null && val !== '') answers[key] = val
  }
  return answers
}

function matrixValues(raw) {
  if (raw == null) return []
  if (Array.isArray(raw)) {
    return raw.filter((v) => v != null && v !== '' && !Number.isNaN(Number(v))).map(Number)
  }
  if (typeof raw === 'object') {
    const keys = Object.keys(raw).sort((a, b) => Number(a) - Number(b))
    return keys
      .map((k) => raw[k])
      .filter((v) => v != null && v !== '' && !Number.isNaN(Number(v)))
      .map(Number)
  }
  return []
}

/** Collect numeric rating values for a question from answers. */
export function collectQuestionValues(question, answers) {
  if (!question || !answers) return []
  if (question.type === 'matrix') {
    return matrixValues(answers[question.id])
  }
  if (question.type === 'rating') {
    const v = answers[question.id]
    if (v != null && v !== '' && !Number.isNaN(Number(v))) return [Number(v)]
  }
  return []
}

export function answersFromScores(scores) {
  if (!scores || typeof scores !== 'object') return {}
  if (scores.answers && typeof scores.answers === 'object') return { ...scores.answers }
  if (scores.survey_data && typeof scores.survey_data === 'object') return { ...scores.survey_data }
  return {}
}

/** Pack answers into API scores shape (answers + legacy survey_data mirror). */
export function packScores(definition, answers, computed = null) {
  const sectionsMirror = {}
  for (const section of definition?.sections || []) {
    sectionsMirror[section.title] = {}
    for (const q of section.questions || []) {
      if (q.type === 'rating') {
        const v = answers[q.id]
        if (v != null && v !== '') sectionsMirror[section.title][q.title] = v
      } else if (q.type === 'matrix') {
        const rows = q.rows || []
        rows.forEach((row, i) => {
          const cell = matrixValues(answers[q.id])[i]
          if (cell != null) sectionsMirror[section.title][row] = cell
        })
      }
    }
  }
  const payload = {
    sections: sectionsMirror,
    answers: { ...answers },
    survey_data: { ...answers },
  }
  if (computed?.overall_percentage != null) {
    payload.answers.overall_percentage = computed.overall_percentage
    payload.survey_data.overall_percentage = computed.overall_percentage
    for (const [key, val] of Object.entries(computed.section_scores || {})) {
      payload.answers[`${key}_score`] = val
      payload.survey_data[`${key}_score`] = val
    }
  }
  return payload
}

export function definitionForApiSave(definition) {
  return {
    version: FORM_DEFINITION_VERSION,
    intro_html: definition.intro_html || '',
    scale: definition.scale || DEFAULT_SCALE,
    info_fields: definition.info_fields || [...DEFAULT_INFO_FIELDS],
    sections: definition.sections || [],
    comments: definition.comments || [],
    signatures: definition.signatures || [],
  }
}
