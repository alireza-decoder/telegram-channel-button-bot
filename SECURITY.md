# Security notes / نکات امنیتی

- Never open an issue containing your Telegram bot token, webhook secret, real `config.php`, channel admin details, or storage contents.
- Rotate any exposed token immediately with [@BotFather](https://t.me/BotFather), update your private `config.php`, and register a new webhook secret.
- Only `config.example.php` belongs in source control; `config.php` is explicitly ignored.
- `storage/` holds private per-user state and archive data. Do not upload it except for the safe placeholder files already tracked.
- Apache `.htaccess` files protect configuration and storage on Apache-compatible hosts. If using Nginx or a different web server, configure equivalent access-deny rules.
- Review staged files with `git diff --cached --name-only` and run a secret scan before making a repository public.
