interface LoadingSpinnerProps {
    className?: string;
    label?: string;
}

/**
 * Small centered spinner for inline loading feedback (e.g. inside a submit
 * button, or as a standalone indicator while content streams in).
 */
export function LoadingSpinner({ className = '', label = 'Loading' }: LoadingSpinnerProps) {
    return (
        <span
            role="status"
            aria-label={label}
            className={`inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent align-middle ${className}`}
        />
    );
}
