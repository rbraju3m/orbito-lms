# RUNNING.md — run it locally and sign in

The short version. Full setup, prerequisites and the reasoning live in the
[repository README](../README.md).

## Before you start

MySQL 8 and Redis must be running, and **ports 8000 and 5173 must be free**.
The frontend pins 5173 (`strictPort`) and will refuse to start if anything
else — another project's dev server — holds it. The API would quietly move to
another port instead, and the SPA would then talk to whatever is on 8000.

## First time only

```bash
cd api
composer install
cp .env.example .env        # set DB_USERNAME / DB_PASSWORD
php artisan key:generate
php artisan migrate         # also creates the permanent platform owner
php artisan db:seed         # plans, plus Demo Academy and demo accounts (local only)

cd ../web
npm install
cp .env.example .env
```

Both seed steps are safe to re-run.

## Beside another local Laravel app — use `orbito.localhost`

Two Laravel SPAs on `localhost` share cookies, and the second one to write
`XSRF-TOKEN` breaks the other's login (*CSRF token mismatch*, below). Give
Orbito its own hostname: browsers send every `*.localhost` name to your own
machine, and cookies never cross hostnames.

```ini
# api/.env
APP_URL=http://orbito.localhost:8000
FRONTEND_URL=http://orbito.localhost:5173      # also the CORS origin
SANCTUM_STATEFUL_DOMAINS=orbito.localhost:5173
SESSION_DOMAIN=null                             # host-only: orbito.localhost and nothing else

# web/.env
VITE_API_URL=http://orbito.localhost:8000
```

Restart `php artisan serve` and `npm run dev`, then **clear the cookies for
`localhost` once**: a cookie Orbito set on `localhost` before this change also
covers `orbito.localhost`, because a domain cookie covers its subdomains.
Then open <http://orbito.localhost:5173/login>.

The test suite pins its own hosts in `api/phpunit.xml`, so it passes
whichever you use. `.env.example` stays on plain `localhost`, which works for
anybody running Orbito alone.

## Every time — three terminals

```bash
cd api && php artisan serve      # API        http://localhost:8000
cd api && php artisan horizon    # queues: mail, certificates, notifications, analytics, webhooks
cd web && npm run dev            # frontend   http://localhost:5173
```

Sign-in works without Horizon; anything queued waits until it runs.

## Sign in

Open **<http://localhost:5173/login>**.

**Platform owner** — the one permanent account, in every environment. The
email is `PLATFORM_OWNER_EMAIL` and the password `PLATFORM_OWNER_PASSWORD` in
`api/.env`; when those are unset, the defaults in `api/config/orbito.php`
(`owner`) apply. The password is written only when the account is first
created, so a password changed since then is the one that works. It runs the
academy registry (`/platform/academies`) and is Super Admin inside every
academy.

**Demo accounts** — local only, all with the password `password`:

| Email | Who |
|---|---|
| `operator@orbito.test` | Platform operator — the academy registry, no academy of its own |
| `owner@orbito.test` | Demo Academy's admin |
| `staff@orbito.test` | Support staff |
| `instructor@orbito.test` | Approved instructor |
| `applicant@orbito.test` | Student with a pending instructor application |
| `student@orbito.test` | Student |

**New sign-ups** need the academy in the link:
`http://localhost:5173/register?academy=demo-academy`. An account belongs to
one academy, so plain `/register` has nowhere to put it.

## Taking a test payment with Stripe

