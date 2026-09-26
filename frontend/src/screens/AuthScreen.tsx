import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'

import { Button, Field } from '@/components/primitives'
import { ApiError, apiFetch } from '@/lib/api'
import { useAuth } from '@/lib/auth-context'
import type { AuthUser } from '@/types'

interface AuthResponse {
  token: string
  user: AuthUser
}

export function AuthScreen({ mode }: { mode: 'login' | 'register' }) {
  const isRegister = mode === 'register'
  const { signIn } = useAuth()
  const navigate = useNavigate()

  const [form, setForm] = useState({ name: '', email: '', password: '' })

  const mutation = useMutation({
    mutationFn: (payload: typeof form) =>
      apiFetch<AuthResponse>(isRegister ? '/auth/register' : '/auth/login', {
        method: 'POST',
        body: JSON.stringify(
          isRegister ? payload : { email: payload.email, password: payload.password },
        ),
      }),
    onSuccess: (response) => {
      signIn(response.token, response.user)
      void navigate('/projects')
    },
  })

  const fieldErrors =
    mutation.error instanceof ApiError ? (mutation.error.validationErrors ?? {}) : {}

  const generalError =
    mutation.error instanceof ApiError && mutation.error.validationErrors === null
      ? mutation.error.message
      : undefined

  return (
    <div className="mx-auto flex min-h-dvh max-w-md flex-col justify-center px-6 py-16">
      <h1 className="font-display text-ink text-3xl font-semibold">
        {isRegister ? 'Create an account' : 'Sign in'}
      </h1>
      <p className="text-ink-soft mt-2 text-sm">
        {isRegister
          ? 'Audits are private to your account.'
          : 'Pick up where your last audit left off.'}
      </p>

      <form
        className="mt-8 space-y-5"
        onSubmit={(event) => {
          event.preventDefault()
          mutation.mutate(form)
        }}
      >
        {isRegister && (
          <Field
            label="Name"
            autoComplete="name"
            required
            value={form.name}
            error={fieldErrors.name?.[0]}
            onChange={(e) => setForm({ ...form, name: e.target.value })}
          />
        )}

        <Field
          label="Email"
          type="email"
          autoComplete="email"
          required
          value={form.email}
          error={fieldErrors.email?.[0]}
          onChange={(e) => setForm({ ...form, email: e.target.value })}
        />

        <Field
          label="Password"
          type="password"
          autoComplete={isRegister ? 'new-password' : 'current-password'}
          required
          value={form.password}
          error={fieldErrors.password?.[0]}
          hint={isRegister ? 'At least 8 characters.' : undefined}
          onChange={(e) => setForm({ ...form, password: e.target.value })}
        />

        {generalError !== undefined && (
          <p role="alert" className="text-orphan text-sm">
            {generalError}
          </p>
        )}

        <Button type="submit" disabled={mutation.isPending} className="w-full">
          {mutation.isPending ? 'Working' : isRegister ? 'Create account' : 'Sign in'}
        </Button>
      </form>

      <p className="text-ink-soft mt-6 text-sm">
        {isRegister ? 'Already have an account? ' : 'No account yet? '}
        <Link
          to={isRegister ? '/login' : '/register'}
          className="text-opportunity underline underline-offset-4"
        >
          {isRegister ? 'Sign in' : 'Create one'}
        </Link>
      </p>
    </div>
  )
}
