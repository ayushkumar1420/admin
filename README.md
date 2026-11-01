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

The public Explore page reads its endpoint from the `data-jobs-api` attribute on `.opportunity-grid`, set to `/career-admin/public-jobs.php` for production. On `localhost` or `127.0.0.1`, it uses `http://localhost:8080/career%20admin/public-jobs.php` so Explore can reach the PHP server while a static server serves the website on any local port. Local API CORS permits localhost and 127.0.0.1 origins on any port. Remember that `localhost` means the computer running the browser: a page opened on another laptop cannot reach your laptop's `localhost:8080`. For testing across computers, deploy both projects to the same testing domain or configure a reachable API host explicitly. For production, map this folder to `/career-admin/` on the same origin as the website.

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

The applicant-facing Careers form is maintained outside this PHP project; its UI is unchanged here. That frontend must call the OTP endpoints below, collect the code, and include the returned token when it submits the existing application form.

The API accepts `POST` only. It accepts the following fields:

Before submitting, the Careers form must call these two JSON APIs in order:

1. `POST api/send-email-otp.php` as form data with `email`. On success, the response contains a `challenge_id`; the candidate receives a six-digit code by email.
2. `POST api/verify-email-otp.php` as form data with `email`, `challenge_id`, and `otp`. On success, the response contains a `verification_token`.
3. Include that `verification_token` with the multipart application submission below. It is bound to the verified email, expires after 30 minutes, and can be used once.

OTP codes expire after five minutes, allow five incorrect attempts, and can be resent once per email every 60 seconds. Requests are also limited to five per email and ten per client IP per hour. Verification state is stored in protected `private/email-otps.json`; only password hashes of codes and verification tokens are stored. On the local PHP development server with `CAREER_EMAIL_MODE=log`, the send response includes `development_code` for testing only; this field is never included in production responses. Production sends use PHP `mail()` and require the hosting account to support it.

| Field | Required | Validation |
|---|---:|---|
| `job_id` | Yes | Must match a currently Published job ID |
| `full_name` | Yes | Up to 120 characters |
| `email` | Yes | Valid email address, up to 254 characters |
| `verification_token` | Yes | Single-use token from successful email OTP verification |
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
curl.exe -i -X POST "http://localhost:8080/career%20admin/api/send-email-otp.php" `
  -F "email=applicant@example.com"

curl.exe -i -X POST "http://localhost:8080/career%20admin/api/verify-email-otp.php" `
  -F "email=applicant@example.com" `
  -F "challenge_id=CHALLENGE_ID_FROM_SEND_RESPONSE" `
  -F "otp=123456"

curl.exe -i -X POST "http://localhost:8080/career%20admin/api/submit-application.php" `
  -F "job_id=demo-web-development-intern" `
  -F "full_name=Test Applicant" `
  -F "email=applicant@example.com" `
  -F "verification_token=TOKEN_RETURNED_BY_VERIFY_EMAIL_OTP" `
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
  "candidate_confirmation_status": "Pending",
  "message": "Your application has been received."
}
```

The response includes separate HR and candidate notification statuses. The application is stored before notification attempts; a mail failure does not erase the application. Duplicate applications using the same email address for the same job are rejected with HTTP `409`. Invalid fields or a missing/invalid/used verification token return HTTP `422`; unavailable/unpublished job IDs are rejected. The response never includes applicant records, private file paths, or mail credentials. The only job fields recorded with the application are its stable ID and title. Resumes use generated names and are not stored in JSON.

## Application storage and administrator review

- Applicant records: `data/applications.json`, protected by `data/.htaccess` and the local router. PHP uses a separate lock and atomic JSON replacement for reads and writes.
- Resumes: by default, `private/application-resumes/`, protected by `private/.htaccess`. To keep files outside the website document root on Hostinger, set `CAREER_PRIVATE_STORAGE_DIR` to an absolute, PHP-writable directory outside `public_html`. The configured directory must not be web-accessible.
- The authenticated admin has an **Applications** section to view records, review details, download resumes, set status to `New`, `Under Review`, `Shortlisted`, or `Rejected`, and retry failed HR notifications. Resume downloads require the existing admin session and look up the file by application ID; public users cannot download files. HR email notifications include the candidate details and attach the validated PDF resume; the attachment link/download in admin remains protected by the admin session.
- The resume endpoint is `admin-resume.php`; direct access requires a logged-in admin session. Do not remove the deny rules from `data/.htaccess` or `private/.htaccess`.
- Ensure PHP can write `data/`, `private/`, and the configured resume directory. Keep backups encrypted and access-restricted; application records contain personal information.

