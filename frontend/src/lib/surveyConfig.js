import { Serializer } from 'survey-core'
import 'survey-core/survey-core.min.css'
import 'survey-creator-core/survey-creator-core.min.css'

// ─── Custom Properties ─────────────────────────────────────────────

// Register a custom "weight" property on Panels so admins can configure
// a section's contribution to the overall evaluation score (0-100%).
Serializer.addProperty('panel', {
  name: 'weight:number',
  displayName: 'Section Weight (%)',
  category: 'general',
  default: 0,
  min: 0,
  max: 100,
  description: 'Relative weight of this section in the overall score.',
})

// ─── Curated Toolbox Configuration ─────────────────────────────────

// Items to show in the toolbox (SurveyJS element type name → display label)
export const CURATED_TOOLBOX = [
  { name: 'rating', title: 'Rating' },
  { name: 'radiogroup', title: 'Radio Group' },
  { name: 'checkbox', title: 'Checkbox' },
  { name: 'dropdown', title: 'Dropdown' },
  { name: 'comment', title: 'Comment' },
  { name: 'text', title: 'Text' },
  { name: 'matrix', title: 'Matrix' },
  { name: 'matrixdropdown', title: 'Matrix Dropdown' },
  { name: 'panel', title: 'Panel' },
  { name: 'paneldynamic', title: 'Panel Dynamic' },
  { name: 'expression', title: 'Expression' },
  { name: 'html', title: 'HTML' },
  { name: 'signaturepad', title: 'Signature' },
  { name: 'file', title: 'File Upload' },
]

// Apply curated toolbox to a SurveyCreator instance
export function applyCuratedToolbox(creator) {
  if (!creator || !creator.toolbox) return
  try {
    const allItems = creator.toolbox.getAllItems()
    if (!allItems || !allItems.length) return
    const allowedNames = new Set(CURATED_TOOLBOX.map(i => i.name))
    for (const item of allItems) {
      if (item && !allowedNames.has(item.name)) {
        creator.toolbox.removeItem(item.name)
      }
    }
    // Rename items for clarity
    for (const curated of CURATED_TOOLBOX) {
      try {
        const item = creator.toolbox.getItemByName(curated.name)
        if (item) item.title = curated.title
      } catch {
        // item may not exist in the current version
      }
    }
  } catch {
    // toolbox may not be fully initialized yet
  }
}

// ─── Blank Template ────────────────────────────────────────────────

export const BLANK_SURVEY_JSON = {
  titleLocation: 'top',
  showQuestionNumbers: 'off',
  pageNextText: 'Next →',
  pagePrevText: '← Previous',
  completeText: 'Submit Evaluation',
  pages: [
    {
      name: 'page1',
      title: 'Page 1',
      elements: [
        {
          type: 'rating',
          name: 'q_rating',
          title: 'Rate this item',
          rateMin: 1,
          rateMax: 5,
          minRateDescription: 'Poor',
          maxRateDescription: 'Excellent',
        },
      ],
    },
  ],
}

// ─── AGC 360° Performance Feedback Template (Fully Implemented) ──
// Matches the exact AMALGATED GROUP OF COMPANIES specification:
//   • Confidentiality & objectives page
//   • Employee Information with relationship selector
//   • Rating Scale reference
//   • Part I – Job Performance (70%) — 5 sections × 3 criteria each
//   • Part II – Core Values (30%) — 7 values
//   • Part III – Comments
//   • Scoring Summary with weighted formula & auto-rating
//   • Signatures (evaluator + HR)

