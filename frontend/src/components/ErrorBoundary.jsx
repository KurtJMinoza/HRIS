import { Component } from 'react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'

/**
 * Catches React render errors and displays a fallback instead of a blank screen.
 */
export class ErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { hasError: false, error: null }
  }

  static getDerivedStateFromError(error) {
    return { hasError: true, error }
  }

  componentDidCatch(error, info) {
    console.error('ErrorBoundary caught:', error, info)
  }

  render() {
    if (this.state.hasError) {
      const card = (
        <Card className="max-w-lg border-destructive/50 shadow-lg">
          <CardHeader>
            <CardTitle className="text-destructive">Something went wrong</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            <p className="text-sm text-muted-foreground break-words">
              {this.state.error?.message || 'An error occurred loading this page.'}
            </p>
            {this.props.fullScreen && (
              <p className="text-xs text-muted-foreground">
                Check the browser console (F12) for details. If this persists after a refresh, report the message above.
              </p>
            )}
            <Button
              variant="outline"
              size="sm"
              onClick={() => this.setState({ hasError: false, error: null })}
            >
              Try again
            </Button>
          </CardContent>
        </Card>
      )
      if (this.props.fullScreen) {
        return (
          <div className="flex min-h-screen w-full items-center justify-center bg-background p-6">{card}</div>
        )
      }
      return card
    }
    return this.props.children
  }
}
