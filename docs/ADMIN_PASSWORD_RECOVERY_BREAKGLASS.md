# Admin password recovery — break-glass (Super Admin)

Use only when **Forgot password** email cannot be delivered and no other active Super Admin can reset access.

## Preconditions

- Verify identity of the legitimate account holder through your organisation’s out-of-band process.
- Prefer restoring SMTP and using **Admin → Forgot password** when possible.

## Option A — Another Super Admin

An active Super Admin with `admin_users.manage` (or equivalent) can create a temporary password via **Admin → Users** (if implemented) or run a controlled CLI hash update on a **staging clone** first, then apply the same hash in production during a maintenance window.

## Option B — Database (last resort)

On the **MySQL host** (not over HTTP):

1. Generate a new bcrypt hash locally:  
   `php -r "echo password_hash('NEW_STRONG_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"`
2. Update the row (replace email and hash):  
   `UPDATE admin_users SET password_hash = '…', auth_epoch = auth_epoch + 1, updated_at = NOW() WHERE email = 'admin@example.com';`
3. Invalidate outstanding reset tokens:  
   `UPDATE admin_password_reset_tokens SET used_at = NOW() WHERE admin_user_id = ? AND used_at IS NULL;`
4. Record the event in your operational log (who/when/why). Do **not** store the plain password in tickets or chat.

## After recovery

- Force the user to change the password again from the admin console if a temporary password was used.
- Review `admin_audit_logs` for `admin.login_failed` spikes and `admin.password_reset_*` events.
- Fix SMTP configuration if email recovery failed.

**Never** commit passwords, reset tokens, or `config.local.php` to git.