export const TEMPLATE_360_FEEDBACK = {
  title: '360-DEGREE PERFORMANCE FEEDBACK SURVEY',
  description: 'Multi-rater feedback from supervisors, peers, and subordinates — the classic 360-degree assessment for AMALGATED GROUP OF COMPANIES.',
  showQuestionNumbers: 'off',
  showProgressBar: 'aboveHeader',
  progressBarType: 'pages',
  pageNextText: 'Next →',
  pagePrevText: '← Previous',
  completeText: 'Submit Evaluation',
  showPreviewBeforeComplete: 'showAllQuestions',
  pages: [
    // ═══════════════════════════════════════════════════════════════
    // PAGE 1 – Confidentiality & Objectives
    // ═══════════════════════════════════════════════════════════════
    {
      name: 'page1_confidentiality',
      title: 'Confidentiality',
      elements: [
        {
          type: 'html',
          name: 'confidentiality_content',
          html: `<div style="text-align:center;padding:1.5rem 0;">
  <p style="font-size:10px;font-weight:700;letter-spacing:0.3em;color:#64748b;text-transform:uppercase;margin-bottom:0.4rem;">Amalgated Group of Companies</p>
  <h1 style="font-size:1.6rem;font-weight:900;color:#0f172a;margin:0.5rem 0;line-height:1.2;">360-DEGREE PERFORMANCE<br>FEEDBACK SURVEY</h1>
  <div style="border-top:3px solid #dc2626;width:70px;margin:1rem auto;"></div>
  <p style="font-size:1rem;font-weight:700;color:#dc2626;letter-spacing:0.15em;">STRICTLY CONFIDENTIAL</p>
  <div style="margin-top:1.5rem;text-align:left;max-width:42rem;margin-left:auto;margin-right:auto;font-size:0.88rem;color:#475569;line-height:1.7;">
    <p>This <strong>360-Degree Performance Feedback Survey</strong> is strictly confidential and intended solely for performance development, employee growth, and organizational improvement purposes. All responses will be treated with the highest level of confidentiality and will only be used to support constructive feedback, professional development, leadership effectiveness, and the achievement of the company's strategic goals.</p>
    <p>The objective of this survey is to provide a fair, comprehensive, and balanced assessment of employee performance based on feedback from supervisors, peers, direct reports, internal clients, and self-evaluation. The results will help strengthen individual performance, reinforce the <strong>AMALGATED WAY</strong> Core Values, enhance teamwork, and contribute to the continuous improvement and success of the Amalgated Group of Companies.</p>
    <p>We encourage all evaluators to provide honest, objective, and professional feedback that will help employees and the organization grow together.</p>
  </div>
</div>`,
        },
      ],
    },

    // ═══════════════════════════════════════════════════════════════
    // PAGE 2 – Employee Information
    // ═══════════════════════════════════════════════════════════════
    {
      name: 'page2_employee_info',
      title: 'Employee Information',
      elements: [
        {
          type: 'text',
          name: 'employee_name',
          title: 'Employee Name',
          isRequired: true,
          placeholder: 'Enter employee full name',
        },
        {
          type: 'text',
          name: 'position',
          title: 'Position',
          placeholder: 'Enter job title / position',
        },
        {
          type: 'text',
          name: 'department',
          title: 'Department / Business Unit',
          placeholder: 'Enter department or business unit',
        },
        {
          type: 'text',
          name: 'evaluation_period',
          title: 'Evaluation Period',
          isRequired: true,
          placeholder: 'e.g. Q1 2026',
        },
        {
          type: 'text',
          name: 'evaluator_name',
          title: 'Evaluator',
          isRequired: true,
          placeholder: 'Enter your full name',
        },
        {
          type: 'radiogroup',
          name: 'relationship',
          title: 'Relationship to Employee',
          isRequired: true,
          colCount: 1,
          choices: [
            'Immediate Supervisor',
            'Peer / Co-worker',
            'Direct Report',
            'Internal Client',
            'Self-Assessment',
          ],
        },
      ],
    },

    // ═══════════════════════════════════════════════════════════════
    // PAGE 3 – Rating Scale (Information Only)
    // ═══════════════════════════════════════════════════════════════
    {
      name: 'page3_rating_scale',
      title: 'Rating Scale',
      elements: [
        {
          type: 'html',
          name: 'rating_scale_table',
          html: `<div style="padding:0.5rem 0;">
  <h3 style="font-size:1.1rem;font-weight:700;color:#0f172a;margin-bottom:1rem;">RATING SCALE</h3>
  <table style="width:100%;border-collapse:collapse;font-size:0.88rem;border:1px solid #e2e8f0;">
    <thead>
      <tr style="background:#1e293b;color:#fff;">
        <th style="padding:0.6rem 1rem;text-align:left;font-weight:600;">Rating</th>
        <th style="padding:0.6rem 1rem;text-align:left;font-weight:600;">Description</th>
      </tr>
    </thead>
    <tbody>
      <tr style="border-bottom:1px solid #e2e8f0;">
        <td style="padding:0.55rem 1rem;font-weight:700;color:#16a34a;text-align:center;">5</td>
        <td style="padding:0.55rem 1rem;"><strong>Outstanding</strong> – Consistently exceeds expectations</td>
      </tr>
      <tr style="border-bottom:1px solid #e2e8f0;background:#f8fafc;">
        <td style="padding:0.55rem 1rem;font-weight:700;color:#0284c7;text-align:center;">4</td>
        <td style="padding:0.55rem 1rem;"><strong>Very Good</strong> – Frequently exceeds expectations</td>
      </tr>
      <tr style="border-bottom:1px solid #e2e8f0;">
        <td style="padding:0.55rem 1rem;font-weight:700;color:#f59e0b;text-align:center;">3</td>
        <td style="padding:0.55rem 1rem;"><strong>Good</strong> – Meets expectations</td>
      </tr>
      <tr style="border-bottom:1px solid #e2e8f0;background:#f8fafc;">
        <td style="padding:0.55rem 1rem;font-weight:700;color:#f97316;text-align:center;">2</td>
        <td style="padding:0.55rem 1rem;"><strong>Needs Improvement</strong> – Occasionally falls below expectations</td>
      </tr>
      <tr style="border-bottom:1px solid #e2e8f0;">
        <td style="padding:0.55rem 1rem;font-weight:700;color:#dc2626;text-align:center;">1</td>
        <td style="padding:0.55rem 1rem;"><strong>Unsatisfactory</strong> – Consistently below expectations</td>
      </tr>
      <tr style="background:#f8fafc;">
        <td style="padding:0.55rem 1rem;font-weight:700;color:#94a3b8;text-align:center;">N/A</td>
        <td style="padding:0.55rem 1rem;color:#94a3b8;"><strong>Not Applicable</strong></td>
      </tr>
    </tbody>
  </table>
  <p style="margin-top:0.75rem;font-size:0.85rem;color:#94a3b8;font-style:italic;">Please select the rating that best reflects the employee's performance for each criterion.</p>
</div>`,
        },
      ],
    },

    // ═══════════════════════════════════════════════════════════════
    // PAGE 4 – PART I: JOB PERFORMANCE (70%)
    // ═══════════════════════════════════════════════════════════════
    {
      name: 'page4_job_performance',
      title: 'Part I – Job Performance (70%)',
      elements: [
        // Section header
        {
          type: 'html',
          name: 'part_i_header',
          html: `<div style="padding:0.25rem 0 0.75rem 0;">
  <h2 style="font-size:1.2rem;font-weight:800;color:#0f172a;">PART I – JOB PERFORMANCE</h2>
  <p style="font-size:0.85rem;color:#64748b;font-weight:600;"><span style="color:#dc2626;">Weight: 70%</span> of Total Score</p>
</div>`,
        },

        // A. Quality of Work (15%)
        {
          type: 'panel',
          name: 'panel_quality',
          title: 'A. Quality of Work (15%)',
          elements: [
            {
              type: 'rating',
              name: 'quality_0',
              title: 'Produces accurate, complete, and high-quality work.',
              rateMin: 1,
              rateMax: 5,
              rateStep: 1,
              minRateDescription: '1 - Unsatisfactory',
              maxRateDescription: '5 - Outstanding',
              isRequired: true,
            },
            {
              type: 'rating',
              name: 'quality_1',
              title: 'Maintains attention to detail.',
              rateMin: 1,
              rateMax: 5,
              rateStep: 1,
              isRequired: true,
            },
            {
              type: 'rating',
              name: 'quality_2',
              title: 'Consistently meets company standards.',
              rateMin: 1,
              rateMax: 5,
              rateStep: 1,
              isRequired: true,
            },
          ],
        },

        // B. Productivity & Results (15%)
        {
          type: 'panel',
          name: 'panel_productivity',
          title: 'B. Productivity & Results (15%)',
          elements: [
            {
              type: 'rating',
              name: 'productivity_0',
              title: 'Meets assigned targets and objectives.',
              rateMin: 1,
              rateMax: 5,
              rateStep: 1,
              isRequired: true,
            },
            {
              type: 'rating',
              name: 'productivity_1',
              title: 'Completes work within deadlines.',
              rateMin: 1,
              rateMax: 5,
              rateStep: 1,
              isRequired: true,
            },
            {
              type: 'rating',
              name: 'productivity_2',
              title: 'Uses time efficiently.',
              rateMin: 1,
              rateMax: 5,
              rateStep: 1,
              isRequired: true,
            },
          ],
        },

        // C. Accountability & Reliability (15%)
        {
          type: 'panel',
          name: 'panel_accountability',
          title: 'C. Accountability & Reliability (15%)',
          elements: [
            {
              type: 'rating',
              name: 'accountability_0',
              title: 'Takes ownership of assigned responsibilities.',
              rateMin: 1,
              rateMax: 5,
              rateStep: 1,
              isRequired: true,
            },
            {
              type: 'rating',
              name: 'accountability_1',
              title: 'Can be relied upon to complete tasks.',
              rateMin: 1,
              rateMax: 5,
              rateStep: 1,
              isRequired: true,
            },
            {
              type: 'rating',
              name: 'accountability_2',
              title: 'Follows company policies and procedures.',
              rateMin: 1,
              rateMax: 5,
              rateStep: 1,
              isRequired: true,
            },
          ],
        },

        // D. Communication & Collaboration (15%)
        {
          type: 'panel',
          name: 'panel_communication',
          title: 'D. Communication & Collaboration (15%)',
          elements: [
            {
              type: 'rating',
              name: 'communication_0',
              title: 'Communicates effectively with others.',
              rateMin: 1,
              rateMax: 5,
              rateStep: 1,
              isRequired: true,
            },
            {
              type: 'rating',
              name: 'communication_1',
              title: 'Cooperates with teammates and other departments.',
              rateMin: 1,
              rateMax: 5,
              rateStep: 1,
              isRequired: true,
            },
            {
              type: 'rating',
              name: 'communication_2',
              title: 'Maintains professionalism in the workplace.',
              rateMin: 1,
              rateMax: 5,
              rateStep: 1,
              isRequired: true,
            },
          ],
        },

        // E. Problem Solving & Initiative (10%)
        {
          type: 'panel',
          name: 'panel_problem_solving',
          title: 'E. Problem Solving & Initiative (10%)',
          elements: [
            {
              type: 'rating',
              name: 'problem_solving_0',
              title: 'Identifies problems and recommends solutions.',
              rateMin: 1,
              rateMax: 5,
              rateStep: 1,
              isRequired: true,
            },
            {
              type: 'rating',
              name: 'problem_solving_1',
              title: 'Takes initiative without waiting for instructions.',
              rateMin: 1,
              rateMax: 5,
              rateStep: 1,
              isRequired: true,
            },
            {
              type: 'rating',
              name: 'problem_solving_2',
              title: 'Adapts positively to change.',
              rateMin: 1,
              rateMax: 5,
              rateStep: 1,
              isRequired: true,
            },
          ],
        },
      ],
    },

    // ═══════════════════════════════════════════════════════════════
    // PAGE 5 – PART II: AMALGATED WAY CORE VALUES (30%)
    // ═══════════════════════════════════════════════════════════════
    {
      name: 'page5_core_values',
      title: 'Part II – Core Values (30%)',
      elements: [
        {
          type: 'html',
          name: 'part_ii_header',
          html: `<div style="padding:0.25rem 0 0.75rem 0;">
  <h2 style="font-size:1.2rem;font-weight:800;color:#0f172a;">PART II – AMALGATED WAY CORE VALUES</h2>
  <p style="font-size:0.85rem;color:#64748b;">Evaluate how consistently the employee demonstrates the following Core Values.</p>
  <p style="font-size:0.85rem;color:#64748b;font-weight:600;"><span style="color:#dc2626;">Weight: 30%</span> of Total Score</p>
</div>`,
        },
        {
          type: 'rating',
          name: 'core_value_0',
          title: 'Compassion – Treats people with respect, empathy, and care.',
          rateMin: 1,
          rateMax: 5,
          rateStep: 1,
          isRequired: true,
        },
        {
          type: 'rating',
          name: 'core_value_1',
          title: 'Leadership – Leads by example and inspires others.',
          rateMin: 1,
          rateMax: 5,
          rateStep: 1,
          isRequired: true,
        },
        {
          type: 'rating',
          name: 'core_value_2',
          title: 'Integrity – Demonstrates honesty, accountability, and ethical behavior.',
          rateMin: 1,
          rateMax: 5,
          rateStep: 1,
          isRequired: true,
        },
        {
          type: 'rating',
          name: 'core_value_3',
          title: 'Excellence – Strives for quality and continuous improvement.',
          rateMin: 1,
          rateMax: 5,
          rateStep: 1,
          isRequired: true,
        },
        {
          type: 'rating',
          name: 'core_value_4',
          title: 'Nurtureship – Develops and supports the growth of others.',
          rateMin: 1,
          rateMax: 5,
          rateStep: 1,
          isRequired: true,
        },
        {
          type: 'rating',
          name: 'core_value_5',
          title: 'Teamwork – Works collaboratively and promotes unity.',
          rateMin: 1,
          rateMax: 5,
          rateStep: 1,
          isRequired: true,
        },
        {
          type: 'rating',
          name: 'core_value_6',
          title: 'Sense of Urgency – Responds promptly and completes work on time.',
          rateMin: 1,
          rateMax: 5,
          rateStep: 1,
          isRequired: true,
        },
      ],
    },

    // ═══════════════════════════════════════════════════════════════
    // PAGE 6 – PART III: COMMENTS
    // ═══════════════════════════════════════════════════════════════
    {
      name: 'page6_comments',
      title: 'Part III – Comments',
      elements: [
        {
          type: 'html',
          name: 'part_iii_header',
          html: `<div style="padding:0.25rem 0 0.5rem 0;">
  <h2 style="font-size:1.2rem;font-weight:800;color:#0f172a;">PART III – COMMENTS</h2>
  <p style="font-size:0.85rem;color:#64748b;">Please provide your qualitative feedback for the employee being evaluated.</p>
</div>`,
        },
        {
          type: 'comment',
          name: 'key_strengths',
          title: 'Key Strengths',
          placeholder: 'What are the employee\'s key strengths and professional assets?',
          maxLength: 2000,
        },
        {
          type: 'comment',
          name: 'areas_for_improvement',
          title: 'Areas for Improvement',
          placeholder: 'What areas could the employee develop further?',
          maxLength: 2000,
        },
        {
          type: 'comment',
          name: 'development_recommendations',
          title: 'Development Recommendations',
          placeholder: 'What training, resources, or support would help the employee grow?',
          maxLength: 2000,
        },
        {
          type: 'comment',
          name: 'additional_comments',
          title: 'Additional Comments',
          placeholder: 'Any other feedback or observations you would like to share.',
          maxLength: 3000,
        },
      ],
    },

    // ═══════════════════════════════════════════════════════════════
    // PAGE 7 – SCORING SUMMARY & SIGNATURE
    // ═══════════════════════════════════════════════════════════════
    {
      name: 'page7_summary',
      title: 'Summary & Signature',
      elements: [
        // ── Header ──
        {
          type: 'html',
          name: 'scoring_header',
          html: `<h2 style="font-size:1.2rem;font-weight:800;color:#0f172a;margin-bottom:0.25rem;">SCORING SUMMARY</h2>
<p style="font-size:0.85rem;color:#64748b;margin-bottom:0.75rem;">The scores below are automatically calculated based on your ratings above.</p>`,
        },

        // ── Section Scores ──
        {
          type: 'expression',
          name: 'quality_score',
          title: 'A. Quality of Work (15%)',
          expression: '({quality_0} + {quality_1} + {quality_2}) / 3',
          displayStyle: 'decimal',
        },
        {
          type: 'expression',
          name: 'productivity_score',
          title: 'B. Productivity & Results (15%)',
          expression: '({productivity_0} + {productivity_1} + {productivity_2}) / 3',
          displayStyle: 'decimal',
        },
        {
          type: 'expression',
          name: 'accountability_score',
          title: 'C. Accountability & Reliability (15%)',
          expression: '({accountability_0} + {accountability_1} + {accountability_2}) / 3',
          displayStyle: 'decimal',
        },
        {
          type: 'expression',
          name: 'communication_score',
          title: 'D. Communication & Collaboration (15%)',
          expression: '({communication_0} + {communication_1} + {communication_2}) / 3',
          displayStyle: 'decimal',
        },
        {
          type: 'expression',
          name: 'problem_solving_score',
          title: 'E. Problem Solving & Initiative (10%)',
          expression: '({problem_solving_0} + {problem_solving_1} + {problem_solving_2}) / 3',
          displayStyle: 'decimal',
        },

        // Core Values Score
        {
          type: 'expression',
          name: 'core_values_score',
          title: 'Core Values (30%)',
          expression: '({core_value_0} + {core_value_1} + {core_value_2} + {core_value_3} + {core_value_4} + {core_value_5} + {core_value_6}) / 7',
          displayStyle: 'decimal',
        },

        // Overall Job Performance average (all 15 ratings)
        {
          type: 'expression',
          name: 'job_performance_score',
          title: 'Job Performance Score (average of all 15 criteria)',
          expression: '({quality_0} + {quality_1} + {quality_2} + {productivity_0} + {productivity_1} + {productivity_2} + {accountability_0} + {accountability_1} + {accountability_2} + {communication_0} + {communication_1} + {communication_2} + {problem_solving_0} + {problem_solving_1} + {problem_solving_2}) / 15',
          displayStyle: 'decimal',
        },

        // Weighted Job Performance (× 0.70)
        {
          type: 'expression',
          name: 'weighted_job_score',
          title: 'Job Performance Score × 70%',
          expression: '{job_performance_score} * 0.70',
          displayStyle: 'decimal',
        },

        // Weighted Core Values (× 0.30)
        {
          type: 'expression',
          name: 'weighted_core_score',
          title: 'Core Values Score × 30%',
          expression: '{core_values_score} * 0.30',
          displayStyle: 'decimal',
        },

        // Final Score
        {
          type: 'expression',
          name: 'final_score',
          title: '★ TOTAL PERFORMANCE SCORE',
          expression: '{weighted_job_score} + {weighted_core_score}',
          displayStyle: 'decimal',
        },

        // Overall Percentage (score / 5 × 100)
        {
          type: 'expression',
          name: 'overall_percentage',
          title: '★ OVERALL PERCENTAGE',
          expression: '({final_score} / 5) * 100',
          displayStyle: 'percent',
        },

        // Automatic Rating
        {
          type: 'expression',
          name: 'overall_rating',
          title: 'Overall Performance Level',
          expression: "if({final_score} >= 4.5, 'Outstanding', if({final_score} >= 3.5, 'Very Good', if({final_score} >= 2.5, 'Good', if({final_score} >= 1.5, 'Needs Improvement', 'Unsatisfactory'))))",
        },

        // Separator
        { type: 'html', name: 'sep3', html: '<hr style="margin:1rem 0;border:none;border-top:1px solid #e2e8f0;" />' },

        // ── Performance Rating Guide ──
        {
          type: 'html',
          name: 'rating_guide',
          html: `<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:0.75rem 1rem;">
  <h3 style="font-size:0.95rem;font-weight:700;color:#0f172a;margin-bottom:0.5rem;">PERFORMANCE RATING GUIDE</h3>
  <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
    <thead>
      <tr style="border-bottom:1px solid #e2e8f0;">
        <th style="padding:0.3rem 0.5rem;text-align:left;font-weight:600;color:#475569;">Final Score</th>
        <th style="padding:0.3rem 0.5rem;text-align:left;font-weight:600;color:#475569;">Performance Level</th>
      </tr>
    </thead>
    <tbody>
      <tr><td style="padding:0.3rem 0.5rem;font-weight:600;color:#16a34a;">4.50 – 5.00</td><td style="padding:0.3rem 0.5rem;">Outstanding</td></tr>
      <tr style="background:#f1f5f9;"><td style="padding:0.3rem 0.5rem;font-weight:600;color:#0284c7;">3.50 – 4.49</td><td style="padding:0.3rem 0.5rem;">Very Good</td></tr>
      <tr><td style="padding:0.3rem 0.5rem;font-weight:600;color:#f59e0b;">2.50 – 3.49</td><td style="padding:0.3rem 0.5rem;">Good</td></tr>
      <tr style="background:#f1f5f9;"><td style="padding:0.3rem 0.5rem;font-weight:600;color:#f97316;">1.50 – 2.49</td><td style="padding:0.3rem 0.5rem;">Needs Improvement</td></tr>
      <tr><td style="padding:0.3rem 0.5rem;font-weight:600;color:#dc2626;">1.00 – 1.49</td><td style="padding:0.3rem 0.5rem;">Unsatisfactory</td></tr>
    </tbody>
  </table>
</div>`,
        },

        // Separator
        { type: 'html', name: 'sep4', html: '<hr style="margin:1.25rem 0 0.75rem 0;border:none;border-top:1px solid #e2e8f0;" />' },

        // ── Signatures ──
        {
          type: 'signaturepad',
          name: 'evaluator_signature',
          title: "Evaluator's Signature",
          isRequired: true,
          placeholder: 'Sign here...',
        },
        {
          type: 'text',
          name: 'evaluator_date',
          title: 'Date',
          inputType: 'date',
          isRequired: true,
        },
        { type: 'html', name: 'sep5', html: '<hr style="margin:0.75rem 0;" />' },
        {
          type: 'signaturepad',
          name: 'hr_review_signature',
          title: 'Reviewed by HR',
          isRequired: false,
          placeholder: 'Sign here...',
        },
        {
          type: 'text',
          name: 'hr_date',
          title: 'Date',
          inputType: 'date',
        },
      ],
    },
  ],

  // ── Completion Page ──
  completedHtml: `<div style="text-align:center;padding:3rem 1.5rem;">
  <div style="font-size:3rem;margin-bottom:1rem;">✓</div>
  <h2 style="color:#16a34a;font-size:1.5rem;font-weight:800;margin-bottom:0.5rem;">Evaluation Submitted</h2>
  <p style="color:#64748b;font-size:0.95rem;max-width:30rem;margin:0 auto;line-height:1.6;">
    Thank you for completing this 360-Degree Performance Feedback Survey. Your honest and constructive feedback is valuable and appreciated. Your response will contribute to the professional growth of your colleague and the success of the Amalgated Group of Companies.
  </p>
</div>`,
}

