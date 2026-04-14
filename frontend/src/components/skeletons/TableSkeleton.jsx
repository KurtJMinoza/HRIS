import { Skeleton } from '@/components/ui/skeleton'

/**
 * Skeleton placeholder for a data table. Renders header row + body rows.
 * @param {object} props
 * @param {number} [props.rows=5] - Number of body rows
 * @param {number} [props.cols=5] - Number of columns
 * @param {string} [props.className] - Optional wrapper class
 */
export function TableSkeleton({ rows = 5, cols = 5, className = '' }) {
  return (
    <div className={className}>
      <table className="w-full border-0 text-sm">
        <thead>
          <tr className="border-b border-border/40 bg-muted/60">
            {Array.from({ length: cols }, (_, i) => (
              <th key={i} className="px-4 py-3 text-left">
                <Skeleton className="h-4 w-24" />
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {Array.from({ length: rows }, (_, rowIndex) => (
            <tr key={rowIndex} className="border-b border-border/20">
              {Array.from({ length: cols }, (_, colIndex) => (
                <td key={colIndex} className="px-4 py-3">
                  <Skeleton
                    className="h-4"
                    style={{ width: colIndex === 0 ? '60%' : colIndex === 1 ? '40%' : '20%' }}
                  />
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/**
 * Skeleton table body rows only (no wrapper table). Use inside existing <tbody>.
 * Renders one <tr> per row with <td> per column so layout matches the real table.
 */
export function TableBodySkeleton({ rows = 5, cols = 5 }) {
  return (
    <>
      {Array.from({ length: rows }, (_, rowIndex) => (
        <tr key={rowIndex} className="border-b border-border/20 hover:bg-muted/30">
          {Array.from({ length: cols }, (_, colIndex) => (
            <td key={colIndex} className="px-3 py-2">
              <Skeleton
                className="h-4"
                style={{
                  width: colIndex === 0 ? '80%' : colIndex === 1 ? '60%' : '40%',
                }}
              />
            </td>
          ))}
        </tr>
      ))}
    </>
  )
}
