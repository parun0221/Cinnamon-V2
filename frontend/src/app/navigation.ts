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
 *
 * `adminOnly` marks the modules the Karyawan may not use, per Docs/PRD.md:
 * the Karyawan records incoming goods and manages pending products, and
 * cannot change master data, record sales, correct stock, read reports, or
 * manage users. The server enforces all of it — this only keeps the menu
 * honest.
 */
export const navGroups: NavGroup[] = [
  {
    label: 'Dashboard',
    items: [{ label: 'Dashboard', to: '/' }],
  },
  {
    label: 'Master Data',
    items: [
      { label: 'Kategori', to: '/master/categories', adminOnly: true },
      { label: 'Produk', to: '/master/products', adminOnly: true },
      // The Karyawan picks a supplier when recording a receipt.
      { label: 'Supplier', to: '/master/suppliers' },
      { label: 'Customer', to: '/master/customers', adminOnly: true },
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
      { label: 'Penjualan Baru', to: '/sales/new', adminOnly: true },
      { label: 'Riwayat', to: '/sales/history', adminOnly: true },
    ],
  },
  {
    label: 'Inventori',
    items: [
      { label: 'Ringkasan Stok', to: '/inventory/stock', adminOnly: true },
      { label: 'Koreksi', to: '/inventory/adjustments', adminOnly: true },
      { label: 'Movement', to: '/inventory/movements', adminOnly: true },
    ],
  },
  {
    label: 'Laporan',
    items: [
      { label: 'Penerimaan', to: '/reports/incoming', adminOnly: true },
      { label: 'Pengeluaran', to: '/reports/outgoing', adminOnly: true },
      { label: 'Stok', to: '/reports/stock', adminOnly: true },
      { label: 'Profit', to: '/reports/profit', adminOnly: true },
      { label: 'Ledger', to: '/reports/ledger', adminOnly: true },
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