// ─── Other Template Presets ────────────────────────────────────────

export const PROBATIONARY_EVALUATION_TEMPLATE = {
  title: 'Probationary Evaluation',
  description: 'Regularization assessment for probationary employees — competencies, attendance, and cultural fit.',
  showQuestionNumbers: 'off',
  showProgressBar: 'aboveHeader',
  progressBarType: 'pages',
  pages: [
    {
      name: 'page1_info',
      title: 'Employee Information',
      elements: [
        {
          type: 'html',
          name: 'header',
          html: '<h2 style="text-align:center;">AMALGAMATED GROUP OF COMPANIES</h2><h3 style="text-align:center;">PROBATIONARY EVALUATION</h3><p style="text-align:center;color:#dc2626;font-weight:bold;">STRICTLY CONFIDENTIAL</p>',
        },
        { type: 'text', name: 'employee_name', title: 'Employee Name', readOnly: true },
        { type: 'text', name: 'position', title: 'Position', readOnly: true },
        { type: 'text', name: 'department', title: 'Department', readOnly: true },
        { type: 'text', name: 'probation_start', title: 'Probation Start Date', readOnly: true, inputType: 'date' },
        { type: 'text', name: 'probation_end', title: 'Probation End Date', readOnly: true, inputType: 'date' },
      ],
    },
    {
      name: 'page2_performance',
      title: 'Job Performance',
      elements: [
        {
          type: 'matrix',
          name: 'probation_performance',
          title: 'Performance Criteria',
          columns: [{ value: 1, text: '1' }, { value: 2, text: '2' }, { value: 3, text: '3' }, { value: 4, text: '4' }, { value: 5, text: '5' }],
          rows: [
            'Ability to perform assigned duties effectively.',
            'Quality and accuracy of work output.',
            'Efficiency and time management.',
            'Willingness to learn and improve.',
            'Attendance and punctuality.',
          ],
          isAllRowRequired: true,
        },
      ],
    },
    {
      name: 'page3_cultural_fit',
      title: 'Cultural Fit & Assessment',
      elements: [
        {
          type: 'matrix',
          name: 'cultural_fit',
          title: 'Cultural Fit',
          columns: [{ value: 1, text: '1' }, { value: 2, text: '2' }, { value: 3, text: '3' }, { value: 4, text: '4' }, { value: 5, text: '5' }],
          rows: [
            'Aligns with company values and culture.',
            'Works well with team members.',
            'Demonstrates professionalism and positive attitude.',
          ],
          isAllRowRequired: true,
        },
        {
          type: 'comment',
          name: 'overall_assessment',
          title: 'Overall Assessment & Recommendation',
          placeholder: 'Provide your recommendation for regularization...',
        },
        {
          type: 'signaturepad',
          name: 'evaluator_signature',
          title: 'Evaluator Signature',
          isRequired: true,
        },
      ],
    },
  ],
  completedHtml: '<h3>Probationary Evaluation Submitted</h3>',
}

