# Varys - Budget App - Project Conventions

## Stack

- **Backend**: Laravel 12, PHP 8.4, PostgreSQL
- **Frontend**: React 19, Inertia.js v2, Tailwind CSS v4, shadcn/ui
- **Package Manager**: bun (not npm)
- **Testing**: Pest 4 (backend), Vitest (frontend)
- **Forms**: React Hook Form + Zod validation (or Inertia useForm for simple forms)
- **Charts**: Recharts
- **Build**: Vite 7, Wayfinder

## Quality Gate Commands

```bash
# Backend
vendor/bin/pint --dirty --format agent    # Code style
./vendor/bin/phpstan analyse --memory-limit=512M  # Static analysis (level 7)
php artisan test --compact                # Tests

# Frontend
bun check:all     # prettier + eslint + typescript
bun test:run      # vitest
```

## Wayfinder

- Always use: `php artisan wayfinder:generate --with-form`
- The Vite plugin has `formVariants: true` which does this automatically during dev/build.

## Architecture Patterns

- **Controllers** in `app/Http/Controllers/App/` — Inertia page controllers
- **Form Requests**: Always separate `Store` and `Update` request classes in `app/Http/Requests/App/`
- **Models**: Use `$guarded = ['id']`, `casts()` method, explicit relationship return types
- **Enums**: PHP backed enums in `app/Enums/`, TitleCase keys
- **Filters**: `app/Filters/` with `QueryFilter` base class, models use `Filterable` trait
- **Factories**: Always create factories and seeders for new models

## Database

- Primary connection: `pgsql` → `varys`
- Test database: `pgsql` → `varys_test`

## Frontend Structure

```
resources/js/
├── pages/           # Inertia page components
├── components/ui/   # shadcn/ui components
├── components/shared/ # Reusable app components
├── layouts/         # Layout components
├── hooks/           # React hooks
├── lib/             # Utility functions
└── test/            # Frontend test setup
```

## Conventions

- All UI text in Italian
- Currency: EUR, formatted with `formatCurrency()` from `@/lib/utils`
- Use curly braces for all control structures
- Constructor property promotion
- Explicit return types on all methods
- Array-based validation rules in Form Requests
