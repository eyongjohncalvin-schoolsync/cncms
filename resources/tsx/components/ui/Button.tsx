import { ButtonHTMLAttributes, forwardRef } from 'react';

type Variant = 'primary' | 'secondary' | 'danger' | 'warning' | 'ghost';

const variantClasses: Record<Variant, string> = {
    primary:
        'bg-blue-600 text-white shadow-sm shadow-blue-600/20 hover:bg-blue-700 hover:shadow-blue-600/30 focus-visible:outline-blue-600',
    secondary:
        'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 hover:ring-slate-400 focus-visible:outline-slate-400',
    danger: 'bg-red-600 text-white shadow-sm shadow-red-600/20 hover:bg-red-700 hover:shadow-red-600/30 focus-visible:outline-red-600',
    // Reversible-but-consequential actions (e.g. archiving a customer) — amber,
    // deliberately not the red `danger` reserved for irreversible destruction.
    warning: 'bg-amber-500 text-white shadow-sm shadow-amber-500/20 hover:bg-amber-600 hover:shadow-amber-500/30 focus-visible:outline-amber-500',
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
            className={`inline-flex min-h-[40px] items-center justify-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium transition-all duration-150 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50 disabled:shadow-none disabled:active:scale-100 sm:min-h-0 ${variantClasses[variant]} ${className}`}
            {...props}
        />
    ),
);

Button.displayName = 'Button';
