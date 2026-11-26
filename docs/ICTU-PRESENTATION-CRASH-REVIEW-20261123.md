# ICTU presentation crash reviewer — 23 November 2026, 8:00 AM

For the three-member SPMU-ACPMP team. Start with the opening, five essentials and your assigned role. Use the questions for practice; explain the ideas in your own words.

This reviewer is based on a read-only inspection of the current project and local runtime at commit `c720717`. No application code, environment configuration, database, accounts, mail settings or containers were changed for this preparation. No new full regression, penetration test, load test or restore rehearsal was run. Earlier test records are historical evidence, not a fresh deployment certification.

## 1. Honest opening

> Good morning po. This is SPMU-ACPMP, our system for inventory, borrowing, custody, returns and accountability. Malaking bahagi ng implementation ay na-generate gamit ang AI tools. We are still developing our technical understanding, and we will identify honestly the parts we can explain and those that need further verification. We are presenting the current implementation for ICTU's assessment before any production deployment.

If asked what you personally contributed, name only work you actually performed: requirements gathering, workflow decisions, client coordination, UI review, testing or documentation. Do not claim to have personally written, reviewed or tested code just because an AI did it.

If asked whether you wrote all the code yourself:

> No po. AI generated much of the code. We can show the implementation and the parts we have studied, but we will not claim independent coding expertise we do not yet have.

If you cannot answer:

> Hindi pa po namin maipapaliwanag nang accurately ang part na iyon. Ire-record po namin ang question, identify the relevant code/configuration, and verify it before making a deployment commitment.

There is no guaranteed reaction or approval. Follow your school's AI-use and disclosure requirements. Honest technical limitations are more useful than invented answers.

## 2. Five things everyone must understand

1. **Purpose:** track school property from request to approval, physical handover, return and any accountability case.
2. **Stack:** Laravel 13 with PHP, Blade/HTML/CSS/JavaScript, Apache and MariaDB in Docker.
3. **Roles:** Borrower, SPMU Action Officer, SPMU Admin/Head. ICTU is the proposed technical custodian, not an active application login role.
4. **Flow:** browser request → route → middleware checks → controller/service → database/files → response. The browser does not directly edit MariaDB.
5. **Inventory:** submission does not reserve stock. Final approval reserves it. Physical release moves it to custody. Return inspection determines whether it becomes available or remains held for laundry/repair/accountability.

## 3. Divide the three members

| Member | Main topic | Must be able to explain |
|---|---|---|
| 1 — workflow/demo | What the office and users do | The three roles, on-campus versus off-campus approval, stock availability, release/return and a short demonstration. |
| 2 — backend/data | How a click is processed | Route, middleware, controller, service, model/database; Login and approval flows; validation, password hashing and transactions. |
| 3 — deployment/operations | How the system runs and recovers | Four Docker services, safe diagnostic commands, configuration/secrets, private files, backups and known deployment gaps. |

Everyone learns section 2. Each member then explains their topic to the others without reading. Hand off detailed questions to the member who studied that part; do not pretend the other members' knowledge is your own.

## 4. Your actual architecture

**Current running local URL:** http://localhost:8080/ — the previous port 8081 is no longer the current setup.

| Layer | Actual project implementation | Simple explanation |
|---|---|---|
| Browser UI | Blade templates in `resources/views`, CSS in `public/css`, JavaScript | Forms and screens the user sees. A React/Vue application was not established by this review. |
| Web application | Laravel **13.35.0**, running PHP **8.4.28** | Receives requests and applies application rules. The PHP version is the observed current container version. |
| Web server | Apache, document root `/var/www/html/public` | Serves the app; the whole project directory should not be publicly served. |
| Database | MariaDB **11.4** image, Laravel `mysql` connection | Persistent relational records. SQLite is also supported and is used in the isolated test setup; it is not the current Docker application database. |
| Background services | `scheduler` and `queue` | Scheduler runs configured timed commands. Queue worker processes database-queued jobs when jobs are dispatched. Do not claim all emails use a queue: the reviewed mail code also calls `Mail::html` directly. |
| Files | Private local disk under `storage/app/private` | Uploads, evidence and generated documents; access goes through application authorization. |
| Dependencies | Composer for PHP packages; npm/Vite asset tooling listed in `package.json` | Tools used to install/build dependencies; npm is not a separate Node API server in the reviewed running stack. |

