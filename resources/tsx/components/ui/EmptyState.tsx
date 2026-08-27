export function EmptyState({ title, description }: { title: string; description?: string }) {
    return (
        <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50/50 px-6 py-14 text-center">
            <p className="text-sm font-medium text-slate-700">{title}</p>
            {description && <p className="mt-1 max-w-sm text-sm text-slate-500">{description}</p>}
        </div>
    );
}
