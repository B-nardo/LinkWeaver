import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'

import App from './App'
import './index.css'
import { ApiError } from './lib/api'
import { ThemeProvider } from './lib/theme'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      // A sleeping free-tier backend makes the first request fail in ways a
      // retry genuinely fixes, so network and timeout errors are retried.
      // Client errors never are: a 404 or 422 will not resolve itself.
      retry: (failureCount, error) => {
        if (error instanceof ApiError && error.kind === 'http') {
          return false
        }

        return failureCount < 2
      },
    },
  },
})

const rootElement = document.getElementById('root')

if (rootElement === null) {
  throw new Error('Root element #root is missing from index.html.')
}

createRoot(rootElement).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <ThemeProvider>
        <BrowserRouter>
          <App />
        </BrowserRouter>
      </ThemeProvider>
    </QueryClientProvider>
  </StrictMode>,
)