The four Compose services are `app`, `database`, `scheduler`, and `queue`. Database and application-storage named volumes preserve data across ordinary container replacement. A persistent volume is not an independent backup.

## 5. Two flows you should practice explaining

### Login

`POST /login` → `routes/web.php` → `Auth/LoginController::store` → input validation → user lookup and password hash check → active-account/authentication checks → email/registration/portal checks → session regeneration → dashboard.

The actual code uses Laravel's `Hash::check`, `Auth::attempt`, and `EstablishesAuthenticatedSession`. An unverified account is redirected to email verification rather than receiving full application access. Login attempts are throttled in the route definition.

### Borrowing and approval

Borrower prepares a request and required signed supporting documents → submission → **on-campus: Head review; off-campus: Action Officer verification followed by Head decision** → final stock availability check and allocation → approved operational documents → Action Officer schedules/prepares and records physical release → return/inspection → laundry/accountability where applicable.

`BorrowingRequestController` handles request actions. `RequestWorkflowService::submit`, `verifyByActionOfficer`, and `decide` implement workflow rules. Approval calls `InventoryService::allocate`; the code uses database transactions and row locks. The office's actual release and return are separate from clicking Approve.

Verification is not final approval. A draft or submitted request does not promise that the item is reserved. GSU/VPAF are physical signatories where required, not active application approver accounts.

## 6. Files to locate quickly

| File/folder | What to point to |
|---|---|
| `routes/web.php` | URL endpoints, login throttling and protected route groups. |
| `bootstrap/app.php` | Middleware registration and friendly oversized-upload handling. |
| `app/Http/Controllers/Auth/LoginController.php` | Login validation and authentication. |
| `app/Http/Controllers/Auth/Concerns/EstablishesAuthenticatedSession.php` | Session setup/regeneration and access checks. |
| `app/Models/User.php` | User model; password has a `hashed` cast. |
| `app/Services/RequestWorkflowService.php` | Submit, verification, approval and workflow state changes. |
| `app/Services/InventoryService.php` | Availability, reservation/allocation, stock corrections and locking. |
| `app/Services/AuditService.php` | Actor, action, reason and before/after snapshots. |
| `app/Services/ProtectedFileService.php` and `app/Http/Controllers/DocumentController.php` | Private file writes and authorized document access. |
| `database/migrations` | Database structure and versioned schema changes. |
| `resources/views` | Blade UI templates. |
| `docker-compose.yml` and `Dockerfile` | Services, volumes, web/PHP runtime and installed document tools. |
| `routes/console.php` | `spmu:process-deadlines`, scheduled every minute. |
| `tests` and `docker-compose.test.yml` | Automated tests and isolated test configuration. |

Database vocabulary: `users`, `inventory_items`, `borrowing_requests`, `request_versions`, `request_items`, `allocations`, `custody_transactions`/`custody_lines`, `return_transactions`/`return_lines`, `stored_files`, `generated_documents`, `audit_events`.

One request can have versions; each version can have multiple requested item rows. An item row references an inventory item. Approval allocation, physical custody and return records represent different stages, not interchangeable statuses.

## 7. Likely ICTU questions: short, defensible answers

