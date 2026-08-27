import { Component, ErrorInfo, ReactNode } from 'react';
import { Button } from '@/components/ui/Button';

interface ErrorBoundaryProps {
    children: ReactNode;
    /**
     * Render a smaller, inline fallback instead of the full-page one —
     * for boundaries wrapping a widget/section (a chart, a table row)
     * rather than an entire page. Retrying just re-renders the children
     * (no page reload), since the rest of the page around it is fine.
     */
    compact?: boolean;
    /** Full custom fallback, overriding both the page and compact defaults. */
    fallback?: ReactNode;
}

interface ErrorBoundaryState {
    error: Error | null;
}

/**
 * Catches rendering errors anywhere below it in the tree and shows a
 * recoverable fallback instead of a blank white screen. React error
 * boundaries must be class components — there is no hook equivalent.
 * Does NOT catch errors in event handlers, async code, or SSR — those
 * still need their own try/catch (this only guards the render path).
 */
export class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
    state: ErrorBoundaryState = { error: null };

    static getDerivedStateFromError(error: Error): ErrorBoundaryState {
        return { error };
    }

    componentDidCatch(error: Error, info: ErrorInfo): void {
        console.error('Unhandled render error:', error, info.componentStack);
    }

    private retry = (): void => {
        this.setState({ error: null });
    };

    private reloadPage = (): void => {
        this.setState({ error: null });
        window.location.reload();
    };

    render(): ReactNode {
        if (!this.state.error) {
            return this.props.children;
        }

        if (this.props.fallback) {
            return this.props.fallback;
        }

        if (this.props.compact) {
            return (
                <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-red-200 bg-red-50/60 px-4 py-6 text-center">
                    <p className="text-sm text-red-700">This section couldn't be displayed.</p>
                    <Button onClick={this.retry} variant="secondary" className="text-xs">
                        Retry
                    </Button>
                </div>
            );
        }

        return (
            <div className="flex min-h-screen flex-col items-center justify-center gap-3 bg-slate-50 px-4 text-center">
                <h1 className="text-lg font-semibold text-slate-900">Something went wrong</h1>
                <p className="max-w-sm text-sm text-slate-500">
                    This page hit an unexpected error. Reloading usually fixes it — if it keeps
                    happening, let the team know what you were doing.
                </p>
                <Button onClick={this.reloadPage} className="mt-2">
                    Reload page
                </Button>
            </div>
        );
    }
}
