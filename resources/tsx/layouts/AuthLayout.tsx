import { ReactNode } from 'react';

export function AuthLayout({ children }: { children: ReactNode }) {
    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
            <div className="w-full max-w-sm">
                <div className="mb-8 text-center">
                    <h1 className="text-2xl font-semibold text-slate-900">CNCMS</h1>
                    <p className="mt-1 text-sm text-slate-500">SWECOM PLC — Cable Network Management</p>
                </div>
                <div className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">{children}</div>
            </div>
        </div>
    );
}
