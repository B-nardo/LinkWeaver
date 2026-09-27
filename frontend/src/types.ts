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

export type PageClassification = 'orphan' | 'weak' | 'linked'

export interface ProjectPage {
  id: number
  url: string
  normalized_url: string
  title: string | null
  h1: string | null
  word_count: number
  http_status: number | null
  crawl_error: string | null
  crawled_at: string | null
  inbound_count: number
  outbound_count: number
  classification: PageClassification
  classification_label: string
}

export interface GraphNode {
  id: number
  url: string
  title: string
  inbound: number
  outbound: number
  classification: PageClassification
  crawled: boolean
}

export interface GraphEdge {
  source: number
  target: number
}

export interface GraphSummary {
  pages: number
  orphans: number
  weak: number
  linked: number
  edges: number
  /** Pages that failed to crawl, so their outbound links are unknown. */
  uncrawled: number
}

export interface GraphPayload {
  nodes: GraphNode[]
  edges: GraphEdge[]
  summary: GraphSummary
}

export interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export interface PageQueryParams {
  filter?: PageClassification | 'attention' | null
  sort?: 'inbound' | 'outbound' | 'title' | 'words' | 'url'
  direction?: 'asc' | 'desc'
  page?: number
}
