import type { ReactNode } from 'react'
import './PageState.css'

type PageStateVariant = 'loading' | 'empty' | 'error'

interface PageStateProps {
  variant: PageStateVariant
  title?: string
  message?: string
  action?: ReactNode
  onRetry?: () => void
}

const DEFAULT_TITLES: Record<PageStateVariant, string> = {
  loading: 'Memuat data…',
  empty: 'Belum ada data',
  error: 'Gagal memuat data',
}

const DEFAULT_MESSAGES: Record<PageStateVariant, string> = {
  loading: 'Mohon tunggu sebentar.',
  empty: 'Daftar ini masih kosong.',
  error: 'Silakan coba lagi. Jika masalah berlanjut, hubungi Admin.',
}

export function PageState({ variant, title, message, action, onRetry }: PageStateProps) {
  if (variant === 'loading') {
    return (
      <div
        className="page-state page-state--loading"
        role="status"
        aria-live="polite"
        aria-busy="true"
      >
        <span className="page-state__label">{title ?? DEFAULT_TITLES.loading}</span>
        <div className="page-state__skeleton" aria-hidden="true">
          <span />
          <span />
          <span />
        </div>
      </div>
    )
  }

  return (
    <div
      className={`page-state page-state--${variant}`}
      role={variant === 'error' ? 'alert' : 'status'}
    >
      <p className="page-state__title">{title ?? DEFAULT_TITLES[variant]}</p>
      <p className="page-state__message">{message ?? DEFAULT_MESSAGES[variant]}</p>

      {variant === 'error' && onRetry && !action && (
        <button type="button" className="page-state__action" onClick={onRetry}>
          Coba lagi
        </button>
      )}

      {action}
    </div>
  )
}