You need a Stripe **sandbox** and the [Stripe CLI](https://docs.stripe.com/cli)
(`stripe login` once). Stripe cannot reach an API on your machine, so the CLI
receives its events and forwards them — nothing is registered in Stripe's
dashboard for this.

1. **Key.** In the sandbox, open **API keys** and copy the secret key
   (`sk_test_…`) — or create a restricted key (`rk_test_…`) that may write
   PaymentIntents and Refunds.
2. **Connect.** Sign in as `owner@orbito.test`, open **Payments**
   (`/admin/payment-gateways`) and choose **Connect** on the Stripe card. Paste
   the key into **Secret key**, leave **Test mode** on, switch on **Take
   payments with this gateway**, and save.
3. **Forward events.** Copy the **Stripe webhook URL** shown on that card — it
   ends in your academy's id — and keep this running in a fourth terminal:

   ```bash
   stripe listen \
     --events checkout.session.completed,checkout.session.async_payment_succeeded,checkout.session.async_payment_failed,checkout.session.expired,refund.created,refund.updated,refund.failed \
     --forward-to <the Stripe webhook URL>
   ```

   It prints `Ready! Your webhook signing secret is whsec_…`. Paste that into
   **Webhook signing secret** on the Stripe card (the key box can stay empty —
   an empty box keeps what is saved). It does not change between runs of
   `stripe listen`, so this is once per machine.
4. **Pay.** Sign in as `student@orbito.test`, buy a priced course, and on the
   order page pick **Stripe** under **Pay with**, then **Pay now**. Stripe's
   hosted Checkout page opens: pay with `4242 4242 4242 4242`, any future
   expiry and any CVC. Stripe sends you back to the order, which turns *paid*
   on its own once `stripe listen` shows `checkout.session.completed`
   forwarded with a `200` — and the course opens for the student.
5. **Refund it in Stripe.** Open the payment in the sandbox's **Payments** list
   and refund it — all of it or part. `stripe listen` forwards `refund.created`
   (and `refund.updated`, if it settles later); the refund appears on the order
   at `/orders/:id` as *Refunded through Stripe*, and a full one takes the
   course away again. The order page's **Refund…** dialog goes the other way —
   through Stripe — and the same events settle it.

## When it does not work

| Symptom | Cause |
|---|---|
| `npm run dev`: port 5173 is already in use | Another dev server holds it. Stop it. |
| Login says *Could not reach the server* | The API is not running — nothing on :8000 |
| Login says **CSRF token mismatch** (419) | A stale `XSRF-TOKEN` cookie from another Laravel app you run on `localhost`. See below |
| Still 419, or 401 straight after signing in, with cookies cleared | The SPA and API disagree about where each other lives — see below |
| Signed in, screens empty, `no_academy_selected` | A platform operator inside no academy. Pick one at `/platform/academies` and enter it |
| Owner account missing | `php artisan orbito:ensure-owner` recreates and repairs it |
| Emails, certificates or notifications never arrive | Horizon is not running |
| Webhook deliveries stay *Pending* | Horizon is not running — every delivery is a queued job |
| After pulling: a 500 naming a missing table or column (`refunds`, `coupon_id`, `webhook_endpoints`…) | New tenant migrations — `cd api && php artisan tenants:migrate` |
| Pay now: *The stripe gateway is not connected for this academy* | No key saved, or **Take payments with this gateway** is off — the Payments screen |
| Paid in Stripe, order still *awaiting payment* | `stripe listen` is not running, or the signing secret saved is not the one it printed |
| `stripe listen` shows `[400]` for every event | The signing secret does not match — or your clock is more than 5 minutes out, because the signature carries a timestamp |
| `stripe listen` shows `[404]` | The URL is not an open academy's. Copy it from the Payments screen again |
| Refunded in Stripe, nothing on the order | `stripe listen` was started without the `refund.*` events — or the report was left for a person (more than the order has left, usually because it was also recorded by hand): see `docs/REFUNDS.md` §6 and the log |

**CSRF token mismatch when you also run another Laravel app locally.** Cookies
belong to a HOST, not a port, and every Laravel app names its CSRF cookie
`XSRF-TOKEN`. An app that leaves `SESSION_DOMAIN` unset writes that cookie to
`localhost` itself; Orbito writes it with `domain=localhost`. The browser keeps
both, the SPA sends whichever it reads first, and Orbito cannot decrypt the
other app's. Clear the cookies for `localhost` (DevTools → Application →
Storage → *Clear site data*) and reload. It comes back whenever you switch
between the two apps; running one of them on its own hostname — `*.localhost`
resolves to your machine in modern browsers — ends it for good.

**Changing ports** means changing all of these together, then
`php artisan config:clear`:

- `api/.env` — `APP_URL`, `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`
- `web/.env` — `VITE_API_URL`
- `web/vite.config.ts` — `server.port`

Any one out of step and the session cookie is never accepted, which is the
419/401 above.

## Before a real deployment

Set `PLATFORM_OWNER_PASSWORD` — the default ships in the repository — or sign
in once and change it. Demo accounts and Demo Academy are never created
outside `local` and `testing`.

**Each academy connects its own Stripe account** (ADR-13) — its admin does,
on **Payments**; the platform is never the merchant.

1. In Stripe, **API keys**: copy the secret key, or create a restricted key
   that may write PaymentIntents and Refunds. Paste it into **Secret key** on
   the Stripe card.
2. Workbench → **Webhooks** → **Create an event destination** → **Your
   account** → choose the API version → select the events the Stripe card
   lists → **Continue** → **Webhook endpoint** → **Continue**, and paste the
   card's **Stripe webhook URL** into **Endpoint URL**.
3. On the new destination's page, **Reveal secret** and paste the `whsec_…`
   into **Webhook signing secret** on the Stripe card. Then switch on **Take
   payments with this gateway**.

Sandbox and live are separate everywhere — their own keys, their own
endpoint, their own signing secret — so going live is all three again with
live values, and **Test mode** off. The endpoint must be public HTTPS; Stripe
delivers to nothing else. A Checkout page stays payable for the coupon
reservation window (`COUPON_RESERVATION_MINUTES`, 60 by default, never under
31), so keep that at 31 or more.
