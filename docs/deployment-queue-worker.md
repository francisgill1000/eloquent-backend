# Queue worker (required for WhatsApp auto-replies)

The backend dispatches `ProcessWaReply` jobs (database queue) for every inbound
WhatsApp message. Without a running worker, messages are stored but **no
auto-reply is ever sent**. The droplet needs a supervisor-managed worker.

## Supervisor program

`/etc/supervisor/conf.d/rezzy-backend-worker.conf`:

```ini
[program:rezzy-backend-worker]
command=php /var/www/rezzy-backend/artisan queue:work --queue=default --sleep=1 --tries=1 --max-time=3600
directory=/var/www/rezzy-backend
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/rezzy-backend/storage/logs/worker.log
stopwaitsecs=130
```

Adjust `/var/www/rezzy-backend` to the actual deploy path. Then:

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start rezzy-backend-worker:*
sudo supervisorctl status   # must show RUNNING
```

Notes:
- `--tries=1` is deliberate: a failed reply must never retry (risk of double-sending to a customer). Failures log to `storage/logs/laravel.log` and the chat stays answerable manually in bizrezzy.
- `--max-time=3600` recycles the worker hourly (memory hygiene); supervisor restarts it.
- `stopwaitsecs=130` > the job timeout (120s) so in-flight replies finish on restart.

## On every redeploy

After `php artisan migrate --force`, add:

```bash
php artisan queue:restart
```

so workers pick up the new code (supervisor restarts them automatically).

## Required production env (auto-reply)

| Var | Purpose |
|---|---|
| `ANTHROPIC_API_KEY` | Claude replies |
| `CLAUDE_MODEL` | default `claude-haiku-4-5` |
| `OPENAI_API_KEY` | Whisper + TTS (absent → text-only, voice features off) |
| `WHATSAPP_APP_SECRET` | Meta webhook signature verification (from the Meta app) |
| `WHATSAPP_SALES_PHONE_NUMBER_ID` | the Rezzy sales line's phone_number_id |
| `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` | web push (copy from the old Node `.env` so existing browser subscriptions keep working) |

The sales line also needs a `wa_accounts` row (`shop_id = null`,
`phone_number_id` = the sales id) so its webhook traffic is stored.
