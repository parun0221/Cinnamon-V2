export interface NavItem {
  label: string
  to: string
  adminOnly?: boolean
}

export interface NavGroup {
  label: string
  items: NavItem[]
}

/**
 * Module order follows the workday, per Docs/Design.md §3. Modules whose page
 * does not exist yet stay in the list as links to the 404 route: the roadmap
 * fixes the order, and hiding a module that is simply not built yet would be
 * less honest than showing where it will live.
 */
export const navGroups: NavGroup[] = [
  {
    label: 'Dashboard',
    items: [{ label: 'Dashboard', to: '/' }],
  },
  {
    label: 'Master Data',
    items: [
      { label: 'Kategori', to: '/master/categories' },
      { label: 'Produk', to: '/master/products' },
      { label: 'Supplier', to: '/master/suppliers' },
      { label: 'Customer', to: '/master/customers' },
    ],
  },
  {
    label: 'Penerimaan',
    items: [
      { label: 'Penerimaan Baru', to: '/receiving/new' },
      { label: 'Verifikasi Pending', to: '/receiving/pending' },
      { label: 'Riwayat', to: '/receiving/history' },
    ],
  },
  {
    label: 'Penjualan',
    items: [
      { label: 'Penjualan Baru', to: '/sales/new' },
      { label: 'Riwayat', to: '/sales/history' },
    ],
  },
  {
    label: 'Inventori',
    items: [
      { label: 'Ringkasan Stok', to: '/inventory/stock' },
      { label: 'Koreksi', to: '/inventory/adjustments' },
      { label: 'Movement', to: '/inventory/movements' },
    ],
  },
  {
    label: 'Laporan',
    items: [
      { label: 'Penerimaan', to: '/reports/incoming' },
      { label: 'Pengeluaran', to: '/reports/outgoing' },
      { label: 'Stok', to: '/reports/stock' },
      { label: 'Profit', to: '/reports/profit' },
      { label: 'Ledger', to: '/reports/ledger' },
    ],
  },
  {
    label: 'Administrasi',
    items: [
      { label: 'Pengguna', to: '/admin/users', adminOnly: true },
      { label: 'Profil', to: '/profile' },
    ],
  },
]

/**
 * Filters the module list for the actor's role. The server remains the
 * authority — this only keeps the menu honest.
 */
export function navGroupsForRole(role: string | undefined): NavGroup[] {
  if (!role) {
    return []
  }

  return navGroups
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => !item.adminOnly || role === 'admin'),
    }))
    .filter((group) => group.items.length > 0)
}