export const LEADERSHIP_EVALUATION_TEMPLATE = {
  title: 'Leadership Assessment',
  description: 'Evaluate management and leadership competencies — strategic thinking, team development, and decision-making.',
  showQuestionNumbers: 'off',
  pages: [
    {
      name: 'page1',
      title: 'Strategic Leadership',
      elements: [
        {
          type: 'matrix',
          name: 'strategic_leadership',
          columns: [{ value: 1, text: '1' }, { value: 2, text: '2' }, { value: 3, text: '3' }, { value: 4, text: '4' }, { value: 5, text: '5' }],
          rows: [
            'Demonstrates strategic thinking and vision.',
            'Makes sound decisions based on analysis and judgment.',
            'Effectively manages change and drives innovation.',
            'Optimizes resource allocation and utilization.',
          ],
        },
      ],
    },
    {
      name: 'page2',
      title: 'Team Development',
      elements: [
        {
          type: 'matrix',
          name: 'team_development',
          columns: [{ value: 1, text: '1' }, { value: 2, text: '2' }, { value: 3, text: '3' }, { value: 4, text: '4' }, { value: 5, text: '5' }],
          rows: [
            'Builds and develops high-performing teams.',
            'Coaches and mentors team members effectively.',
            'Delegates authority and empowers others.',
            'Resolves conflicts constructively.',
          ],
        },
      ],
    },
    {
      name: 'page3',
      title: 'Results & Comments',
      elements: [
        {
          type: 'matrix',
          name: 'results',
          columns: [{ value: 1, text: '1' }, { value: 2, text: '2' }, { value: 3, text: '3' }, { value: 4, text: '4' }, { value: 5, text: '5' }],
          rows: [
            'Achieves organizational goals and targets.',
            'Drives team performance and engagement.',
            'Maintains strong stakeholder satisfaction.',
          ],
        },
        { type: 'comment', name: 'leadership_comments', title: 'Additional Comments' },
      ],
    },
  ],
}

