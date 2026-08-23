import { InputHTMLAttributes, forwardRef } from 'react';

interface TextInputProps extends InputHTMLAttributes<HTMLInputElement> {
    label?: string;
    error?: string;
}

export const TextInput = forwardRef<HTMLInputElement, TextInputProps>(
    ({ label, error, className = '', id, ...props }, ref) => (
        <div className="flex flex-col gap-1">
            {label && (
                <label htmlFor={id} className="text-sm font-medium text-slate-700">
                    {label}
                </label>
            )}
            <input
                ref={ref}
                id={id}
                className={`rounded-md border-0 px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 ${
                    error ? 'ring-red-400 focus:ring-red-500' : ''
                } ${className}`}
                {...props}
            />
            {error && <p className="text-xs text-red-600">{error}</p>}
        </div>
    ),
);

TextInput.displayName = 'TextInput';
