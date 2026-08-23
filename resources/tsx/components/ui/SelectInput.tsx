import { SelectHTMLAttributes, forwardRef } from 'react';

interface SelectInputProps extends SelectHTMLAttributes<HTMLSelectElement> {
    label?: string;
    error?: string;
}

export const SelectInput = forwardRef<HTMLSelectElement, SelectInputProps>(
    ({ label, error, className = '', id, children, ...props }, ref) => (
        <div className="flex flex-col gap-1">
            {label && (
                <label htmlFor={id} className="text-sm font-medium text-slate-700">
                    {label}
                </label>
            )}
            <select
                ref={ref}
                id={id}
                className={`rounded-md border-0 px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-blue-600 ${
                    error ? 'ring-red-400 focus:ring-red-500' : ''
                } ${className}`}
                {...props}
            >
                {children}
            </select>
            {error && <p className="text-xs text-red-600">{error}</p>}
        </div>
    ),
);

SelectInput.displayName = 'SelectInput';
