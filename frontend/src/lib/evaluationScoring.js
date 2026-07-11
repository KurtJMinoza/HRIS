/** @typedef {{ key: string, matrix: string, ratings: string[], count: number, weight: number, max: number }} WeightedSection */

/** @type {WeightedSection[]} */
export const WEIGHTED_360_SECTIONS = [
  { key: 'quality', matrix: 'quality_of_work', ratings: ['quality_0', 'quality_1', 'quality_2'], count: 3, weight: 15, max: 5 },
  { key: 'productivity', matrix: 'productivity', ratings: ['productivity_0', 'productivity_1', 'productivity_2'], count: 3, weight: 15, max: 5 },
  { key: 'accountability', matrix: 'accountability', ratings: ['accountability_0', 'accountability_1', 'accountability_2'], count: 3, weight: 15, max: 5 },
  { key: 'communication', matrix: 'communication', ratings: ['communication_0', 'communication_1', 'communication_2'], count: 3, weight: 15, max: 5 },
  { key: 'problem_solving', matrix: 'problem_solving', ratings: ['problem_solving_0', 'problem_solving_1', 'problem_solving_2'], count: 3, weight: 10, max: 5 },
  { key: 'core_values', matrix: 'core_values', ratings: ['core_value_0', 'core_value_1', 'core_value_2', 'core_value_3', 'core_value_4', 'core_value_5', 'core_value_6'], count: 7, weight: 30, max: 5 },
]

function matrixSumExpression(matrix, count) {
  const parts = []
  for (let i = 0; i < count; i++) parts.push(`{${matrix}.${i}}`)
  return `(${parts.join(' + ')})`
}

function ratingSumExpression(ratings) {
  return `(${ratings.map((name) => `{${name}}`).join(' + ')})`
}

function sectionTitle(key, weight) {
  const titles = {
    quality: `A. Quality of Work (${weight}%)`,
    productivity: `B. Productivity & Results (${weight}%)`,
    accountability: `C. Accountability & Reliability (${weight}%)`,
    communication: `D. Communication & Collaboration (${weight}%)`,
    problem_solving: `E. Problem Solving & Initiative (${weight}%)`,
    core_values: `Core Values (${weight}%)`,
  }
  return titles[key] || `${key} (${weight}%)`
}

function surveyUsesMatrixQuestions(surveyJson) {
  let found = false
  const walk = (elements) => {
    for (const el of elements || []) {
      if (!el) continue
      if (el.type === 'panel') walk(el.elements)
      else if (el.type === 'matrix') found = true
    }
  }
  for (const page of surveyJson?.pages || []) walk(page.elements)
  return found
}

function buildSummaryExpressionElements(usesMatrix) {
  const elements = []
  const weightedRefs = []

  for (const section of WEIGHTED_360_SECTIONS) {
    const sumExpr = usesMatrix
      ? matrixSumExpression(section.matrix, section.count)
      : ratingSumExpression(section.ratings)
    const maxPossible = section.count * section.max
    const title = sectionTitle(section.key, section.weight)

    elements.push({
      type: 'expression',
      name: `${section.key}_score`,
      title,
      expression: `round((${sumExpr}) / ${maxPossible}, 2) * ${section.weight}`,
      displayStyle: 'decimal',
    })
    weightedRefs.push(`{${section.key}_score}`)
  }

  const overallExpr = weightedRefs.join(' + ')
  elements.push(
    { type: 'expression', name: 'overall_percentage', title: '★ OVERALL PERCENTAGE', expression: overallExpr, displayStyle: 'decimal' },
    { type: 'expression', name: 'overall_rating', title: 'Overall Performance Level', expression: "if({overall_percentage} >= 90, 'Outstanding', if({overall_percentage} >= 70, 'Very Good', if({overall_percentage} >= 50, 'Good', if({overall_percentage} >= 30, 'Needs Improvement', 'Unsatisfactory'))))" },
  )

  return elements
}

/** Replace summary-page expression questions with weighted section scoring. */
export function applyWeightedSummaryExpressions(surveyJson) {
  if (!surveyJson || !Array.isArray(surveyJson.pages)) return surveyJson
  const json = JSON.parse(JSON.stringify(surveyJson))
  const usesMatrix = surveyUsesMatrixQuestions(json)

  let summaryPageIndex = null
  for (let i = 0; i < json.pages.length; i++) {
    const title = String(json.pages[i]?.title || '').toLowerCase()
    if (title.includes('summary') || title.includes('signature')) {
      summaryPageIndex = i
      break
    }
  }
  if (summaryPageIndex === null) return json

  const page = json.pages[summaryPageIndex]
  let elements = Array.isArray(page.elements) ? page.elements : []
  elements = elements.filter((el) => el?.type !== 'expression')

  let insertAt = elements.length
  for (let i = 0; i < elements.length; i++) {
    if (elements[i]?.name === 'scoring_header') {
      insertAt = i + 1
      break
    }
  }

  elements.splice(insertAt, 0, ...buildSummaryExpressionElements(usesMatrix))
  json.pages[summaryPageIndex].elements = elements
  return json
}

