# Veggie Tokri — Coding Conventions (Stage 0)

## Language and runtime

- PHP **8.2+** with `declare(strict_types=1);` in every new PHP file.
- **PDO** with prepared statements only; no string-concatenated SQL for user input.
- No Composer, npm, Node, or PHP frameworks.

## Project layout

- **Document-root PHP pages** at project root (`index.php`, `cart.php`, …).
- **Shared logic** in `includes/` (helpers, domain services added per stage).
- **Admin** under `admin/` (Stage 4+).
- **AJAX** under `ajax/` — thin controllers; business logic in `includes/`.
- **Webhooks** in `webhooks/`; **cron** scripts CLI-only in `cron/`.

## Naming

- Classes: `PascalCase` in dedicated files matching the class name (`Database.php` optional; Stage 0 uses grouped files like `security.php` with multiple related classes until split in later stages).
- Functions: `snake_case` only for plain functions; prefer static methods on small service classes.
- Database: `snake_case` table and column names (Stage 5+).
- URL slugs: lowercase hyphenated ASCII (see requirements SEO section).

## Configuration

- Committed template: `includes/config.example.php`.
- Local/server config: `includes/config.local.php` (**never commit**).
- Encryption master key: file path in config, file **outside public webroot** in production.

## Security (always)

- Escape output with `Security::escape()` in HTML.
- Validate input server-side with `Validation` helpers; extend per form in later stages.
- State-changing requests: `Csrf::requireValid()` in forms and AJAX POST/PUT/PATCH/DELETE.
- Never trust client-submitted price, stock, discount, payment status, or role.
- Use DB transactions for orders, payments, stock, coupons, subscriptions.

## AJAX

- Use `Ajax::success()` / `Ajax::error()` for JSON responses.
- Send header `X-Requested-With: XMLHttpRequest` or `Accept: application/json` from jQuery.
- Include CSRF token in POST bodies as `_csrf_token` or header `X-CSRF-Token`.

## Errors and logging

- Production: `app.debug` = false; no stack traces to users.
- Log via PHP `error_log` to `storage/logs/` (writable by web server user).

## Frontend (Stage 1+)

- Tailwind CSS via CDN; jQuery for AJAX.
- Brand colors: green `#245C3A`, yellow `#F5A623`, cream `#FFF8E9`, charcoal `#292D29`.
- Mobile-first responsive layouts from 320px up.

## Stage discipline

- Implement **one requirements stage at a time**.
- Before editing, inspect existing files; do not rewrite completed modules without reason.
- Update `README.md` and `database/database.sql` when schema or setup steps change.
