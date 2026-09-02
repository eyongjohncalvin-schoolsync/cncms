import { Dialog, DialogPanel, Transition, TransitionChild } from '@headlessui/react';
import { Fragment } from 'react';
import { AppNav } from '@/components/shared/AppNav';

/**
 * Off-canvas navigation drawer for phones (`< md`). Above `md` the desktop
 * `<aside>` in AppLayout is the only nav and this never mounts a visible
 * panel. The pattern is the well-established admin-dashboard one (AdminLTE
 * PushMenu / CoreUI / Bootstrap offcanvas): a hamburger in the header opens
 * a left-anchored panel over a dimmed backdrop.
 *
 * Headless UI's `Dialog` is deliberately reused here (same as
 * components/ui/Modal.tsx) — it gives the a11y contract for free: focus is
 * trapped inside the panel while open, Escape closes, body scroll is locked,
 * and focus is restored to the trigger (the hamburger) on close. We only
 * add the slide transform + reduced-motion handling on top.
 *
 * `id` is wired to the hamburger's `aria-controls`; the panel carries the
 * matching nav landmark via <AppNav>.
 */
export function MobileNavDrawer({
    open,
    onClose,
    id,
    permissions = [],
    currentPath,
    companyName,
}: {
    open: boolean;
    onClose: () => void;
    id: string;
    permissions?: string[];
    currentPath: string;
    companyName?: string | null;
}) {
    return (
        <Transition show={open} as={Fragment}>
            <Dialog onClose={onClose} className="relative z-50 md:hidden">
                <TransitionChild
                    as={Fragment}
                    enter="ease-out duration-200 motion-reduce:transition-none"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="ease-in duration-150 motion-reduce:transition-none"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm" aria-hidden="true" />
                </TransitionChild>

                <TransitionChild
                    as={Fragment}
                    enter="ease-out duration-300 motion-reduce:transition-none motion-reduce:duration-0"
                    enterFrom="-translate-x-full"
                    enterTo="translate-x-0"
                    leave="ease-in duration-200 motion-reduce:transition-none motion-reduce:duration-0"
                    leaveFrom="translate-x-0"
                    leaveTo="-translate-x-full"
                >
                    <DialogPanel
                        id={id}
                        className="fixed inset-y-0 left-0 flex w-64 max-w-[80vw] flex-col overflow-hidden border-r border-slate-200/70 bg-slate-50 shadow-xl shadow-slate-900/10"
                    >
                        {/* Same soft white → slate → blue-50 wash as the desktop
                            <aside>, so the drawer reads as the same surface. */}
                        <div aria-hidden="true" className="pointer-events-none absolute inset-0">
                            <div className="absolute inset-0 bg-gradient-to-b from-white via-slate-50 to-blue-50/50" />
                            <div className="absolute -top-16 left-1/2 h-52 w-52 -translate-x-1/2 rounded-full bg-blue-200/25 blur-3xl" />
                        </div>

                        <AppNav
                            permissions={permissions}
                            currentPath={currentPath}
                            companyName={companyName}
                            onNavigate={onClose}
                        />
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </Transition>
    );
}
