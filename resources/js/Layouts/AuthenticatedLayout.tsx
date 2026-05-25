import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import { Toaster } from '@/Components/ui/sonner';
import { Link, usePage } from '@inertiajs/react';
import { PropsWithChildren, ReactNode, useState } from 'react';

interface NavItem {
    label: string;
    href: string;
    routeMatch: string;
    /** Single required permission. */
    permission?: string;
    /** Any of these permissions. Mutually exclusive with `permission`. */
    anyPermission?: string[];
}

interface NavSection {
    label?: string;
    items: NavItem[];
}

export default function Authenticated({
    header,
    children,
}: PropsWithChildren<{ header?: ReactNode }>) {
    const { auth } = usePage().props;
    const user = auth.user;
    const can = (perm: string) => auth.permissions?.includes(perm) ?? false;

    const [mobileOpen, setMobileOpen] = useState(false);

    const sections: NavSection[] = [
        {
            items: [
                { label: 'Dashboard', href: route('dashboard'), routeMatch: 'dashboard' },
                {
                    label: 'Kasir',
                    href: route('pos.cashier'),
                    routeMatch: 'pos.*',
                    permission: 'pos.access',
                },
                {
                    label: 'Riwayat Penjualan',
                    href: route('sales.index'),
                    routeMatch: 'sales.*',
                    permission: 'pos.access',
                },
            ],
        },
        {
            label: 'Master Data',
            items: [
                {
                    label: 'Produk',
                    href: route('master.products.index'),
                    routeMatch: 'master.products.*',
                    permission: 'master.manage',
                },
                {
                    label: 'Kategori',
                    href: route('master.categories.index'),
                    routeMatch: 'master.categories.*',
                    permission: 'master.manage',
                },
                {
                    label: 'Gudang / Cabang',
                    href: route('master.warehouses.index'),
                    routeMatch: 'master.warehouses.*',
                    permission: 'master.manage',
                },
                {
                    label: 'Pelanggan',
                    href: route('master.customers.index'),
                    routeMatch: 'master.customers.*',
                    permission: 'customer.manage',
                },
                {
                    label: 'Promo',
                    href: route('master.promos.index'),
                    routeMatch: 'master.promos.*',
                    permission: 'promo.manage',
                },
                {
                    label: 'Racikan',
                    href: route('master.compounds.index'),
                    routeMatch: 'master.compounds.*',
                    permission: 'master.compounds',
                },
                {
                    label: 'Jasa & Tindakan',
                    href: route('master.services.index'),
                    routeMatch: 'master.services.*',
                    permission: 'master.services',
                },
                {
                    label: 'Supplier',
                    href: route('master.suppliers.index'),
                    routeMatch: 'master.suppliers.*',
                    permission: 'purchasing.supplier_manage',
                },
            ],
        },
        {
            label: 'Purchasing',
            items: [
                {
                    label: 'Purchase Request',
                    href: route('purchasing.requests.index'),
                    routeMatch: 'purchasing.requests.*',
                    anyPermission: ['purchasing.pr_create', 'purchasing.pr_approve'],
                },
                {
                    label: 'Purchase Order',
                    href: route('purchasing.orders.index'),
                    routeMatch: 'purchasing.orders.*',
                    anyPermission: ['purchasing.po_create', 'purchasing.po_approve'],
                },
                {
                    label: 'Penerimaan',
                    href: route('purchasing.receipts.index'),
                    routeMatch: 'purchasing.receipts.*',
                    permission: 'purchasing.receive',
                },
                {
                    label: 'Hutang Supplier',
                    href: route('purchasing.payables.index'),
                    routeMatch: 'purchasing.payables.*',
                    permission: 'purchasing.ap_view',
                },
            ],
        },
        {
            label: 'Inventori',
            items: [
                {
                    label: 'Inventori',
                    href: route('inventory.stock'),
                    routeMatch: 'inventory.stock',
                    permission: 'inventory.view',
                },
                {
                    label: 'Penyesuaian Stok',
                    href: route('inventory.adjustments.index'),
                    routeMatch: 'inventory.adjustments.*',
                    permission: 'inventory.adjustment',
                },
                {
                    label: 'Stock Opname',
                    href: route('inventory.opnames.index'),
                    routeMatch: 'inventory.opnames.*',
                    permission: 'inventory.opname',
                },
                {
                    label: 'Racik Obat',
                    href: route('pharmacy.compound.index'),
                    routeMatch: 'pharmacy.*',
                    permission: 'pharmacy.compound',
                },
                {
                    label: 'Jurnal',
                    href: route('accounting.journal'),
                    routeMatch: 'accounting.*',
                    permission: 'accounting.view',
                },
            ],
        },
        {
            label: 'Pengaturan',
            items: [
                {
                    label: 'Tenant',
                    href: route('settings.tenant'),
                    routeMatch: 'settings.tenant',
                    permission: 'settings.tenant',
                },
                {
                    label: 'User',
                    href: route('settings.users.index'),
                    routeMatch: 'settings.users.*',
                    permission: 'settings.users',
                },
                {
                    label: 'Role',
                    href: route('settings.roles.index'),
                    routeMatch: 'settings.roles.*',
                    permission: 'settings.roles',
                },
            ],
        },
    ];

    const visibleSections = sections
        .map((s) => ({
            ...s,
            items: s.items.filter((i) => {
                if (i.anyPermission) return i.anyPermission.some(can);
                if (i.permission) return can(i.permission);
                return true;
            }),
        }))
        .filter((s) => s.items.length > 0);

    // Collapsible groups — state resets on each mount (no persistence by design).
    // Groups containing the active route auto-expand on initial render.
    const [expandedGroups, setExpandedGroups] = useState<Set<string>>(() => {
        const expanded = new Set<string>();
        visibleSections.forEach((s) => {
            if (s.label && s.items.some((i) => route().current(i.routeMatch))) {
                expanded.add(s.label);
            }
        });
        return expanded;
    });

    const toggleGroup = (label: string) =>
        setExpandedGroups((prev) => {
            const next = new Set(prev);
            if (next.has(label)) next.delete(label);
            else next.add(label);
            return next;
        });

    function NavItemLink({ item }: { item: NavItem }) {
        const active = route().current(item.routeMatch);
        return (
            <Link
                href={item.href}
                onClick={() => setMobileOpen(false)}
                className={
                    'flex items-center border-l-2 px-4 py-2 text-sm font-medium transition-colors ' +
                    (active
                        ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                        : 'border-transparent text-gray-700 hover:bg-gray-50 hover:text-gray-900')
                }
            >
                {item.label}
            </Link>
        );
    }

    const sidebarContent = (
        <>
            <div className="flex h-16 shrink-0 items-center border-b border-gray-100 px-4">
                <Link href={route('dashboard')} onClick={() => setMobileOpen(false)}>
                    <ApplicationLogo className="block h-8 w-auto fill-current text-gray-800" />
                </Link>
            </div>
            <nav className="flex-1 overflow-y-auto py-4">
                {visibleSections.map((section, sIdx) => {
                    const isGroup = !!section.label;
                    const expanded = isGroup ? expandedGroups.has(section.label!) : true;
                    return (
                        <div key={sIdx} className={sIdx > 0 ? 'mt-6' : ''}>
                            {isGroup && (
                                <button
                                    type="button"
                                    onClick={() => toggleGroup(section.label!)}
                                    aria-expanded={expanded}
                                    className="mb-2 flex w-full items-center justify-between px-4 text-xs font-semibold uppercase tracking-wider text-gray-400 transition-colors hover:text-gray-600"
                                >
                                    <span>{section.label}</span>
                                    <svg
                                        className={
                                            'h-3 w-3 transform transition-transform duration-200 ' +
                                            (expanded ? 'rotate-90' : '')
                                        }
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path
                                            fillRule="evenodd"
                                            d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                            clipRule="evenodd"
                                        />
                                    </svg>
                                </button>
                            )}
                            {expanded && (
                                <div className="space-y-0.5">
                                    {section.items.map((item) => (
                                        <NavItemLink key={item.label} item={item} />
                                    ))}
                                </div>
                            )}
                        </div>
                    );
                })}
            </nav>
        </>
    );

    return (
        <div className="flex min-h-screen bg-gray-100">
            <Toaster />

            {/* Mobile overlay */}
            {mobileOpen && (
                <div
                    className="fixed inset-0 z-30 bg-black/50 sm:hidden"
                    onClick={() => setMobileOpen(false)}
                    aria-hidden="true"
                />
            )}

            {/* Sidebar — fixed desktop, drawer mobile */}
            <aside
                className={
                    'fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-gray-200 bg-white transform transition-transform duration-200 sm:static sm:translate-x-0 ' +
                    (mobileOpen ? 'translate-x-0' : '-translate-x-full sm:translate-x-0')
                }
            >
                {sidebarContent}
            </aside>

            {/* Right column */}
            <div className="flex min-w-0 flex-1 flex-col">
                {/* Top bar */}
                <div className="flex h-16 shrink-0 items-center justify-between border-b border-gray-100 bg-white px-4 sm:px-6">
                    <button
                        type="button"
                        onClick={() => setMobileOpen(true)}
                        className="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-500 focus:outline-none sm:hidden"
                        aria-label="Buka menu"
                    >
                        <svg
                            className="h-6 w-6"
                            stroke="currentColor"
                            fill="none"
                            viewBox="0 0 24 24"
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth="2"
                                d="M4 6h16M4 12h16M4 18h16"
                            />
                        </svg>
                    </button>

                    <div className="flex-1 sm:hidden" />

                    <div className="relative">
                        <Dropdown>
                            <Dropdown.Trigger>
                                <span className="inline-flex rounded-md">
                                    <button
                                        type="button"
                                        className="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-600 transition duration-150 ease-in-out hover:text-gray-900 focus:outline-none"
                                    >
                                        {user.name}
                                        <svg
                                            className="-me-0.5 ms-2 h-4 w-4"
                                            xmlns="http://www.w3.org/2000/svg"
                                            viewBox="0 0 20 20"
                                            fill="currentColor"
                                        >
                                            <path
                                                fillRule="evenodd"
                                                d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                clipRule="evenodd"
                                            />
                                        </svg>
                                    </button>
                                </span>
                            </Dropdown.Trigger>

                            <Dropdown.Content>
                                <Dropdown.Link href={route('profile.edit')}>
                                    Profile
                                </Dropdown.Link>
                                <Dropdown.Link
                                    href={route('logout')}
                                    method="post"
                                    as="button"
                                >
                                    Log Out
                                </Dropdown.Link>
                            </Dropdown.Content>
                        </Dropdown>
                    </div>
                </div>

                {header && (
                    <header className="bg-white shadow">
                        <div className="px-4 py-6 sm:px-6 lg:px-8">{header}</div>
                    </header>
                )}

                <main className="flex-1">{children}</main>
            </div>
        </div>
    );
}
