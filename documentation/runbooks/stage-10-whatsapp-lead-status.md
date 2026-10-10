# Stage 10 — WhatsApp and lead engine: status against Technical Blueprint §9.4

Status is what the repository contains, not what is intended. Nothing here was run: no PHP, Composer, PHPCS, PHPStan or PHPUnit result exists for this package.

## Done in Stage 10

| §9.4 behaviour | Status | Where |
|---|---|---|
| Lead written server-side before the redirect | Implemented | `LeadHandler::process()`, `LeadCapture`, `LeadRepository` |
| Source, object, code, page, referrer, hashed IP, customer if signed in | Implemented | `LeadHandler::process()` builds them; the raw IP goes only to `Hash` |
| Lead write fails, WhatsApp still opens | Implemented | A failed or throwing store is isolated; the link is always returned |
| Same phone, same object, inside the window updates the lead | Implemented | `LeadRepository`; no-phone enquiries are never merged |
| Templates as named objects with declared tokens | Implemented | `TemplateRenderer`, `ContextResolver` |
| POST only | Implemented | `LeadHandler::request()`; `handle()` calls it |
| Replay protection | Implemented, unverified | `IntentIssuer`: server-issued, signed, single-use intent; see below |

## Not done, and why

**Enquiry counter ("incremented off-request").** Not implemented.
- `_rj_enquiry_count` exists from Stage 6: registered, private, not in REST, documented as system-owned.
- No increment API exists. Stage 6 offers `ProductGuard::as_system()` for the publication marker and Product Code only; whether a write to `_rj_enquiry_count` passes the guard has not been shown.
- "Off-request" needs a deferred worker. None exists in Stage 10, and nothing may be invented here.
- **Dependency:** an approved counter-writer contract owned by the Product Domain, plus the deferred-work mechanism. Until then no code touches `_rj_enquiry_count`.

**Lead notification event.** Not implemented.
- The Development Blueprint lists `rj/lead_created` and `rj/lead_unhandled_24h` as events for the notification stage (18).
- No event bus exists in this repository, and no `do_action` for either event is present.
- **Dependency:** the Stage 18 event bus. When it exists, `LeadCapture` raises `rj/lead_created` after a successful store. No event API is added early, and a failed delivery must never block the enquiry.

## Known limitation: no frontend caller

No code in this repository posts to the `rj_whatsapp_lead_capture` action, and no theme, block or shortcode calls the form seam yet. A presentation layer must call `LeadHandler::form_fields( $type, $object )` and post the fields it returns: `rj_type`, `rj_object`, `rj_nonce` and `rj_intent`. It may add `rj_phone` and the honeypot `rj_website`. Until a caller does so, the flow is not usable end to end. The theme must not build WhatsApp links itself.

## Replay protection

- **Issue:** `form_fields()` returns a token made of issue time, expiry (900 seconds), a random id and an HMAC over the action, enquiry type, resolved object, times and id, keyed with the site salt. Nothing is stored at issue time.
- **Check:** the handler recomputes the signature for the resolved type and object, so a token cannot be moved to another enquiry. A client-supplied timestamp is never used; the dwell time is measured from the signed issue time.
- **Consume:** a valid token id is claimed once by `IntentClaims`, which uses `Lock::acquire` on `lead_intent_<id>` (add-if-absent, so the first caller wins). The claim is held for 1200 seconds, the token lifetime plus 300. A token is always claimed before it expires, so its claim outlives it. The second submission of the same token, or any failure to claim it, records no lead.
- **Refusals:** a replayed, expired, tampered, too-fast or unclaimable submission records nothing, shows no error, and returns the engine-generated link for the resolved object. A too-fast attempt spends its token.
- **Storage:** with a persistent object cache the backend expires claims itself and nothing touches the options table. Without one, `Lock` keeps each claim as a non-autoloaded options row named `rj_lock_lead_intent_<16 hex>`. `Lock` only reclaims a row when the same name is acquired again, which never happens for a random id, so these rows do **not** expire by themselves.
- **Clean-up:** each claim on a site without an object cache removes up to 20 of the oldest expired claim rows, found by one prepared read of the options table. The exact name pattern (`rj_lock_lead_intent_` plus 16 lowercase hex characters, case-sensitive) is applied in the query itself, before the row limit, so unrelated or malformed options that merely share the prefix cannot use up the scan. The read returns at most 100 candidates, oldest `option_id` first, and at most 20 expired ones are deleted per claim. It deletes a row only if its recorded expiry has passed (unreadable records count as expired, as `Lock` treats them). An active claim is never deleted, and expired claims cannot matter again because their tokens are already invalid. A failing clean-up is ignored and never changes the claim result. Steady state is one row per recent submission. No cron job, table or option group is added.
- **Limit:** clean-up runs only when a claim is made, so after traffic stops up to 20 expired rows can remain until the next submission clears them. A burst larger than 20 expired rows is cleared over the next claims. `Lock.php` is not changed.
- **Not added:** no table, option group, route, cron job or theme code. `Lock.php` is unchanged.

## Request hardening

- Non-POST requests go to the home page and never reach lead capture.
- `rj_type`, `rj_object`, `rj_nonce`, `rj_phone`, `rj_website` and `rj_intent` must be a string or an integer. An array, object or other type is malformed.
- A malformed type or object goes home without resolving anything.
- A malformed nonce, phone, honeypot or intent value stores no lead, and the visitor still receives the link.
- A non-string `Origin`, `Referer` or client address counts as absent.


## Public helper: `rj_whatsapp_url`

- **Contract (owner-approved):** `rj_whatsapp_url( array $context ): string`. The context holds only `type` (`product`, `reel`, `collection`, `bridal`, `showroom`) and `object_id`. Any other key, a bad type or ID, or a non-zero ID for bridal returns an empty string. A numeric-string ID is read as the same integer.
- **What it does:** `PublicBridge::url()` resolves the trusted context with `ContextResolver`, then builds the link with the one registered `whatsapp.engine` service. It builds no URL, number or message itself, and the theme composes no WhatsApp URL.
- **Safe results:** an unknown, draft, private, trashed, hidden or wrong-type object, a missing or throwing engine or resolver, or a malformed resolver result all return `''`.
- **Unchanged:** availability and number fallback, the `call_us` phone route, stored per-product message overrides, template encoding and the 900-character rule, and the `rj_whatsapp_generated_url` filter with its strict URL validation.
- **Loading:** the function is defined in `src/WhatsApp/functions.php`, loaded by `WhatsAppModule::register()`, so it exists once the plugin has booted on `plugins_loaded`. It is not in the main plugin file, whose current version was not available when this was written.
- **Not a lead:** the helper only returns a link. It records no lead and sends no event.

## What is still not connected

- No frontend caller posts to `rj_whatsapp_lead_capture`, so lead capture is not usable end to end. The helper produces a link; a visitor click is not yet routed through the capture form.
- The approved next scope for that is the theme and renderer stage that submits `rj_type`, `rj_object` and the issued intent token (Stage 13 renderer, Stage 14 theme).
- The enquiry counter and the `rj/lead_created` event still wait on their later approved stages.