export const CUSTOMER_SERVICE_EVALUATION_TEMPLATE = {
  title: 'Customer Service Evaluation',
  description: 'Assess customer-facing skills: service quality, problem resolution, and client satisfaction.',
  showQuestionNumbers: 'off',
  pages: [
    {
      name: 'page1',
      title: 'Service Quality',
      elements: [
        {
          type: 'matrix',
          name: 'service_quality',
          columns: [{ value: 1, text: '1' }, { value: 2, text: '2' }, { value: 3, text: '3' }, { value: 4, text: '4' }, { value: 5, text: '5' }],
          rows: [
            'Quality of customer interactions and service delivery.',
            'First-contact resolution rate.',
            'Product and service knowledge.',
            'Response time adherence.',
          ],
        },
      ],
    },
    {
      name: 'page2',
      title: 'Customer Satisfaction',
      elements: [
        {
          type: 'matrix',
          name: 'customer_satisfaction',
          columns: [{ value: 1, text: '1' }, { value: 2, text: '2' }, { value: 3, text: '3' }, { value: 4, text: '4' }, { value: 5, text: '5' }],
          rows: [
            'Customer satisfaction survey scores.',
            'Handling of difficult situations and complaints.',
          ],
        },
        { type: 'comment', name: 'feedback', title: 'Additional Feedback' },
      ],
    },
  ],
}

