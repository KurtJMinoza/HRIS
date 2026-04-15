/** Shared print rules: A4, single sheet, match on-screen Tailwind layout. */
export const PAYSLIP_DOCUMENT_PRINT_CSS = `
@media print {
  @page {
    size: A4 portrait;
    margin: 10mm;
  }
  @page :first {
    margin: 10mm;
  }
}

@media print {
  html, body {
    margin: 0 !important;
    padding: 0 !important;
    background: #fff !important;
    color: #0A0A0A !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    width: 210mm !important;
    min-height: 297mm !important;
    height: auto !important;
    overflow: visible !important;
  }

  #root, [data-slot="root"], .min-h-screen {
    min-height: 0 !important;
    height: auto !important;
  }

  * {
    text-shadow: none !important;
    box-shadow: none !important;
  }

  [data-payslip-document] {
    width: 100% !important;
    max-width: 190mm !important;
    margin: 0 !important;
    border: 0 !important;
    border-radius: 0 !important;
    overflow: hidden !important;
    height: 277mm !important;
    max-height: 277mm !important;
    page-break-after: avoid !important;
    page-break-before: avoid !important;
    break-after: avoid-page !important;
    break-before: avoid-page !important;
    break-inside: avoid-page !important;
    font-size: 11px !important;
    line-height: 1.18 !important;
  }

  [data-payslip-doc-header] {
    padding: 5px 7px !important;
    border: 0 !important;
    page-break-inside: avoid !important;
    break-inside: avoid !important;
    break-after: avoid-page !important;
  }

  [data-payslip-doc-header] h1 {
    font-size: 14px !important;
    line-height: 1.1 !important;
  }

  [data-payslip-doc-header] > div > div:last-child span {
    font-size: 11px !important;
    letter-spacing: 0.08em !important;
    padding: 2px 7px !important;
  }

  [data-payslip-document] .payslip-content {
    padding: 5px 7px 5px !important;
    row-gap: 5px !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 5px !important;
    break-inside: avoid-page !important;
  }

  [data-payslip-meta-bar] {
    padding: 3px 5px !important;
    border: 0 !important;
    page-break-inside: avoid !important;
    break-inside: avoid-page !important;
  }

  [data-payslip-meta-bar] p:first-child {
    font-size: 10px !important;
    letter-spacing: 0.08em !important;
    line-height: 1.1 !important;
  }

  [data-payslip-meta-bar] p:last-child {
    margin-top: 1px !important;
    font-size: 11px !important;
    line-height: 1.15 !important;
    font-weight: 600 !important;
  }

  [data-payslip-section] {
    padding: 5px 7px !important;
    border: 0 !important;
    page-break-inside: avoid !important;
    break-inside: avoid-page !important;
  }

  [data-payslip-avatar] {
    margin-bottom: 4px !important;
  }

  [data-payslip-avatar-photo] {
    display: none !important;
  }

  [data-payslip-employee-headline] p:first-child {
    font-size: 12px !important;
    line-height: 1.15 !important;
  }

  [data-payslip-employee-headline] p:last-child {
    font-size: 11px !important;
    line-height: 1.1 !important;
  }

  [data-payslip-tables-grid] {
    gap: 5px !important;
    page-break-inside: avoid !important;
    break-inside: avoid-page !important;
    break-before: avoid-page !important;
    break-after: avoid-page !important;
  }

  [data-payslip-tables-grid] > div {
    border: 0 !important;
    box-shadow: none !important;
    page-break-inside: avoid !important;
    break-inside: avoid-page !important;
  }

  [data-payslip-table-header] {
    padding: 3px 5px !important;
    border: 0 !important;
    page-break-inside: avoid !important;
    break-inside: avoid-page !important;
  }

  [data-payslip-lines-table],
  [data-payslip-deductions-table] {
    font-size: 11px !important;
    border: 0 !important;
    page-break-inside: avoid !important;
    break-inside: avoid-page !important;
  }

  [data-payslip-lines-table] th,
  [data-payslip-lines-table] td,
  [data-payslip-deductions-table] th,
  [data-payslip-deductions-table] td {
    border: 0 !important;
    padding-top: 1px !important;
    padding-bottom: 1px !important;
    font-size: 11px !important;
    line-height: 1.1 !important;
  }

  [data-payslip-document] tr {
    border: 0 !important;
    break-inside: avoid-page !important;
    page-break-inside: avoid !important;
  }

  [data-payslip-net-hero] {
    padding: 5px 7px !important;
    border-radius: 8px !important;
    page-break-inside: avoid !important;
    break-inside: avoid-page !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    background: #0A0A0A !important;
    color: #fff !important;
  }

  [data-payslip-net-hero] p:nth-of-type(2) {
    font-size: 18px !important;
    line-height: 1.05 !important;
  }

  [data-payslip-footer] {
    font-size: 10px !important;
    padding-top: 1px !important;
    page-break-inside: avoid !important;
    break-inside: avoid-page !important;
  }

  [data-payslip-document] h1,
  [data-payslip-document] h2,
  [data-payslip-document] h3 {
    break-after: avoid-page !important;
  }

  [data-payslip-document] h2 {
    font-size: 14px !important;
    margin-bottom: 0.15rem !important;
  }

  [data-payslip-document] h3 {
    font-size: 12px !important;
  }
}
`

export const PAYSLIP_PAGE_PRINT_STYLES = `
${PAYSLIP_DOCUMENT_PRINT_CSS}
@media print {
  [data-payslip-toolbar] { display: none !important; }
  [data-payslip-bg] { display: none !important; }
  nav, aside, header:not([data-payslip-doc-header]),
  [data-sidebar], [data-topbar], [data-app-header],
  .sidebar, .app-sidebar { display: none !important; }
}
`

export const PAYSLIP_MODAL_PRINT_STYLES = `
${PAYSLIP_DOCUMENT_PRINT_CSS}
@media print {
  html, body {
    width: 210mm !important;
    height: 297mm !important;
    overflow: hidden !important;
  }
  body * {
    visibility: hidden !important;
  }
  [data-payslip-print-mount],
  [data-payslip-print-mount] * {
    visibility: visible !important;
  }
  [data-payslip-print-mount] {
    position: absolute !important;
    left: 0 !important;
    top: 0 !important;
    width: 100% !important;
    height: 297mm !important;
    max-height: 297mm !important;
    overflow: hidden !important;
    background: #fff !important;
    padding: 0 !important;
    display: flex !important;
    justify-content: center !important;
    align-items: flex-start !important;
  }
  [data-payslip-modal-chrome] {
    display: none !important;
  }
  [data-radix-dialog-overlay],
  [data-slot="dialog-overlay"] {
    display: none !important;
  }
}
`

