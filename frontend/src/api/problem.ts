/**
 * RFC 9457 `application/problem+json`, as the SPA sees it.
 *
 * Every non-2xx response from this API has this shape (ADR-0007), so the client parses one
 * thing rather than guessing per endpoint.
 */
export interface Problem {
  type: string
  title: string
  status: number
  detail: string
  instance?: string
  requestId?: string
  errors?: ValidationError[]
  /** Endpoint-specific extras, e.g. `retryAfter` on a lockout. */
  [key: string]: unknown
}

export interface ValidationError {
  field: string
  message: string
}

/**
 * A failed request, carrying the parsed problem.
 *
 * A typed error rather than a rejected promise with a string: callers need the status to
 * decide what to do, and the field errors to show next to the inputs that caused them.
 */
export class ApiError extends Error {
  // Explicit fields rather than constructor parameter properties: the app tsconfig sets
  // `erasableSyntaxOnly`, which forbids syntax that cannot be removed by type-stripping alone.
  readonly problem: Problem
  readonly response: Response

  constructor(problem: Problem, response: Response) {
    super(problem.detail || problem.title)
    this.name = 'ApiError'
    this.problem = problem
    this.response = response
  }

  get status(): number {
    return this.problem.status
  }

  /** Validation failures, keyed by field, ready to render beside a form control. */
  get fieldErrors(): Record<string, string> {
    const errors: Record<string, string> = {}
    for (const error of this.problem.errors ?? []) {
      // First message per field: showing three variations of "this is required" helps nobody.
      errors[error.field] ??= error.message
    }
    return errors
  }

  /**
   * The id to quote in a support request.
   *
   * The same value is on the server's log line and, for anything security-relevant, on the
   * audit row — which is what turns "it broke this morning" into a query.
   */
  get requestId(): string | undefined {
    return this.problem.requestId
  }
}

/**
 * Parse a failed response into a Problem, whatever actually came back.
 *
 * A gateway timeout or a crashed process produces HTML or nothing at all, and the client must
 * still produce something typed rather than throwing while handling an error.
 */
export async function parseProblem(response: Response): Promise<Problem> {
  const fallback: Problem = {
    type: 'about:blank',
    title: response.statusText || 'Die Anfrage ist fehlgeschlagen',
    status: response.status,
    detail: 'Der Server hat unerwartet geantwortet.',
  }

  const contentType = response.headers.get('content-type') ?? ''
  if (!contentType.includes('json')) {
    return fallback
  }

  try {
    const body = (await response.json()) as unknown
    if (typeof body !== 'object' || body === null) {
      return fallback
    }
    return { ...fallback, ...(body as Partial<Problem>) } as Problem
  } catch {
    return fallback
  }
}