| Question | Answer to understand, then say naturally |
|---|---|
| Why Laravel? | It provides routing, validation, database access, authentication/session facilities and migrations. We chose to build on those facilities; using a framework still requires reviewing our own application code. |
| What is MVC? | A Model represents records/relationships, a View renders the screen, and a Controller handles the request. Business rules are also organized into service classes in this project. |
| What prevents a borrower from opening Admin functions? | Protected routes require authentication and account checks; middleware checks the workspace, and sensitive handlers also check access classification/record ownership. Hiding a button is only the UI layer. |
| How are passwords stored? | The current runtime uses bcrypt hashing. `User.php` casts passwords as `hashed`; the login handler verifies the submitted password against the stored hash. We do not retrieve a user's original password. [Laravel hashing](https://laravel.com/framework/docs/hashing). |
| Authentication versus authorization? | Authentication identifies who is signed in. Authorization decides which actions and records that user may access. |
| How do you validate input? | Laravel server-side validation checks required values, allowed values, references and upload rules. Browser validation helps the user; the server still has to validate. |
| How do you address SQL injection and XSS? | Reviewed paths use Eloquent/query-builder bindings and Blade escaping. Raw SQL/HTML paths still require review; we cannot claim every path is vulnerability-free. |
| CSRF? | Laravel's web request stack and form tokens protect applicable state-changing requests. It is separate from role/record authorization. |
| Can two users approve the last available item? | The code rechecks stock during approval and uses transactions plus row locks in allocation. This is the intended concurrency protection; a production concurrency/load test has not been demonstrated today. |
| What is a transaction? | Related database changes commit together or roll back together on failure, so a partially completed business operation is avoided. External effects such as email need their own failure handling. |
| Do all stock numbers mean the same thing? | No. Total stock is the recorded quantity; Reserved, On Custody and held/unavailable units explain how much is actually free for borrowing. Approval and physical release are different movements. |
| Where are files stored? | Private storage, with metadata in `stored_files`; protected documents are served by authorized controllers. A SHA-256 file hash is recorded for integrity. That alone does not establish encryption at rest. |
| Are signatures automatically pasted? | Reviewed signing paths require explicit consent and capture a signature snapshot. Reports use prepared/noted names and blank manual-signing lines. We should demonstrate the specific action rather than claim a certificate-based digital-signature system. |
| How is OTP handled? | The implementation uses a six-digit, hashed verification code, 10-minute validity, an attempt limit and resend controls. Mailbox verification does not by itself grant a role or approve a registration. Actual email delivery still needs separate verification. |
| Is email working now? | SMTP is selected and a password is configured, but we did not send a delivery test in this review. Earlier OTP delivery was blocked and its resolution is not confirmed. We will disclose this dependency rather than bypass verification. |
| What runs automatically? | The configured scheduler invokes `spmu:process-deadlines` every minute for implemented deadline processing. Someone must monitor the scheduler/worker; a page merely opening does not prove timed work is healthy. |
| What happens if the server fails? | Recovery needs a database backup, matching private storage, protected application-key/configuration handling and a tested restoration procedure. Persistent Docker volumes alone do not cover host failure. |
| Are backups automatic? | The supplied Compose stack has no automatic backup service/destination. The manual backup/restore runbook exists; schedule, retention, ownership and restore rehearsal still need agreement. |
| Who will maintain it? | We need an agreed handover and named maintainer. The proposed arrangement is SPMU for operational ownership and ICTU for technical custody, subject to ICTU accepting the responsibilities. We cannot assume ICTU support without agreement. |
| Have all tests passed? | There are backend/frontend tests and earlier focused results in the tracker. This preparation did not run a fresh full regression, penetration test or load test. We will present evidence by revision/scope rather than say everything is perfect. |
| Can it be deployed immediately? | We are asking ICTU to assess readiness. The current laptop profile is local/demo, and production configuration, email, backups, recovery and ownership still need acceptance. We can request a controlled pilot after the agreed prerequisites. |

## 8. Current facts and deployment gaps to disclose

Read-only checks during this preparation found:

