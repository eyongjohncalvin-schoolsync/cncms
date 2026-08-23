import { ButtonHTMLAttributes, forwardRef } from 'react';

type Variant = 'primary' | 'secondary' | 'danger' | 'ghost';

const variantClasses: Record<Variant, string> = {
    primary: 'bg-blue-600 text-white hover:bg-blue-700 focus-visible:outline-blue-600',
    secondary: 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus-visible:outline-slate-400',
    danger: 'bg-red-600 text-white hover:bg-red-700 focus-visible:outline-red-600',
    ghost: 'bg-transparent text-slate-600 hover:bg-slate-100 focus-visible:outline-slate-400',
};

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: Variant;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
    ({ variant = 'primary', className = '', disabled, ...props }, ref) => (
        <button
            ref={ref}
            disabled={disabled}
            className={`inline-flex items-center justify-center gap-1.5 rounded-md px-3 py-2 text-sm font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50 ${variantClasses[variant]} ${className}`}
            {...props}
        />
    ),
);

Button.displayName = 'Button';