export const TECHNICAL_COMPETENCY_TEMPLATE = {
  title: 'Technical Competency Evaluation',
  description: 'Assess technical skills, certifications, project delivery, and domain expertise.',
  showQuestionNumbers: 'off',
  pages: [
    {
      name: 'page1',
      title: 'Technical Skills',
      elements: [
        {
          type: 'matrix',
          name: 'technical_skills',
          columns: [{ value: 1, text: '1' }, { value: 2, text: '2' }, { value: 3, text: '3' }, { value: 4, text: '4' }, { value: 5, text: '5' }],
          rows: [
            'Depth of technical knowledge and expertise.',
            'Quality of technical deliverables.',
            'Problem-solving and troubleshooting ability.',
            'Adherence to best practices and standards.',
          ],
        },
      ],
    },
    {
      name: 'page2',
      title: 'Professional Development',
      elements: [
        {
          type: 'matrix',
          name: 'professional_dev',
          columns: [{ value: 1, text: '1' }, { value: 2, text: '2' }, { value: 3, text: '3' }, { value: 4, text: '4' }, { value: 5, text: '5' }],
          rows: [
            'Certifications and training completed.',
            'Staying current with industry trends.',
            'Knowledge sharing and mentorship.',
          ],
        },
      ],
    },
    {
      name: 'page3',
      title: 'Project Delivery',
      elements: [
        {
          type: 'matrix',
          name: 'project_delivery',
          columns: [{ value: 1, text: '1' }, { value: 2, text: '2' }, { value: 3, text: '3' }, { value: 4, text: '4' }, { value: 5, text: '5' }],
          rows: [
            'Project completion rate and success.',
            'Meeting deadlines and milestones.',
            'Work quality review scores.',
          ],
        },
        { type: 'comment', name: 'comments', title: 'Comments & Recommendations' },
      ],
    },
  ],
}

