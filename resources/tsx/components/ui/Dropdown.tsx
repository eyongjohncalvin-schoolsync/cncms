import { Menu, MenuButton, MenuItem, MenuItems, MenuSeparator } from '@headlessui/react';
import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { ButtonHTMLAttributes, ReactNode } from 'react';
import { IconDotsVertical } from '@tabler/icons-react';

/**
 * A compact "kebab" dropdown menu built on @headlessui/react's Menu
 * primitives (already a project dependency via Modal.tsx's Dialog usage —
 * no new package added). Headless UI's `anchor` prop handles viewport-aware
 * positioning (flips upward when there's no room below) and auto-portals
 * the panel to <body>, so it's never clipped by a table's `overflow-x-auto`
 * wrapper. Outside-click, Escape-to-close, and full arrow-key navigation
 * all come for free from the same primitive that already powers Modal.tsx.
 *
 * Usage:
 *   <Dropdown label="Actions for Jane Doe">
 *       <DropdownItem href="/customers/1">View</DropdownItem>
 *       <DropdownItem href="/customers/1/edit">Edit</DropdownItem>
 *       <DropdownDivider />
 *       <DropdownItem onClick={...} variant="danger">Delete</DropdownItem>
 *   </Dropdown>
 */
interface DropdownProps {
    /** Custom trigger content. Defaults to an icon-only kebab button. */
    trigger?: ReactNode;
    /** Accessible label for the default kebab trigger button (ignored when `trigger` is supplied — give the custom trigger its own label instead). */
    label?: string;
    /** Horizontal alignment of the panel relative to the trigger. */
    align?: 'start' | 'end';
    className?: string;
    panelClassName?: string;
    children: ReactNode;
}

export function Dropdown({ trigger, label = 'Open menu', align = 'end', className = '', panelClassName = '', children }: DropdownProps) {
    return (
        <Menu as="div" className={`relative inline-block text-left ${className}`}>
            <MenuButton
                aria-label={trigger ? undefined : label}
                className={
                    trigger
                        ? 'rounded focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600'
                        : 'inline-flex items-center justify-center rounded-md p-1.5 text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 data-open:bg-slate-100 data-open:text-slate-700'
                }
            >
                {trigger ?? <IconDotsVertical size={18} stroke={1.75} />}
            </MenuButton>

            <MenuItems
                anchor={align === 'end' ? 'bottom end' : 'bottom start'}
                transition
                className={`z-40 w-48 origin-top rounded-lg bg-white p-1 shadow-lg shadow-slate-900/10 ring-1 ring-slate-900/5 transition duration-100 ease-out [--anchor-gap:6px] focus:outline-none data-closed:scale-95 data-closed:opacity-0 ${panelClassName}`}
            >
                {children}
            </MenuItems>
        </Menu>
    );
}

type DropdownItemVariant = 'default' | 'danger' | 'warning' | 'success';

const itemBaseClass =
    'flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-medium transition-colors focus:outline-none data-focus:bg-slate-100 data-disabled:cursor-not-allowed data-disabled:opacity-50';

const itemVariantClass: Record<DropdownItemVariant, string> = {
    default: 'text-slate-700',
    danger: 'text-red-600 data-focus:bg-red-50 data-focus:text-red-700',
    warning: 'text-amber-700 data-focus:bg-amber-50 data-focus:text-amber-800',
    success: 'text-green-700 data-focus:bg-green-50 data-focus:text-green-800',
};

interface DropdownItemCommonProps {
    variant?: DropdownItemVariant;
    icon?: ReactNode;
    className?: string;
    disabled?: boolean;
    children: ReactNode;
}

type DropdownLinkItemProps = DropdownItemCommonProps &
    Omit<InertiaLinkProps, 'className' | 'children' | 'href'> & {
        href: string;
    };

type DropdownButtonItemProps = DropdownItemCommonProps &
    Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'className' | 'children'> & {
        href?: undefined;
    };

export type DropdownItemProps = DropdownLinkItemProps | DropdownButtonItemProps;

/**
 * A single menu row — renders as an Inertia `<Link>` when `href` is given
 * (auto-rendered as a real `<button>` element, matching how the rest of the
 * app renders non-GET Links, whenever a non-GET `method` is passed), or a
 * plain `<button>` otherwise. Wrapped in Headless UI's `MenuItem`, which
 * merges its a11y props (role, roving tabIndex, `data-focus`/`data-disabled`
 * state, close-on-click) directly onto this child element.
 */
export function DropdownItem({ variant = 'default', icon, className = '', disabled, children, href, ...rest }: DropdownItemProps) {
    const classes = `${itemBaseClass} ${itemVariantClass[variant]} ${className}`;

    if (href !== undefined) {
        const linkRest = rest as Omit<InertiaLinkProps, 'className' | 'children' | 'href'>;
        const inferredAs = linkRest.method && linkRest.method !== 'get' ? 'button' : undefined;

        return (
            <MenuItem disabled={disabled}>
                <Link href={href} as={inferredAs} className={classes} {...linkRest}>
                    {icon}
                    {children}
                </Link>
            </MenuItem>
        );
    }

    const buttonRest = rest as ButtonHTMLAttributes<HTMLButtonElement>;

    return (
        <MenuItem disabled={disabled}>
            <button type="button" className={classes} disabled={disabled} {...buttonRest}>
                {icon}
                {children}
            </button>
        </MenuItem>
    );
}

export function DropdownDivider() {
    return <MenuSeparator className="my-1 h-px bg-slate-200" />;
}
