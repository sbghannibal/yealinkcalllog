# yealinkcalllog

Minimal PHP + MySQL service that receives Yealink phone events via **Action URL**, stores them in MySQL, and shows a per-extension dashboard with authenticated access.

---

## Features

- **Auto-migrate** – tables are created on the very first request; no manual SQL needed.
- **Session-based auth** – admin and per-extension user accounts; all pages require login.
- **`GET /yealink/event`** – public ingest endpoint for Yealink Action URL callbacks.
- **`GET /dashboard`** – daily HR dashboard (received / answered / missed / answer-rate %).
- **`GET /extension`** – per-extension rolling statistics (today / 7d / 1m / 3m / 6m / 12m).
- **`GET /calls`** – paginated call list with date-range and quick-filter buttons.
- **`GET /setup`** – phone configuration helper: generates ready-to-copy Action URLs.
- Hard **12-month lookback** cap enforced in all queries.
- Optional shared-secret protection via `YEALINK_TOKEN` env var.

---

## Project structure

```
public/
  index.php      ← web root entry point (point your web server here)
  .htaccess      ← Apache rewrite rules
src/
  Auth.php       ← session management, login/logout, access guards
  Config.php     ← reads env vars + .env file loader
  Db.php         ← PDO MySQL connection
  Migrations.php ← auto schema migration
  Ingest.php     ← /yealink/event handler
  Report.php     ← /dashboard, /extension, /calls handlers
  Setup.php      ← /setup phone-config helper
.env.example     ← copy to .env and fill in your values
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

The tables are created automatically on the first request.

### 3. Configure environment variables

The easiest way is a **`.env` file** in the project root (the directory that contains `public/` and `src/`):

#### Step-by-step

1. Copy the example file:
   ```bash
   cp .env.example .env
   ```

2. Open `.env` in a text editor and fill in your values:
   ```dotenv
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=yealinkcalllog
   DB_USER=yealink
   DB_PASS=strongpassword

   # Optional: if set, every /yealink/event request must include ?token=<value>
   YEALINK_TOKEN=mysecrettoken

   # Optional but recommended: makes PHP session IDs harder to guess
   # Generate with: php -r "echo bin2hex(random_bytes(32));"
   SESSION_SECRET=
   ```

3. **Protect the `.env` file** – make sure it is not publicly accessible.  
   For Apache the included `.htaccess` only routes PHP requests; the `.env` file lives outside `public/` so it is never served directly.

> **Alternative**: set the same variables via Apache `SetEnv` in the VirtualHost or via the PHP-FPM pool config (`env[DB_HOST] = ...`). Variables set this way take priority over the `.env` file.

| Variable | Default | Description |
|---|---|---|
| `DB_HOST` | `127.0.0.1` | MySQL host |
| `DB_PORT` | `3306` | MySQL port |
| `DB_NAME` | `yealinkcalllog` | Database name |
| `DB_USER` | `root` | Database user |
| `DB_PASS` | *(empty)* | Database password |
| `YEALINK_TOKEN` | *(not set)* | Optional shared secret for the ingest endpoint |
| `SESSION_SECRET` | *(not set)* | Optional extra entropy for PHP sessions |

---

## First-run: create the admin account

On a fresh install (no admin exists yet) visit:

```
https://your-server/init
```

Fill in a username and password (minimum 8 characters) and click **Create Admin Account**.  
You will be redirected to the login page.

> `/init` is automatically disabled as soon as the first admin account is created.

---

## Login

Visit any protected page (e.g. `/dashboard`) – you will be redirected to:

```
https://your-server/login
```

Log in with the credentials you created at `/init`.

---

## User management

Admins can create additional accounts at:

```
https://your-server/admin/users
```

Two roles are available:

| Role | Access |
|---|---|
| **admin** | Full access – all extensions, all data, user management |
| **user** | Restricted to a single extension (set at account creation) |

---

## Phone configuration

### Quick way – use the `/setup` page

Open **`https://your-server/setup`** in a browser (requires login). The page auto-detects your server URL and generates the four Action URLs ready to copy-paste into each phone.

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
```

---

## Endpoints

| Method | Path | Auth required | Description |
|---|---|---|---|
| `GET` | `/yealink/event` | No (token optional) | Receive a Yealink Action URL callback |
| `GET` / `POST` | `/login` | No | Login form |
| `GET` | `/logout` | No | Clear session and redirect to /login |
| `GET` / `POST` | `/init` | No (disabled after first admin) | Create first admin account |
| `GET` | `/` or `/dashboard` | ✅ | Daily summary per extension |
| `GET` | `/extension?ext=…` | ✅ | Rolling stats for one extension |
| `GET` | `/calls` | ✅ | Paginated call list with filters |
| `GET` | `/setup` | ✅ | Phone configuration helper |
| `GET` / `POST` | `/admin/users` | ✅ Admin only | Create and list user accounts |

### `/yealink/event` query parameters

| Parameter | Required | Description |
|---|---|---|
| `event` | ✅ | `ringing`, `answered`, `ended`, `missed`, or `outgoing` |
| `ext` | ✅ | Extension / SIP user (`$active_user`) |
| `call_id` | ✅ | Call identifier (`$call_id`) |
| `local` | optional | Local SIP URI |
| `remote` | optional | Remote SIP URI |
| `dl` / `display_local` | optional | Local display name |
| `dr` / `display_remote` | optional | Remote display name |
| `token` | if enabled | Must match `YEALINK_TOKEN` env var |

Returns `200 OK` (plain text) on success, `400` for missing/invalid params, `401` for bad token.

