import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { IconInfoCircle } from '@tabler/icons-react';

interface InfoTipProps {
    /** The help text: one short, plain sentence. */
    text: string;
    /** Accessible name of the ⓘ button, e.g. "About the zone name". */
    label?: string;
    className?: string;
}

/** Gap kept between the tip and the edge of the screen, in px. */
const EDGE = 8;

/**
 * A small ⓘ help button. Its text shows on hover, on keyboard focus and on
 * tap (a click toggles it, for touch screens); Escape or a click elsewhere
 * closes it. The text is always the button's accessible description
 * (aria-describedby → role="tooltip"), and the tip is nudged sideways so it
 * never runs off a narrow screen.
 */
export function InfoTip({ text, label = 'More information', className = '' }: InfoTipProps) {
    const id = useId();
    const wrapperRef = useRef<HTMLSpanElement>(null);
    const tipRef = useRef<HTMLSpanElement>(null);
    const [hovered, setHovered] = useState(false);
    const [focused, setFocused] = useState(false);
    const [pinned, setPinned] = useState(false);
    const [shift, setShift] = useState(0);

    const open = hovered || focused || pinned;

    function close() {
        setHovered(false);
        setFocused(false);
        setPinned(false);
    }

    useEffect(() => {
        if (!open) {
            return;
        }

        function onKeyDown(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                close();
            }
        }

        function onPointerDown(event: PointerEvent) {
            if (wrapperRef.current && !wrapperRef.current.contains(event.target as Node)) {
                close();
            }
        }

        document.addEventListener('keydown', onKeyDown);
        document.addEventListener('pointerdown', onPointerDown);

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            document.removeEventListener('pointerdown', onPointerDown);
        };
    }, [open]);

    // Keep the tip on screen: measure where it would sit unshifted, then
    // nudge it back inside the viewport.
    useLayoutEffect(() => {
        if (!open || !tipRef.current) {
            setShift(0);

            return;
        }

        const rect = tipRef.current.getBoundingClientRect();
        const left = rect.left - shift;
        const right = rect.right - shift;
        const maxRight = document.documentElement.clientWidth - EDGE;

        if (left < EDGE) {
            setShift(EDGE - left);
        } else if (right > maxRight) {
            setShift(maxRight - right);
        } else {
            setShift(0);
        }
        // Only re-measured when the tip opens or its text changes.
    }, [open, text]);

    return (
        <span
            ref={wrapperRef}
            className={`relative inline-flex align-middle ${className}`}
            onMouseEnter={() => setHovered(true)}
            onMouseLeave={() => setHovered(false)}
        >
            <button
                type="button"
                aria-label={label}
                aria-describedby={id}
                onFocus={() => setFocused(true)}
                onBlur={() => setFocused(false)}
                onClick={() => (open ? close() : setPinned(true))}
                className="inline-flex size-5 items-center justify-center rounded-full text-slate-400 transition-colors hover:text-blue-600 focus-visible:text-blue-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-blue-600"
            >
                <IconInfoCircle size={16} stroke={1.75} aria-hidden="true" />
            </button>
            <span
                ref={tipRef}
                id={id}
                role="tooltip"
                style={{ marginLeft: shift }}
                className={`absolute top-full left-1/2 z-50 mt-1.5 w-max max-w-[min(16rem,calc(100vw-1rem))] -translate-x-1/2 rounded-lg bg-slate-900 px-3 py-2 text-left text-xs leading-snug font-normal text-white shadow-lg ${
                    open ? '' : 'hidden'
                }`}
            >
                {text}
            </span>
        </span>
    );
}
