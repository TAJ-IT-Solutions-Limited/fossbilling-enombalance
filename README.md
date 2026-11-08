# Enom balance

Admin dashboard card for the Enom registrar already configured in FOSSBilling.

The card is rendered in the `admin.theme.content.before` slot, which is the top of every admin page, including the dashboard shown after login. FOSSBilling 0.8.9 does not have a separate admin-dashboard widget slot.

The module does not ask for an API token. It loads the existing Enom `TldRegistrar` through Servicedomain and calls `testApi()`, which sends `GetBalance` only. The parsed card is cached for 15 minutes. Refresh is limited to one call per staff member per minute. A low-balance threshold sends one email to admins per calendar day from the company email address. A mail failure does not break the page.

Copy is English and Arabic. A failure says "Could not read Enom balance" and redacts credential-like text.

This repository is private until Simon says to publish. Do not tag, release, or install it on billing.tajhost.com from this branch.
