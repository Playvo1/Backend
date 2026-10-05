# Deploying the staging API to Render

The API runs on Render (Docker, free plan) with a MySQL database on Aiven and emails caught by Mailtrap.
Every merge to `main` redeploys automatically; migrations and `RoleSeeder` run on each start.

Base URL once deployed: `https://<service-name>.onrender.com/api/v1`

## One-time setup

### 1. Database: Aiven for MySQL
1. Sign up at https://aiven.io and create a **MySQL** service on the **Free** plan.
2. From the service **Overview**, note **Host**, **Port** and **Password** (user is `avnadmin`, database `defaultdb`).
3. Download the **CA certificate** (`ca.pem`).

### 2. Email: Mailtrap Sandbox
1. Sign up at https://mailtrap.io → **Email Testing** → **Inboxes** → open (or create) an inbox.
2. Under **SMTP Settings**, note the **Username** and **Password**.
3. Invite teammates to the inbox so they can read OTP / temporary-password emails.

### 3. App key
Run locally and copy the output (starts with `base64:`):
```
php artisan key:generate --show
```

### 4. Render
1. Sign up at https://render.com with GitHub and give it access to `Playvo1/Backend`.
2. **New → Blueprint**, pick the repo; Render reads `render.yaml`.
3. Fill in the secret values it asks for:
   | Key | Value |
   |---|---|
   | `APP_KEY` | output of step 3 |
   | `DB_HOST`, `DB_PORT`, `DB_PASSWORD` | from Aiven |
   | `MAIL_USERNAME`, `MAIL_PASSWORD` | from Mailtrap |
   | `ADMIN_EMAIL`, `ADMIN_PASSWORD` | first admin account (created on start if missing) |
   | `GOOGLE_CLIENT_ID` | Google OAuth client ID (same as local `.env`) |
   | `CORS_ALLOWED_ORIGINS` | web dashboard URL(s), comma-separated |
4. Service → **Environment → Secret Files → Add**: filename `ca.pem`, paste the Aiven certificate.
5. **Manual Deploy → Deploy latest commit**. Check `https://<service>.onrender.com/up` returns 200.

## Things to know
- **Cold starts:** the free plan sleeps after ~15 min without traffic; the first request then takes up to a minute.
- **Uploaded files are not persistent:** payment receipt images are lost on every redeploy/restart. Move them to external storage (e.g. S3) before real use.
- **First admin account:** created from `ADMIN_EMAIL` / `ADMIN_PASSWORD` by `InitialAdminSeeder`. Log in with it and create further admins via `POST /api/v1/admin/admins`. Changing the variables later does not modify an existing account.
- **Logs:** Render dashboard → service → **Logs**.
