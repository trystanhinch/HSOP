# Deploy HSOP M3 to Live

## 1. Build frontend
```powershell
cd frontend
npm run build
```

## 2. Create deploy package
```powershell
cd ..
.\scripts\deploy-live.ps1
```
This creates `deploy/hsop-m3-backend.zip` and copies `frontend/dist/` to `deploy/hsop-frontend-dist/`.

## 3. Upload via cPanel / FTP
- **Backend:** Upload zip to server, extract over `adminhsop.alphaarraytechnologies.com` document root (keep existing `.env` on server).
- **Frontend:** Upload contents of `deploy/hsop-frontend-dist/` to `hsop.alphaarraytechnologies.com` document root.

## 4. On server (SSH or cPanel terminal)
```bash
cd /path/to/backend
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
```

## 5. Run migrations via deploy URL (replace SECRET with server DEPLOY_SECRET)
```
https://adminhsop.alphaarraytechnologies.com/deploy/migrate/SECRET
https://adminhsop.alphaarraytechnologies.com/deploy/seed-settings/SECRET
```

## 6. Verify M3 endpoints (login as admin first)
- `GET /api/sms-logs` → 200
- `GET /api/jobs/search?q=test` → 200
- `GET /api/email-logs` → 200

## 7. Add real credentials to server `.env`
```
TWILIO_SID=...
TWILIO_AUTH_TOKEN=...
TWILIO_FROM_NUMBER=+1...
SMS_ENABLED=true

MAIL_MAILER=smtp
MAIL_HOST=...
...
```

Then enable in Settings → Notifications → SMS/Email globally.
