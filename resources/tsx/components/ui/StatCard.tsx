import { ReactNode } from 'react';
import { Card } from '@/components/ui/Card';

interface StatCardProps {
    label: string;
    value: string;
    hint?: string;
    icon?: ReactNode;
}

export function StatCard({ label, value, hint, icon }: StatCardProps) {
    return (
        <Card className="p-4">
            <div className="flex items-start justify-between">
                <div>
                    <p className="text-sm font-medium text-slate-500">{label}</p>
                    <p className="mt-1 text-2xl font-semibold text-slate-900">{value}</p>
                    {hint && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
                </div>
                {icon && <div className="text-slate-400">{icon}</div>}
            </div>
        </Card>
    );
}
