import { IconBuildingSkyscraper, IconShieldLock, IconTerminal2, IconTool } from '@tabler/icons-react';
import type { LandlordActivityEntry } from '@/types';

const SUBJECT_ICON = {
    tenant: IconBuildingSkyscraper,
    maintenance: IconTool,
    user: IconShieldLock,
};

function when(iso: string | null): string {
    return iso
        ? new Date(iso).toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
        : '';
}

/** Rows of landlord activity — shared by Landlord/Dashboard and Landlord/Activity/Index. */
export function LandlordActivityList({ activities }: { activities: LandlordActivityEntry[] }) {
    return (
        <ul className="divide-y divide-slate-100">
            {activities.map((activity) => {
                const Icon = (activity.subject_type && SUBJECT_ICON[activity.subject_type]) || IconTerminal2;

                return (
                    <li key={activity.id} className="flex items-start gap-3 px-4 py-3">
                        <span className="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-indigo-50 text-indigo-600">
                            <Icon size={15} stroke={1.75} />
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="text-sm text-slate-800">{activity.summary}</p>
                            <p className="text-xs text-slate-500">
                                {activity.user ?? 'Command line'} · {when(activity.created_at)}
                                {activity.ip_address && <span className="text-slate-400"> · {activity.ip_address}</span>}
                            </p>
                        </div>
                        <code className="hidden shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-500 sm:inline">{activity.action}</code>
                    </li>
                );
            })}
        </ul>
    );
}