- App and database containers report healthy; scheduler and queue containers are running. `GET /up` and `GET /login` returned HTTP 200 on port 8080. This verifies basic service availability, not every business function.
- Runtime: Laravel 13.35.0 / PHP 8.4.28; database driver `mysql`; password hashing `bcrypt`; session and queue drivers `database`; timezone `Asia/Manila`.
- **Local profile:** `APP_ENV=local`, `APP_DEBUG=true`, HTTP URL, secure-cookie setting false, demo seeding flag true and startup-migrations flag true. These are not a production sign-off. Do not change them hurriedly before the demo.
- A production deployment needs the approved HTTPS origin, production/debug-off configuration, appropriate secure cookies/proxy configuration, approved accounts and demo-user removal, controlled migrations, secrets management, private storage and accepted operating responsibilities. Laravel explicitly requires debug mode to be off in production: [deployment documentation](https://laravel.com/framework/docs/deployment).
- The backup runbook lists frequency/retention, destination, encryption/key ownership, recovery targets and restore-rehearsal cadence as decisions still to be finalized. A written procedure is not evidence that a restore succeeded.
- SMTP credentials being present does not prove successful OTP delivery. No mail was sent in this review.
- Environment files are excluded by current ignore rules. A complete repository-history secret scan and a full security assessment were not performed here.

## 9. Basic commands: practice the read-only ones

Open PowerShell and go to the correct project:

```powershell
Set-Location 'C:\Users\Admin\capstone-spmu-acpmp'
```

| Command | Meaning |
|---|---|
| `docker compose ps` | Shows this project's running services/status. |
| `docker compose exec app php artisan --version` | Runs Laravel's version command inside the app container. |
| `docker compose exec app php artisan route:list --path=login` | Lists the login endpoints. It does not sign anyone in. |
| `docker compose exec app php artisan migrate:status` | Shows migration status. It does not apply migrations. |
| `git status --short` | Shows working-copy changes; it does not save or commit them. |

Vocabulary: `docker compose` manages the multi-service stack; `exec` runs a command inside a running service; `php artisan` is Laravel's command-line interface; a migration is a versioned database-schema change.

For a start/install question, the existing README documents `docker compose up -d --build`. Explain that it builds/starts the services. **Do not run it just for practice before 8:00 AM:** the app is already running and the current entrypoint can apply migrations/bootstrap when configured to do so. Updates/startup belong to a controlled operating procedure.

Do not improvise resets: `migrate:fresh` rebuilds tables, `db:seed` can alter reference/demo records, and `docker compose down -v` removes the named data volumes ([Docker reference](https://docs.docker.com/reference/cli/docker/compose/down/)). Do not regenerate `APP_KEY`, edit secrets, run an unverified database import or display the environment file on a projector. Backups/restores should follow the ICTU runbook with an isolated rehearsal.

## 10. Rehearsal plan before 8:00 AM

Suggested schedule from the 6:05 AM starting point; reserve more travel time if your situation requires it.

| Time | Activity |
|---|---|
| 6:05–6:20 | All three: honest opening, five essentials, local URL and known limitations. |
| 6:20–6:40 | Study assigned roles; each member teaches their topic to the other two. |
| 6:40–7:00 | Rehearse the existing demo: inventory → request/documents → verification/decision → release/return → audit/report. Use approved demonstration records and existing accounts. |
| 7:00–7:15 | Ask each other the questions above without reading answers. Mark unknowns for disclosure. |
| 7:15–7:30 | Check laptop/charger/display, the current local URL and a short fallback recording/screenshots. Stop changing code/configuration. |
| 7:30–8:00 | Travel/setup buffer. Review only the opening and each member's main points. |

For the demonstration, show a small, reliable flow you have actually rehearsed. If a new registration depends on unverified email delivery, disclose that and demonstrate using an existing authorized demo account; do not disable verification to force the flow. Never manipulate live office records merely to make the presentation look successful.

If the team still cannot explain basic request flow, permissions or recovery, say so and seek a supervised review/pilot plan. Do not promise unsupervised production support or a deployment date you cannot substantiate.

## Official references and existing project runbooks

- [Laravel authentication](https://laravel.com/framework/docs/13.x/authentication)
- [Laravel password hashing](https://laravel.com/framework/docs/hashing)
- [Laravel production deployment](https://laravel.com/framework/docs/deployment)
- [Docker Compose down options, including volume removal](https://docs.docker.com/reference/cli/docker/compose/down/)
- [Project README](../README.md)
- [ICTU deployment/operations](DEPLOYMENT-ICTU.md)
- [Backup and restore runbook](BACKUP-RESTORE-RUNBOOK.md)
- [Progress tracker and historical verification](UI-ADMIN-REVISION-TRACKER.md)