## HR email configuration

The recipient is configured server-side through `CAREER_HR_EMAIL`; if it is unset, the admin email in `private/config.php` is used. Candidate confirmations are sent to the verified email address. HR notifications use PHP's built-in `mail()` function, without SMTP credentials or a mail library. This only works if the web host has enabled and configured PHP mail delivery; confirm any sending limits or fees with the host. A `true` return means the server accepted the message for delivery, not that it reached the inbox.

```text
CAREER_HR_EMAIL=your-hr@example.com
CAREER_MAIL_FROM_ADDRESS=careers@your-domain.example
CAREER_MAIL_FROM_NAME=DeepshiTech Careers
```

Set the From address to an address on your own domain if the host requires it; omit it if the host supplies a default sender. HR email status and candidate confirmation status are recorded separately. A failed send leaves the application saved, marks that email status `Failed`, logs a server-side error, and failed HR notifications can be retried from the admin detail view.

For local development only, `CAREER_EMAIL_MODE=log` records a no-delivery attempt in the protected `private/email-attempts.jsonl` and leaves the notification `Pending`. This mode is accepted only when PHP runs via its built-in development server on `localhost` or `127.0.0.1`; it never reports a logged attempt as an email sent.

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

1. Request an OTP using `api/send-email-otp.php`; expect a `challenge_id`. In local log mode, use the response's `development_code`. Confirm a second send within 60 seconds gets HTTP `429`.
2. Verify the OTP using `api/verify-email-otp.php`; expect a verification token. Try an incorrect code five times, an expired code, and a token for a different email; none may produce a usable token.
3. Submit a published job application with the verification token and a real PDF. Expect HTTP `201`, a saved application and private resume, plus separate HR and candidate email statuses. Local log mode must leave both statuses `Pending` and deliver nothing.
4. Re-submit for the same job and email with a new verified token; expect HTTP `409`. Reuse the consumed token; expect HTTP `422`.
5. Submit a made-up job ID or an ID for a job changed to `Draft`; expect HTTP `422` and no application record. Omit required fields or use invalid email/phone/year/URL values; expect HTTP `422`.
6. Upload a non-PDF renamed to `.pdf`, a malformed PDF without a `%PDF-` signature/`%%EOF`, or a PDF larger than 5 MB; expect rejection. PHP must allow requests up to at least 6 MB (`post_max_size`) and files up to 5 MB (`upload_max_filesize`) for the app's own size checks to run.
7. Inspect `data/applications.json` locally and confirm a generated resume name and notification statuses are recorded; do not request the JSON through HTTP. Confirm the file is stored in `private/application-resumes/` (or your configured private directory).
8. Request `http://localhost:8080/career%20admin/admin-resume.php?id=APP-...` without logging in; expect HTTP `401`. Log in to the admin, open the application, and verify the resume download is authorized.
9. Stop the built-in server with `Ctrl+C`, run `Remove-Item Env:CAREER_EMAIL_MODE` in that terminal, and restart the server to exercise PHP `mail()`. Check that the host accepts both the HR message with its PDF attachment and the candidate confirmation. Mail failure must leave the application persisted and the relevant notification marked `Failed`.

On Windows PowerShell, use `curl.exe` (not the `curl` alias) for multipart examples above. For an oversized-file test, upload a local file larger than 5 MB; do not alter production PHP upload limits to bypass the application limit.

## Hostinger deployment notes

Deploy this project at `/career-admin/` on the same origin as the Careers frontend so the frontend can reach the production endpoints. Confirm the hosting account uses PHP 8+, supports PHP `mail()`, allows outbound application email, and accepts messages with attachments; check its quotas and limits with the host. Apache `.htaccess` rules must be enabled, and `data/.htaccess` and `private/.htaccess` must deny direct requests. Set writable permissions narrowly for `data/`, `private/`, and the private resume directory; do not make those directories publicly readable. Prefer a `CAREER_PRIVATE_STORAGE_DIR` outside `public_html`. Set the optional mail From address in the server-side PHP environment, require HTTPS, and test the full OTP/application/notification flow on the deployed host before launch. The PHP built-in local-development email mode must not be deployed as a production mail service.