// Map of template ID → SurveyJS JSON
export const TEMPLATE_REGISTRY = {
  blank: { id: 'blank', name: 'Blank Form', description: 'Start from a clean slate with a single rating question.', json: BLANK_SURVEY_JSON },
  '360-feedback': { id: '360-feedback', name: '360° Performance Feedback', description: 'Multi-rater feedback from supervisors, peers, and subordinates.', json: TEMPLATE_360_FEEDBACK },
  probationary: { id: 'probationary', name: 'Probationary Evaluation', description: 'Regularization assessment for probationary employees.', json: PROBATIONARY_EVALUATION_TEMPLATE },
  leadership: { id: 'leadership', name: 'Leadership Assessment', description: 'Management and leadership competencies evaluation.', json: LEADERSHIP_EVALUATION_TEMPLATE },
  'customer-service': { id: 'customer-service', name: 'Customer Service Evaluation', description: 'Customer-facing skills and service quality assessment.', json: CUSTOMER_SERVICE_EVALUATION_TEMPLATE },
  'technical-competency': { id: 'technical-competency', name: 'Technical Competency Evaluation', description: 'Technical skills, certifications, and project delivery.', json: TECHNICAL_COMPETENCY_TEMPLATE },
}

// ─── Helper to load a template into a Creator instance ─────────────
export function loadTemplateIntoCreator(creator, templateId) {
  if (!creator) return false
  const template = TEMPLATE_REGISTRY[templateId]
  if (!template) return false
  try {
    creator.JSON = JSON.parse(JSON.stringify(template.json))
    return true
  } catch {
    return false
  }
}

// ─── Legacy Compatibility Helpers (unchanged) ──────────────────────

// A sensible starter template for a new evaluation form.
export const DEFAULT_SURVEY_JSON = BLANK_SURVEY_JSON

function normalizeWeights(sections) {
  const total = sections.reduce((sum, s) => sum + (Number(s.weight) || 0), 0)
  if (total <= 0) {
    const even = sections.length ? Math.floor(100 / sections.length) : 0
    return sections.map((s, i) => {
      // adjust the last section so weights sum to exactly 100
      const weight = i === sections.length - 1 ? 100 - even * (sections.length - 1) : even
      return { ...s, weight }
    })
  }
  // scale to 100 and round, fixing the remainder on the last section
  let running = 0
  return sections.map((s, i) => {
    const raw = ((Number(s.weight) || 0) / total) * 100
    let weight = i === sections.length - 1 ? 100 - running : Math.round(raw)
    running += weight
    return { ...s, weight }
  })
}