function collectSectionValues(surveyData, section) {
  const matrixRaw = surveyData?.[section.matrix]
  if (matrixRaw && typeof matrixRaw === 'object') {
    const vals = []
    for (let i = 0; i < section.count; i++) {
      const cell = matrixRaw[i] ?? matrixRaw[String(i)]
      if (cell !== undefined && cell !== null && cell !== '' && !Number.isNaN(Number(cell))) {
        vals.push(Number(cell))
      }
    }
    if (vals.length > 0) return vals
  }

  const vals = []
  for (const name of section.ratings) {
    const cell = surveyData?.[name]
    if (cell !== undefined && cell !== null && cell !== '' && !Number.isNaN(Number(cell))) {
      vals.push(Number(cell))
    }
  }
  return vals
}

export function ratingLabelFromPercentage(percentage) {
  if (percentage >= 90) return 'Outstanding'
  if (percentage >= 70) return 'Very Good'
  if (percentage >= 50) return 'Good'
  if (percentage >= 30) return 'Needs Improvement'
  return 'Unsatisfactory'
}

/** Overall % from stored survey result, or recomputed from answers when missing. */
export function evalOverallPercentage(ev) {
  const stored = ev?.scores?.survey_data?.overall_percentage
  if (stored !== undefined && stored !== null && stored !== '') {
    return Math.round(Number(stored) * 100) / 100
  }

  const surveyData = ev?.scores?.survey_data ?? ev?.scores
  const computed = computeWeightedScoresFromSurveyData(surveyData)
  if (computed?.overall_percentage != null) {
    return computed.overall_percentage
  }

  if (ev?.overall_score != null) {
    return Math.round(Number(ev.overall_score) * 20 * 100) / 100
  }
  return null
}

/** Rating label from evaluation record or derived from overall %. */
export function evalDisplayRating(ev) {
  if (ev?.overall_rating) return ev.overall_rating
  const pct = evalOverallPercentage(ev)
  return pct != null ? ratingLabelFromPercentage(pct) : null
}

/** @returns {{ overall_percentage: number, overall_score: number, overall_rating: string, section_scores: Record<string, number> } | null} */
export function computeWeightedScoresFromSurveyData(surveyData) {
  if (!surveyData || typeof surveyData !== 'object') return null

  const sectionScores = {}
  let overallPercentage = 0

  for (const section of WEIGHTED_360_SECTIONS) {
    const values = collectSectionValues(surveyData, section)
    if (values.length === 0) continue

    const total = values.reduce((sum, v) => sum + v, 0)
    const maxPossible = values.length * section.max
    if (maxPossible <= 0) continue

    const normalized = Math.round((total / maxPossible) * 100) / 100
    const weighted = Math.round(normalized * section.weight * 100) / 100
    sectionScores[section.key] = weighted
    overallPercentage += weighted
  }

  if (Object.keys(sectionScores).length === 0) return null

  overallPercentage = Math.round(overallPercentage * 100) / 100
  const equivalentRating = Math.round((overallPercentage / 20) * 100) / 100

  return {
    overall_percentage: overallPercentage,
    overall_score: equivalentRating,
    overall_rating: ratingLabelFromPercentage(overallPercentage),
    section_scores: sectionScores,
  }
}

// ponytail: self-check — all 5s should yield 100% and 5.0 equivalent
if (import.meta.env?.DEV) {
  const perfect = {}
  for (const section of WEIGHTED_360_SECTIONS) {
    if (section.ratings) {
      for (const name of section.ratings) perfect[name] = 5
    }
  }
  const result = computeWeightedScoresFromSurveyData(perfect)
  console.assert(result?.overall_percentage === 100, 'perfect scores should yield 100%')
  console.assert(result?.overall_score === 5, 'perfect scores should yield 5.0 equivalent rating')

  const qualityExample = { quality_0: 5, quality_1: 4, quality_2: 4 }
  const qOnly = computeWeightedScoresFromSurveyData(qualityExample)
  console.assert(qOnly?.section_scores?.quality === 13.05, 'quality 13/15 should weighted to 13.05%')
}
