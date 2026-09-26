export type ProjectStatus = 'pending' | 'crawling' | 'embedding' | 'analyzing' | 'done' | 'failed'

export interface Progress {
  pages_found: number
  pages_crawled: number
  pages_embedded: number
}

export interface Project {
  id: string
  name: string
  sitemap_url: string
  status: ProjectStatus
  status_label: string
  is_terminal: boolean
  skip_taxonomies: boolean
  is_demo: boolean
  error_message: string | null
  progress: Progress
  created_at: string | null
  updated_at: string | null
}

/** The deliberately small payload returned by the polling endpoint. */
export interface ProjectStatusPayload {
  status: ProjectStatus
  status_label: string
  is_terminal: boolean
  error_message: string | null
  progress: Progress
}

export interface AuthUser {
  id: number
  name: string
  email: string
}

/** Laravel wraps single resources in `data` and paginated ones in `data` + `meta`. */
export interface Wrapped<T> {
  data: T
}
