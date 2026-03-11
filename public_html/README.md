# yealinkcalllog

Minimal PHP + MySQL service that receives Yealink phone events via **Action URL**, stores them in MySQL, and shows a per-extension daily summary dashboard.

---

## Features

- **Auto-migrate** – tables are created on the very first request; no manual SQL needed.
- **`GET /yealink/event`** – ingest endpoint for Yealink Action URL callbacks.
- **`GET /dashboard`** – daily HR dashboard (received / answered / missed / answer-rate %).
- **`GET /setup`** – phone configuration helper: generates ready-to-copy Action URLs.
- Hard **12-month lookback** cap enforced in queries.
- Optional shared-secret protection via `YEALINK_TOKEN` env var.

---

## Project structure

```
public/
  index.php      ← web root entry point (point your web server here)
  .htaccess      ← Apache rewrite rules
src/
  Config.php     ← reads env vars
  Db.php         ← PDO MySQL connection
  Migrations.php ← auto schema migration
  Ingest.php     ← /yealink/event handler
  Report.php     ← /dashboard handler
  Setup.php      ← /setup phone-config helper
```

> **Zero dependencies** – no Composer required.

---

## Deployment

### 1. Point your web server at `public/`

#### Apache (with `mod_rewrite`)
Set `DocumentRoot` to the `public/` directory. The included `.htaccess` handles routing.

```apacheconf
<VirtualHost *:80>
    ServerName yealink.example.com
    DocumentRoot /var/www/yealinkcalllog/public

    <Directory /var/www/yealinkcalllog/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

#### Nginx
```nginx
server {
    listen 80;
    server_name yealink.example.com;
    root /var/www/yealinkcalllog/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.x-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

### 2. Create the MySQL database

```sql
CREATE DATABASE yealinkcalllog CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'yealink'@'localhost' IDENTIFIED BY 'strongpassword';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX ON yealinkcalllog.* TO 'yealink'@'localhost';
FLUSH PRIVILEGES;
```

The tables (`schema_migrations`, `yealink_events`, `yealink_calls`) are created automatically on the first request.

### 3. Set environment variables

| Variable | Default | Description |
|---|---|---|
| `DB_HOST` | `127.0.0.1` | MySQL host |
| `DB_PORT` | `3306` | MySQL port |
| `DB_NAME` | `yealinkcalllog` | Database name |
| `DB_USER` | `root` | Database user |
| `DB_PASS` | *(empty)* | Database password |
| `YEALINK_TOKEN` | *(not set)* | Optional shared secret; if set, every ingest request must include `?token=<value>` |

Example (Apache `SetEnv` in VirtualHost or `.htaccess`):

```apacheconf
SetEnv DB_HOST     127.0.0.1
SetEnv DB_NAME     yealinkcalllog
SetEnv DB_USER     yealink
SetEnv DB_PASS     strongpassword
SetEnv YEALINK_TOKEN mysecrettoken
```

Or via PHP-FPM pool (`www.conf`):

```ini
env[DB_HOST]        = 127.0.0.1
env[DB_NAME]        = yealinkcalllog
env[DB_USER]        = yealink
env[DB_PASS]        = strongpassword
env[YEALINK_TOKEN]  = mysecrettoken
```

---

## Phone configuration

### Quick way – use the `/setup` page

Open **`https://your-server/setup`** in a browser. The page auto-detects your server URL and generates the four Action URLs ready to copy-paste into each phone.

### Manual configuration

In the phone's web interface go to **Features → Action URL** and paste the matching URL:

| Event | Setting name | Auto-provisioning key |
|---|---|---|
| Incoming call (ringing) | Action URL – Incoming call | `features.action_url.incoming_call` |
| Call answered | Action URL – Answer call | `features.action_url.answer_call` |
| Call ended | Action URL – End call | `features.action_url.end_call` |
| Missed call | Action URL – Missed call | `features.action_url.missed_call` |

URL template (replace `your-server` and `mysecrettoken`):

```
http://your-server/yealink/event?event=ringing&ext=$active_user&call_id=$call_id&local=$local_uri&remote=$remote_uri&dl=$display_local&dr=$display_remote&token=mysecrettoken
```

Full set (copy-paste ready, swap `ringing` for `answered` / `ended` / `missed`):

```
http://your-server/yealink/event?event=ringing&ext=$active_user&call_id=$call_id&local=$local_uri&remote=$remote_uri&dl=$display_local&dr=$display_remote&token=mysecrettoken
http://your-server/yealink/event?event=answered&ext=$active_user&call_id=$call_id&local=$local_uri&remote=$remote_uri&dl=$display_local&dr=$display_remote&token=mysecrettoken
http://your-server/yealink/event?event=ended&ext=$active_user&call_id=$call_id&local=$local_uri&remote=$remote_uri&dl=$display_local&dr=$display_remote&token=mysecrettoken
http://your-server/yealink/event?event=missed&ext=$active_user&call_id=$call_id&local=$local_uri&remote=$remote_uri&dl=$display_local&dr=$display_remote&token=mysecrettoken
```

> **Note:** Yealink Action URL maximum length is 511 characters.

---

## Testing with curl

```bash
# Simulate an incoming call (ringing)
curl "http://your-server/yealink/event?event=ringing&ext=101&call_id=abc123&token=mysecrettoken"

# Simulate the call being answered
curl "http://your-server/yealink/event?event=answered&ext=101&call_id=abc123&token=mysecrettoken"

# Simulate the call ending
curl "http://your-server/yealink/event?event=ended&ext=101&call_id=abc123&token=mysecrettoken"

# Simulate a missed call
curl "http://your-server/yealink/event?event=missed&ext=102&call_id=xyz789&token=mysecrettoken"

# View dashboard for today
curl "http://your-server/dashboard"

# View dashboard for a specific date
curl "http://your-server/dashboard?date=2025-06-15"
```

---

## Endpoints

| Method | Path | Description |
|---|---|---|
| `GET` | `/yealink/event` | Receive a Yealink Action URL callback |
| `GET` | `/` or `/dashboard` | HR dashboard (daily summary per extension) |
| `GET` | `/setup` | Phone configuration helper – copy-paste Action URLs |

### `/yealink/event` query parameters

| Parameter | Required | Description |
|---|---|---|
| `event` | ✅ | `ringing`, `answered`, `ended`, or `missed` |
| `ext` | ✅ | Extension / SIP user (`$active_user`) |
| `call_id` | ✅ | Call identifier (`$call_id`) |
| `local` | optional | Local SIP URI |
| `remote` | optional | Remote SIP URI |
| `dl` / `display_local` | optional | Local display name |
| `dr` / `display_remote` | optional | Remote display name |
| `token` | if enabled | Must match `YEALINK_TOKEN` env var |

Returns `200 OK` (plain text) on success, `400` for missing/invalid params, `401` for bad token.
