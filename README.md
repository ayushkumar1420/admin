# DeepshiTech Career Admin (PHP)

This is a standalone PHP career-management panel created outside the main `DeepshiTechW1` website folder. Its layout follows the provided Blog Admin screenshot: dark workspace sidebar, white top bar, light dashboard cards, gold actions, and a compact sign-in screen. The existing Explore page is updated only to load published jobs; the blog admin and unrelated website files are untouched.

## What it does

- Login with a server-side password hash and PHP session.
- Limit login attempts to five per client address in a 15-minute window.
- Create, edit, publish, unpublish, and delete job listings.
- Show total, published, and draft counts.
- Save job data in `data/jobs.json` inside this folder.
- Return published jobs from `public-jobs.php`; drafts and application details are not returned.
- Keep job applications in the separate protected `data/applications.json` store; the legacy per-job `applications` arrays remain empty.
- Seed the three existing Explore Career demo roles in the JSON file once, with stable IDs.
- Load published jobs into the existing Explore Career card design without changing its theme.

The three current demo roles are already present once in `data/jobs.json`. There is no recurring seed step, so rerunning admin setup will not create duplicates. The Explore Career page fetches `public-jobs.php`; it keeps its existing demo cards as a graceful fallback if the service cannot be reached, and displays a styled empty state if the API successfully returns no published jobs.

## Configure the first admin

Requirements: PHP 8.0+ with sessions enabled and permission for PHP to write this folder's `data/` and `private/` directories.

Run setup once from a terminal in this folder:

```powershell
$env:CAREER_ADMIN_EMAIL = "your-admin@example.com"
$env:CAREER_ADMIN_PASSWORD = "use-a-unique-password-at-least-12-characters"
php setup.php
Remove-Item Env:CAREER_ADMIN_EMAIL
Remove-Item Env:CAREER_ADMIN_PASSWORD
```

The setup script hashes the password with PHP `password_hash()`, then stores only the hash and admin email in `private/config.php`. The setup script rejects web requests. Do not copy the password into frontend files. Change credentials by rerunning the setup command with new environment values.

Replace the temporary demo credentials before production by rerunning the setup command with a unique email and password.

## Run locally

From a VS Code terminal opened in this `career admin` folder, run PHP's built-in server on port `8080` (port `5500` is left available for a separate static-site server). The application endpoint requires PHP's `fileinfo` extension to validate uploaded resume MIME types:

```powershell
$phpDir = Split-Path (Get-Command php).Source
$env:CAREER_EMAIL_MODE = "log"
php -d "extension_dir=$phpDir\ext" -d extension=php_fileinfo.dll `
  -d upload_max_filesize=5M -d post_max_size=6M `
  -S localhost:8080 -t .. .\router.php
```

Keep this terminal open. Open the admin at:

`http://localhost:8080/career%20admin/`

For the Explore page, start VS Code Live Server from the website folder and open `http://127.0.0.1:5500/career/explore.html`. The Explore page connects to the PHP feed at `http://localhost:8080/career%20admin/public-jobs.php`; the API allows the local `localhost:5500` and `127.0.0.1:5500` origins. The router denies direct requests for private configuration and JSON storage because PHP's built-in server does not read Apache `.htaccess` files. If either server reports that the port is already in use, stop the other server or choose different ports and update the local API URL in the frontend.

The public Explore page reads its endpoint from the `data-jobs-api` attribute on `.opportunity-grid`, set to `/career-admin/public-jobs.php` for production. On `localhost` or `127.0.0.1`, it uses `http://localhost:8080/career%20admin/public-jobs.php` so Explore can reach the PHP server while Live Server serves the static page from port `5500`. The endpoint explicitly permits only these two local development origins. For production, map this folder to `/career-admin/` on the same origin as the website.

## Job JSON format

Every successful create/edit writes the data file in this structure:

```json
{
  "jobs": [
    {
      "id": "stable-generated-id",
      "title": "Web Development Intern",
      "department": "Technology",
      "job_type": "Internship",
      "work_mode": "Hybrid",
      "location": "India",
      "openings": "",
      "experience": "",
      "short_description": "Short public card text.",
      "description": "Full role description.",
      "responsibilities": "",
      "skills": "",
      "deadline": "",
      "status": "Draft",
      "created_at": "UTC timestamp",
      "updated_at": "UTC timestamp",
      "applications": []
    }
  ]
}
```

Admin create/update form: `index.php`. Public read-only API: `public-jobs.php`, which returns `{ "jobs": [...] }` containing only Published entries. It supports GET and OPTIONS for the two whitelisted local development origins; production should serve the API same-origin. Job text is treated as plain text in the admin UI and is rendered with `textContent` on Explore Career.

