import { InputHTMLAttributes, forwardRef, useState } from 'react';
import { IconEye, IconEyeOff } from '@tabler/icons-react';

interface PasswordInputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
    label?: string;
    error?: string;
}

/**
 * Password field with a show/hide toggle. Mirrors TextInput's ring/shadow
 * styling exactly (they must look identical stacked in a form) and adds the
 * one thing a password field needs that a plain TextInput can't give it.
 */
export const PasswordInput = forwardRef<HTMLInputElement, PasswordInputProps>(
    ({ label, error, className = '', id, ...props }, ref) => {
        const [visible, setVisible] = useState(false);

        return (
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
                    <input
                        ref={ref}
                        id={id}
                        type={visible ? 'text' : 'password'}
                        className={`w-full rounded-lg border-0 bg-white py-2 pr-10 pl-3.5 text-sm text-slate-900 shadow-xs ring-1 ring-inset ring-slate-300 transition-shadow duration-150 placeholder:text-slate-400 hover:ring-slate-400 focus:shadow-none focus:ring-2 focus:ring-inset focus:ring-blue-600 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400 disabled:ring-slate-200 ${
                            error ? 'ring-red-400 hover:ring-red-400 focus:ring-red-500' : ''
                        } ${className}`}
                        {...props}
                    />
                    <button
                        type="button"
                        onClick={() => setVisible((v) => !v)}
                        tabIndex={-1}
                        aria-label={visible ? 'Hide password' : 'Show password'}
                        className="absolute inset-y-0 right-0 flex items-center px-3 text-slate-400 transition-colors hover:text-slate-600"
                    >
                        {visible ? <IconEyeOff size={17} stroke={1.75} /> : <IconEye size={17} stroke={1.75} />}
                    </button>
                </div>
                {error && <p className="text-xs text-red-600">{error}</p>}
            </div>
        );
    },
);

PasswordInput.displayName = 'PasswordInput';
