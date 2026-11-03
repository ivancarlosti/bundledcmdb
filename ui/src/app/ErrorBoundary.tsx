import { Component, type ErrorInfo, type ReactNode } from 'react';

type Props = { children: ReactNode };

type State = { error: Error | null };

/**
 * Last-resort error boundary.
 *
 * A render error used to blank the whole mount point, which looks like an outage.
 * Here the user keeps a readable message, a reload button and a link back to the
 * grid, and the error is logged once for the console/observability pipeline.
 */
export class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    console.error('unhandled UI error', error, info.componentStack);
  }

  render() {
    const { error } = this.state;
    if (error === null) {
      return this.props.children;
    }

    // Deliberately untranslated: the catalogue may be the thing that failed.
    return (
      <main className="mx-auto flex min-h-dvh w-full max-w-lg flex-col justify-center gap-3 p-6">
        <div role="alert" className="card p-4">
          <p className="m-0 font-semibold">Something went wrong.</p>
          <p className="mt-1 mb-0 text-sm text-[var(--color-muted)]">{error.message}</p>
          <div className="mt-3 flex flex-wrap gap-2">
            <button
              type="button"
              className="rounded-[var(--radius-md)] bg-[var(--color-accent)] px-3 py-1.5 text-sm font-medium text-[var(--color-accent-contrast)]"
              onClick={() => this.setState({ error: null })}
            >
              Try again
            </button>
            <button
              type="button"
              className="rounded-[var(--radius-md)] border border-[var(--color-border)] px-3 py-1.5 text-sm"
              onClick={() => window.location.assign('/app/')}
            >
              Back to the devices
            </button>
          </div>
        </div>
      </main>
    );
  }
}