## Security and deployment

- Deploy this folder at `/career-admin/` under the same website origin so the public Explore page can reach `public-jobs.php`.
- `private/` and `data/` have Apache `.htaccess` deny rules; `setup.php` is also blocked over HTTP. Configure equivalent access-denial rules on Nginx/IIS if applicable.
- Login attempt counters are stored as hashed client keys in the protected `private/login-attempts.json` file.
- Ensure PHP can write `data/jobs.json` and create `data/jobs.lock`; keep the data file backed up and not downloadable.
- Serve over HTTPS in production; the session cookie is HttpOnly, SameSite=Lax, and Secure when HTTPS is detected.
- The public endpoint returns only published jobs. It does not accept mutations; all writes require an authenticated session and CSRF token.
- This JSON store is for a small, single-host admin. Keep backups. Use a database if you later require concurrent multi-server writes.
- Applications are stored separately from jobs in `data/applications.json`; uploaded resumes are kept in a protected private folder and are never included in public job data.

## Apply for Job API

The Careers website should submit `multipart/form-data` to:

- Local: `http://localhost:8080/career%20admin/api/submit-application.php`
- Production (when this folder is deployed at `/career-admin/`): `https://YOUR-DOMAIN/career-admin/api/submit-application.php`

The API accepts `POST` only. It accepts the following fields:

| Field | Required | Validation |
|---|---:|---|
| `job_id` | Yes | Must match a currently Published job ID |
| `full_name` | Yes | Up to 120 characters |
| `email` | Yes | Valid email address, up to 254 characters |
| `phone` | Yes | Validated phone characters and 7-15 digits |
| `resume` | Yes | PDF, no larger than 5 MB |
| `recruitment_consent` | Yes | Send the string `1` only when checked |
| `location` | No | Up to 120 characters |
| `qualification` | No | Up to 160 characters |
| `college` | No | Up to 180 characters |
| `graduation_year` | No | Four-digit year from 1950 through the current year + 10 |
| `linkedin_url` | No | Valid HTTP(S) URL |
| `portfolio_url` | No | Valid HTTP(S) URL |
| `cover_letter` | No | Up to 5,000 characters |

Example request from a terminal (replace the resume path with a real PDF):

```powershell
curl.exe -i -X POST "http://localhost:8080/career%20admin/api/submit-application.php" `
  -F "job_id=demo-web-development-intern" `
  -F "full_name=Test Applicant" `
  -F "email=applicant@example.com" `
  -F "phone=+1 555 123 4567" `
  -F "location=Remote" `
  -F "qualification=BSc Computer Science" `
  -F "college=Example University" `
  -F "graduation_year=2027" `
  -F "linkedin_url=https://www.linkedin.com/in/example" `
  -F "portfolio_url=https://example.com" `
  -F "cover_letter=I am interested in this role." `
  -F "recruitment_consent=1" `
  -F "resume=@C:\path\to\resume.pdf;type=application/pdf"
