## Billing module

`modules/billing` (namespace `Modules\Billing`) handles checkout, subscriptions, payments, invoices, and
webhooks through a payment gateway driver (Stripe by default).

- Look up provider rows by `(provider, provider_*_id)`, never the provider ID alone.
- `Price::amount` is in minor units (cents); format with `Currency::formatAmount()`.
- Delete billing owners through the model, never a bulk query; billing history outlives the account.
- Raw SQL must work on SQLite, MySQL, and PostgreSQL.

Activate the `saucebase-billing-development` skill before changing this module.
