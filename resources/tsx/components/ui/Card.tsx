import { HTMLAttributes, ReactNode } from 'react';

interface CardProps extends HTMLAttributes<HTMLDivElement> {
    children: ReactNode;
}

export function Card({ children, className = '', ...props }: CardProps) {
    return (
        <div className={`rounded-lg border border-slate-200 bg-white shadow-sm shadow-slate-900/5 ${className}`} {...props}>
            {children}
        </div>
    );
}

export function CardHeader({ children, className = '', ...props }: CardProps) {
    return (
        <div className={`border-b border-slate-200 px-4 py-3 ${className}`} {...props}>
            {children}
        </div>
    );
}

export function CardBody({ children, className = '', ...props }: CardProps) {
    return (
        <div className={`p-4 ${className}`} {...props}>
            {children}
        </div>
    );
}
