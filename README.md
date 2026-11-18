# Pixel Studio

Laravel 12 + Vue 3 + Inertia app that generates images through the Pixelforge API, charges users Pixelforge's cost + 25%, and keeps every amount exact.

## Running locally

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed           # demo user: test@example.com / password, $5 credit
composer run dev                     # web server, queue worker, scheduler and Vite

```

Set `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET` and `ADMIN_EMAILS` in `.env`. Webhooks need a public URL (`ngrok http 8000`, Expose, Cloudflare Tunnel) pointed at `/webhooks/pixelforge`. Use one Pixelforge key per environment.

`php artisan test` runs the suite: cost maths checked against the docs' worked examples, and webhook duplication, ordering and signatures.

## How the money works

**Unit.** Every amount is an integer number of **nanodollars** (1 USD = 10⁹). Pixelforge reports costs to nine decimal places, so this is exact. No floats are used anywhere. Rates such as `"12.50"` are parsed from strings. Cost is `Σ tokens × rate`, and with the published rates it always comes out to a whole number of nanodollars. Users are charged to the nanodollar as well, so nothing is rounded to cents. The UI shows 4 decimals and the exact 9-decimal value on hover.

**Markup.** `charge = ceil(provider_cost × 125 / 100)`. With Pixelforge's rates this is always exact. If it ever isn't, it rounds up by at most one nanodollar, so we never charge less than cost + 25%.

**Estimate.** The estimate uses the rates and token tables from `GET /v1/pricing` (cached for 10 minutes; nothing is hardcoded). Two parts are unknown until completion: text tokens, which are `ceil(chars / 4)` ±15%, and reasoning tokens, which fall in a range per quality. The UI shows the expected price, the min–max range and a per-token breakdown. It updates live as the user types, using a TypeScript mirror of `Pricing::estimate()`. The server recomputes the estimate on submit, and that figure is the one stored.

**Ledger** (`credit_transactions`, `App\Services\Billing\CreditLedger`):

| step | entry |
|---|---|
| submit | `hold` −(max estimate). An atomic `UPDATE … WHERE balance >= hold`, so concurrent submits can't overspend |
| succeeded | `hold_release` +hold, then `charge` −(exact cost + 25%) |
| failed / rejected | `hold_release` +hold. Failed generations are free |

`UNIQUE(generation_id, type)` means the database itself guarantees a generation is held, released and charged at most once. Inserts use `INSERT … ON CONFLICT DO NOTHING`, and the balance only moves when a row was actually inserted.

## Robustness

* **Submitting** happens in a queued job (`SubmitGeneration`) with `Idempotency-Key = generation uuid`, so a retry after a timeout never creates or bills a second image.
  * `429` and `503 overloaded`: the job waits `max(Retry-After, 2^attempt)` (capped at 30s) and retries, up to 25 attempts.
  * `400`, `422`, `401` and `daily_limit_exceeded`: the generation fails immediately, for free.
  * Users see what's happening ("Waiting for a free slot at Pixelforge").
* **Webhooks** (`POST /webhooks/pixelforge`, outside the web/CSRF middleware):
  * The HMAC-SHA256 is checked over the raw body with `hash_equals`, and timestamps older than 5 minutes are rejected.
  * Each `evt_` id is stored once (unique index), so a repeat delivery is acknowledged and ignored.
  * Storing the event and applying it share one transaction. If applying fails we return 500 and Pixelforge's retry starts clean.
* **State machine** (`GenerationSync`): the one place a generation changes state. It works from the generation object's `status`, not the event type, and runs under a row lock.
  * Status only moves forward (queued → processing → succeeded|failed). A late `processing` or a contradictory event after a final state is ignored.
  * A webhook that arrives before our POST returns is matched through `metadata.generation_uuid`.
* **Missed webhooks**: `pixelforge:sync` runs every minute. It polls in-flight generations that haven't been updated for 45s, at most 20 per run, to stay under the 60/min limit.
* **Unknown outcomes**: if Pixelforge never answers, the generation is marked `submission_unconfirmed` and the hold is released. This is the only failure that a later real event from Pixelforge can override. If it turns out the image was made, the user is charged the real cost, so our provider costs still match `/v1/usage`.

## Stretch goals

* **Balances**:
  * New accounts get $1 of credit.
  * `/credits` has the fake "Buy credits" buttons and the full ledger.
  * Submitting is blocked, in both the UI and the server, when the balance can't cover the worst-case cost.
* **Operator report** (`/admin/report`, for emails listed in `ADMIN_EMAILS`):
  * Shows credit spend, provider cost and profit per UTC day and per user.
  * Days are grouped by **completion** day, the way Pixelforge bills.
  * Each day is compared against `GET /v1/usage` and flagged when the cost or generation count differs.

## Deploying (Laravel Cloud)

1. Push this repo to GitHub and create a Laravel Cloud app from it. Attach a database (Postgres or MySQL).
2. Environment variables:
   * `PIXELFORGE_API_KEY`: a key made for production.
   * `PIXELFORGE_WEBHOOK_SECRET`: that key's signing secret.
   * `ADMIN_EMAILS`
   * `QUEUE_CONNECTION=database`
3. Deploy commands: `php artisan migrate --force`. Build commands: `composer install --no-dev`, `npm ci`, `npm run build`.
4. Turn on the **scheduler**, and add a **queue worker** (`php artisan queue:work --tries=26`).
5. In the Pixelforge dashboard, set that key's webhook URL to `https://<your-app>/webhooks/pixelforge` and send a test event.

## Decisions made where the brief was open

* Prices are shown and charged to the nanodollar rather than rounded to cents, because rounding each charge to cents would overcharge cheap generations by a lot.
* The hold is the maximum possible cost, so a user can never go negative.
* The expected estimate uses the text-token estimate and the midpoint of the reasoning range.
* Images aren't copied to our storage. Once Pixelforge's 24-hour URLs expire, the card says so.
* "Try again" on a failed generation submits a new generation with the same settings. It is priced and held again.
