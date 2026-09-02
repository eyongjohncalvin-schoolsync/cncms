import { SelectHTMLAttributes, forwardRef } from 'react';
import { IconChevronDown } from '@tabler/icons-react';

interface SelectInputProps extends SelectHTMLAttributes<HTMLSelectElement> {
    label?: string;
    error?: string;
}

export const SelectInput = forwardRef<HTMLSelectElement, SelectInputProps>(
    ({ label, error, className = '', id, children, ...props }, ref) => (
        <div className="flex flex-col gap-1.5">
            {label && (
                <label htmlFor={id} className="text-sm font-medium text-slate-700">
                    {label}
                    {props.required && (
                        <span className="ml-0.5 text-red-500" aria-hidden="true">
                            *
                        </span>
                    )}
                </label>
            )}
            <div className="relative">
                <select
                    ref={ref}
                    id={id}
                    className={`w-full appearance-none rounded-lg border-0 bg-white px-3.5 py-2.5 pr-9 text-base text-slate-900 shadow-xs ring-1 ring-inset ring-slate-300 transition-shadow duration-150 hover:ring-slate-400 focus:shadow-none focus:ring-2 focus:ring-inset focus:ring-blue-600 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400 disabled:ring-slate-200 sm:py-2 sm:text-sm ${
                        error ? 'ring-red-400 hover:ring-red-400 focus:ring-red-500' : ''
                    } ${className}`}
                    {...props}
                >
                    {children}
                </select>
                <IconChevronDown
                    size={16}
                    stroke={2}
                    className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-slate-400"
                    aria-hidden="true"
                />
            </div>
            {error && <p className="text-xs text-red-600">{error}</p>}
        </div>
    ),
);

SelectInput.displayName = 'SelectInput';
