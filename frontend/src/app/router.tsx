import { createBrowserRouter } from 'react-router-dom'
import { AppShell } from './app-shell'
import { AuthGuard } from '../features/auth/AuthGuard'
import { LoginPage } from '../features/auth/LoginPage'
import { ProfilePage } from '../features/auth/ProfilePage'
import { UserManagementPage } from '../features/auth/UserManagementPage'
import { DashboardPage } from '../features/dashboard/DashboardPage'
import { NotFoundPage } from '../features/misc/NotFoundPage'

export const router = createBrowserRouter([
  {
    path: '/login',
    element: <LoginPage />,
  },
  {
    element: <AuthGuard />,
    children: [
      {
        path: '/',
        element: <AppShell />,
        children: [
          { index: true, element: <DashboardPage /> },
          { path: 'profile', element: <ProfilePage /> },
          { path: 'admin/users', element: <UserManagementPage /> },
        ],
      },
    ],
  },
  {
    // Outside AuthGuard so a guest who mistypes an address gets the same
    // explanation instead of a login redirect.
    path: '*',
    element: <NotFoundPage />,
  },
])
