import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'

export default function AdminEmployeeProfileSubmodule({ title }) {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="hr-page-title">{title}</h1>
        <p className="text-sm text-muted-foreground">This submodule is set up in the sidebar and route structure.</p>
      </div>
      <Card>
        <CardHeader>
          <CardTitle>{title}</CardTitle>
        </CardHeader>
        <CardContent className="text-sm text-muted-foreground">
          Content for this section is not designed yet.
        </CardContent>
      </Card>
    </div>
  )
}
