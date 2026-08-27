import { InputHTMLAttributes, forwardRef } from 'react';

interface TextInputProps extends InputHTMLAttributes<HTMLInputElement> {
    label?: string;
    error?: string;
}

export const TextInput = forwardRef<HTMLInputElement, TextInputProps>(
    ({ label, error, className = '', id, ...props }, ref) => (
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
            <input
                ref={ref}
                id={id}
                className={`rounded-lg border-0 bg-white px-3.5 py-2 text-sm text-slate-900 shadow-xs ring-1 ring-inset ring-slate-300 transition-shadow duration-150 placeholder:text-slate-400 hover:ring-slate-400 focus:shadow-none focus:ring-2 focus:ring-inset focus:ring-blue-600 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400 disabled:ring-slate-200 ${
                    error ? 'ring-red-400 hover:ring-red-400 focus:ring-red-500' : ''
                } ${className}`}
                {...props}
            />
            {error && <p className="text-xs text-red-600">{error}</p>}
        </div>
    ),
);

TextInput.displayName = 'TextInput';
