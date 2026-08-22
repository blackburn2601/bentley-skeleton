# Recipe: add an Application service

## 0. First, check one already doesn't exist

```bash
grep -i "<topic>" docs/SERVICES.md
```

`docs/SERVICES.md` lists every service with its one-sentence responsibility. This step exists
because writing a duplicate is the most common mistake made by someone without full context —
and the inventory is generated precisely so the check is cheap.

## 1. Generate

```bash
make service
```

You are asked for the context, the name (without the `Service` suffix), and the
responsibility sentence. Without a terminal, pass them:

```bash
make service CONTEXT=Account NAME=RotateRefreshToken \
  WHY="Rotates a refresh token, revoking the family on reuse"
```

**Write the sentence before you write the code.** If it needs an "and", you are describing
two services — generate two. The generator warns you; PHPStan then fails the build
(`bentley.serviceHasTwoTopics`). This is INV-10, and it is the rule the whole architecture
leans on: it is what makes `docs/SERVICES.md` useful and what keeps a PDF service from
growing string-normalization helpers.

## 2. Implement

Rules the build enforces:

- `final readonly`, constructor injection only. No static state, no setters, no container
  (INV-09).
- No `Request`, `Response`, `Session` or `HttpException` — the service must be callable from
  a console command or a test with no HTTP anywhere (INV-08).
- Throw **domain** exceptions. `ProblemJsonExceptionListener` is the only thing that knows
  about status codes (INV-17).
- Own your transaction here if the work spans more than one write (INV-06).
- No `Doctrine\ORM\QueryBuilder` — that belongs in `Infrastructure`.
- **Do not create an interface for it.** One implementation means no interface (INV-12).
  Introduce one when a second implementation or a genuine port appears.

## 3. Calling another context

You cannot call another context's services directly. Use its facade:

```php
public function __construct(private AclFacade $acl) {}
```

If the facade lacks the method you need, add it there. That is a visible, reviewable change —
which is the point.

## 4. Verify

```bash
make check
make docs      # adds the service to docs/SERVICES.md; commit it
```

## Checklist

- [ ] Checked `docs/SERVICES.md` for an existing owner of this topic
- [ ] Responsibility is one sentence with no conjunction
- [ ] `final readonly`, constructor injection only
- [ ] No HTTP types, no QueryBuilder, no ceremonial interface
- [ ] Unit test asserts the behaviour the sentence describes
- [ ] `make check` green, `make docs` no diff
