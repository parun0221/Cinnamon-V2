import { useAuth } from '../auth/auth-context'

export function DashboardPage() {
  const { user } = useAuth()

  return (
    <section>
      <h1>Dashboard</h1>
      <p className="dashboard__intro">
        Selamat datang, {user?.name}. Modul inventory dan penjualan menyusul pada fase berikutnya.
      </p>
      <dl className="dashboard__facts">
        <div>
          <dt>Peran</dt>
          <dd>{user?.role === 'admin' ? 'Admin' : 'Karyawan'}</dd>
        </div>
        <div>
          <dt>Email</dt>
          <dd>{user?.email}</dd>
        </div>
      </dl>
    </section>
  )
}