```

Success returns HTTP `201` and an application ID, for example:

```json
{
  "success": true,
  "application_id": "APP-20261028-0123456789ABCDEF0123456789ABCDEF",
  "notification_status": "Pending",
  "message": "Your application has been received."
}
```

The response never includes applicant records, private file paths, or mail credentials. Invalid fields return HTTP `422`; unavailable/unpublished job IDs are rejected. The only job fields recorded with the application are its stable ID and title. Resumes use generated names and are not stored in JSON.

## Application storage and administrator review

- Applicant records: `data/applications.json`, protected by `data/.htaccess` and the local router. PHP uses a separate lock and atomic JSON replacement for reads and writes.
- Resumes: by default, `private/application-resumes/`, protected by `private/.htaccess`. To keep files outside the website document root on Hostinger, set `CAREER_PRIVATE_STORAGE_DIR` to an absolute, PHP-writable directory outside `public_html`. The configured directory must not be web-accessible.
- The authenticated admin has an **Applications** section to view records, review details, download resumes, set status to `New`, `Under Review`, `Shortlisted`, or `Rejected`, and retry failed HR notifications. Resume downloads require the existing admin session and look up the file by application ID; public users cannot download files.
- The resume endpoint is `admin-resume.php`; direct access requires a logged-in admin session. Do not remove the deny rules from `data/.htaccess` or `private/.htaccess`.
- Ensure PHP can write `data/`, `private/`, and the configured resume directory. Keep backups encrypted and access-restricted; application records contain personal information.

## HR email configuration

The recipient is configured server-side through `CAREER_HR_EMAIL` and defaults to `ayushmaurya496@gmail.com`. Email is sent by the built-in SMTP client; `mail()` is not used. Configure these environment variables in the Hostinger PHP application/environment settings (never in website JavaScript or public JSON):

```text
CAREER_HR_EMAIL=ayushmaurya496@gmail.com
CAREER_SMTP_HOST=smtp.your-provider.example
CAREER_SMTP_PORT=587
CAREER_SMTP_ENCRYPTION=tls
CAREER_SMTP_USERNAME=your-mailbox@your-domain.example
CAREER_SMTP_PASSWORD=your-mailbox-password
CAREER_SMTP_FROM_EMAIL=your-mailbox@your-domain.example
CAREER_SMTP_FROM_NAME=DeepshiTech Careers
```

Port `587` uses STARTTLS; port `465` can use `CAREER_SMTP_ENCRYPTION=ssl`. Use a mailbox and SMTP host supplied by your mail provider, and make sure the PHP OpenSSL extension is enabled for encrypted SMTP. SMTP delivery is marked `Sent` only after the configured SMTP server accepts the message; this does not guarantee inbox delivery. A failed send leaves the application saved, marks its notification `Failed`, logs only a safe server-side error, and can be retried from the admin detail view.

For local development only, `CAREER_EMAIL_MODE=log` records a no-delivery attempt in the protected `private/email-attempts.jsonl` and leaves the notification `Pending`. This mode is accepted only when PHP runs via its built-in development server on `localhost` or `127.0.0.1`; production PHP/Hostinger will ignore it and require working SMTP settings. It never reports a logged attempt as an email sent.

## Local application testing

Use PHP 8.0+ with sessions and the `fileinfo` extension enabled. The application endpoint fails closed when MIME inspection is unavailable. Keep the existing PHP built-in server/router arrangement; from this project directory run:

```powershell
$env:CAREER_EMAIL_MODE = "log"
$phpDir = Split-Path (Get-Command php).Source
php -d "extension_dir=$phpDir\ext" -d extension=php_fileinfo.dll `
  -d upload_max_filesize=5M -d post_max_size=6M `
  -S localhost:8080 -t .. .\router.php
```

Open the existing admin at `http://localhost:8080/career%20admin/`. If using a separate local static site server, its origin should be `http://localhost:5500` or `http://127.0.0.1:5500`; those two development origins are the only cross-origin origins allowed. The PHP server's router blocks HTTP access to `data/`, `private/`, and `setup.php`, because PHP's built-in server does not read Apache `.htaccess` files.

Test cases:

1. Use a published ID such as `demo-web-development-intern` from `data/jobs.json` and a real PDF; expect HTTP `201`, an application record, a private resume, and notification status `Pending` when local log mode is enabled.
2. Submit a made-up ID or an ID for a job changed to `Draft`; expect HTTP `422` and no application record.
3. Omit name, email, phone, consent, or resume; use an invalid email/phone/year/URL; expect HTTP `422`.
4. Upload a non-PDF renamed to `.pdf`, a malformed PDF without a `%PDF-` signature/`%%EOF`, or a PDF larger than 5 MB; expect rejection. PHP must allow requests up to at least 6 MB (`post_max_size`) and files up to 5 MB (`upload_max_filesize`) for the app's own size checks to run.
5. After a successful submission, inspect `data/applications.json` locally and confirm the record references only a generated resume name; do not request the JSON through HTTP. Confirm the file is in `private/application-resumes/` (or your configured private directory).
6. Request `http://localhost:8080/career%20admin/admin-resume.php?id=APP-...` without logging in; expect HTTP `401`. Log in to the admin, open the application, and use its resume link to test the authorized download.
7. Stop the built-in server with `Ctrl+C`, run `Remove-Item Env:CAREER_EMAIL_MODE` in that terminal, and restart the server to exercise failure handling with SMTP unset. The submission should still be persisted, and notification status should be `Failed`; retrying from admin should remain visibly failed until SMTP is configured.

On Windows PowerShell, use `curl.exe` (not the `curl` alias) for multipart examples above. For an oversized-file test, upload a local file larger than 5 MB; do not alter production PHP upload limits to bypass the application limit.

## Hostinger deployment notes

Deploy this project at `/career-admin/` on the same origin as the Careers frontend so the frontend can call the production endpoint above. Confirm the hosting account uses PHP 8+, Apache `.htaccess` rules are enabled, and `data/.htaccess` and `private/.htaccess` return access denied for direct requests. Set writable permissions narrowly for `data/`, `private/`, and the private resume directory; do not make those directories publicly readable. Prefer a `CAREER_PRIVATE_STORAGE_DIR` outside `public_html`. Configure SMTP environment values in the server-side PHP environment, require HTTPS, and verify one real notification before launch. The PHP built-in local-development email mode must not be deployed as a production mail service.