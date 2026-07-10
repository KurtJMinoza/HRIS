import { useMemo, useState } from 'react'
import { Model } from 'survey-core'
import { Survey } from 'survey-react-ui'
import { SurveyCreatorComponent, SurveyCreator } from 'survey-creator-react'
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs'
import { FileCog, Eye } from 'lucide-react'
import { cn } from '@/lib/utils'

export default function SurveyFormBuilder({ value, onChange }) {
  const [tab, setTab] = useState('builder')

  const creator = useMemo(() => {
    const c = new SurveyCreator({
      showEmbeddedSurveyTab: false,
      showTranslationTab: false,
      showJSONEditorTab: true,
      allowEditSurveyTitle: true,
      showPreviewTab: false,
      maxUndoRedoCount: 50,
    })
    c.JSON = value || {}
    c.onModified.add(() => {
      onChange?.(c.JSON)
    })
    return c
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const previewModel = useMemo(
    () => new Model(value && Object.keys(value).length ? value : { pages: [] }),
    [value],
  )

  return (
    <div className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm">
      <Tabs value={tab} onValueChange={setTab} className="flex h-full flex-col">
        <div className="flex items-center justify-between gap-3 border-b border-border/40 bg-muted/30 px-5 py-3">
          <div>
            <h3 className="text-sm font-bold text-foreground">Form Designer</h3>
            <p className="mt-0.5 text-xs text-muted-foreground">
              Drag, drop, and configure questions. Add Panels to create weighted sections.
            </p>
          </div>
          <TabsList className="h-9 rounded-xl bg-muted p-1">
            <TabsTrigger
              value="builder"
              className={cn(
                'gap-1.5 rounded-lg px-3 text-xs font-semibold data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow-sm',
              )}
            >
              <FileCog className="size-3.5" />
              Builder
            </TabsTrigger>
            <TabsTrigger
              value="preview"
              className={cn(
                'gap-1.5 rounded-lg px-3 text-xs font-semibold data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow-sm',
              )}
            >
              <Eye className="size-3.5" />
              Preview
            </TabsTrigger>
          </TabsList>
        </div>

        <TabsContent value="builder" className="m-0">
          <div className="survey-creator-host h-[58vh] min-h-[420px] overflow-hidden">
            <SurveyCreatorComponent creator={creator} />
          </div>
        </TabsContent>

        <TabsContent value="preview" className="m-0">
          <div className="h-[58vh] min-h-[420px] overflow-y-auto bg-muted/20 px-5 py-6">
            {previewModel.getAllQuestions().length === 0 ? (
              <div className="flex h-full flex-col items-center justify-center text-center">
                <p className="text-sm font-semibold text-foreground">Nothing to preview yet</p>
                <p className="mt-1 max-w-xs text-sm text-muted-foreground">
                  Switch to the Builder tab and add some questions to see a live preview.
                </p>
              </div>
            ) : (
              <div className="mx-auto max-w-2xl rounded-2xl border border-border/70 bg-card p-6 shadow-sm">
                <Survey model={previewModel} />
              </div>
            )}
          </div>
        </TabsContent>
      </Tabs>
    </div>
  )
}