function questionToSectionQuestion(q) {
  if (q.type === 'rating') {
    const max = Number(q.rateMax) || Number(q.rateCount) || 5
    return { title: q.title || q.name || 'Untitled', type: 'rating', max }
  }
  return { title: q.title || q.name || 'Untitled', type: 'text' }
}

// Convert a SurveyJS definition into the legacy `sections` shape used by
// the scoring engine and the evaluation/review screens.
export function surveyToSections(surveyJson) {
  if (!surveyJson || !Array.isArray(surveyJson.pages) || surveyJson.pages.length === 0) {
    return []
  }

  const sections = []
  for (const page of surveyJson.pages) {
    const elements = Array.isArray(page.elements) ? page.elements : []
    const panels = elements.filter((el) => el && el.type === 'panel')

    if (panels.length > 0) {
      for (const panel of panels) {
        const questions = Array.isArray(panel.elements)
          ? panel.elements.filter((el) => el && el.type !== 'panel').map(questionToSectionQuestion)
          : []
        sections.push({
          title: panel.title || panel.name || 'Untitled Section',
          weight: Number(panel.weight) || 0,
          questions,
        })
      }
    } else {
      const questions = elements.filter((el) => el && el.type !== 'panel').map(questionToSectionQuestion)
      sections.push({
        title: page.title || page.name || 'Section',
        weight: 100,
        questions,
      })
    }
  }

  return normalizeWeights(sections)
}

// Count all question-like elements (used for form summary displays).
export function countSurveyQuestions(surveyJson) {
  if (!surveyJson || !Array.isArray(surveyJson.pages)) return 0
  let count = 0
  const walk = (elements) => {
    for (const el of elements || []) {
      if (!el) continue
      if (el.type === 'panel') walk(el.elements)
      else count += 1
    }
  }
  for (const page of surveyJson.pages) walk(page.elements)
  return count
}

function surveyQuestionWalk(surveyJson, cb) {
  if (!surveyJson || !Array.isArray(surveyJson.pages)) return
  for (const page of surveyJson.pages) {
    const elements = Array.isArray(page.elements) ? page.elements : []
    const panels = elements.filter((el) => el && el.type === 'panel')
    if (panels.length > 0) {
      for (const panel of panels) {
        for (const q of panel.elements || []) {
          if (q && q.type !== 'panel') cb(panel.title || panel.name || 'Section', q)
        }
      }
    } else {
      for (const q of elements) {
        if (q && q.type !== 'panel') cb(page.title || page.name || 'Section', q)
      }
    }
  }
}

// Build the legacy `scores` payload ({ sections: { [section]: { [question]: value } } })
// from a SurveyJS definition and its current response data.
// Handles both individual rating questions and matrix questions (converting
// matrix row objects into per-row numeric entries so the view dialog never
// sees an object). Expression values are also captured as-is.
export function scoresFromSurvey(surveyJson, data = {}) {
  const sections = {}
  surveyQuestionWalk(surveyJson, (sectionTitle, q) => {
    if (!sections[sectionTitle]) sections[sectionTitle] = {}
    const raw = data ? data[q.name] : undefined

    // ── Matrix question: extract individual row values ──
    if (q.type === 'matrix' && raw && typeof raw === 'object') {
      const rows = q.rows || []
      for (let i = 0; i < rows.length; i++) {
        const row = rows[i]
        const rowTitle = typeof row === 'string' ? row : (row.text || row.value || `Row ${i}`)
        sections[sectionTitle][rowTitle] = Number(raw[i]) || 0
      }
      return
    }

    // ── Rating question: store as number ──
    if (q.type === 'rating') {
      sections[sectionTitle][q.title || q.name] = Number(raw) || 0
      return
    }

    // ── Expression question: use the computed value (could be number or string) ──
    if (q.type === 'expression') {
      const val = raw
      if (typeof val === 'number') {
        sections[sectionTitle][q.title || q.name] = Number(val.toFixed(2)) || 0
      } else {
        sections[sectionTitle][q.title || q.name] = val ?? ''
      }
      return
    }

    // ── Everything else: text, comment, html (skip), signaturepad, radiogroup ──
    sections[sectionTitle][q.title || q.name] = (typeof raw === 'string' || typeof raw === 'number')
      ? raw
      : (raw ?? '')
  })
  return { sections }
}

// Convert a legacy `scores` payload back into SurveyJS response data so a
// previously saved (draft) evaluation can be pre-filled into the survey.
// For matrix questions, individual row values are re-assembled into the
// row-indexed object format that SurveyJS expects ({ '0': value, '1': value }).
export function surveyDataFromScores(surveyJson, scores = {}) {
  const data = {}
  const legacy = scores?.sections || {}
  surveyQuestionWalk(surveyJson, (sectionTitle, q) => {
    // ── Matrix question: re-assemble row values into indexed object ──
    if (q.type === 'matrix') {
      const rows = q.rows || []
      const matrixData = {}
      let hasValue = false
      for (let i = 0; i < rows.length; i++) {
        const row = rows[i]
        const rowTitle = typeof row === 'string' ? row : (row.text || row.value || `Row ${i}`)
        const val = legacy[sectionTitle]?.[rowTitle]
        if (val !== undefined && val !== '' && val !== 0) {
          matrixData[i] = Number(val)
          hasValue = true
        }
      }
      if (hasValue) data[q.name] = matrixData
      return
    }

    // ── Non-matrix: direct lookup ──
    const value = legacy[sectionTitle]?.[q.title || q.name]
    if (value !== undefined && value !== '') data[q.name] = value
  })
  return data
}
