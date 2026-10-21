export type UserRole = 'admin' | 'karyawan'

export interface User {
  id: string
  name: string
  email: string
  role: UserRole
  is_active: boolean
  last_login_at: string | null
  created_at?: string | null
  updated_at?: string | null
}

export interface Meta {
  request_id: string
}

export interface Envelope<T> {
  data: T
  meta: Meta
}

export interface PaginatedMeta extends Meta {
  current_page: number
  per_page: number
  last_page: number
  total: number
}

export interface PaginatedEnvelope<T> {
  data: T[]
  meta: PaginatedMeta
}
