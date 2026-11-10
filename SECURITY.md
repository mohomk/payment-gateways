# Security policy

This package signs payment requests and verifies payment callbacks, so we treat security issues in it as urgent.

## Supported versions

Security fixes are released for the latest `0.x` version only. Upgrade to the newest release before reporting.

## Reporting a vulnerability

Please **do not open a public issue** for a vulnerability.

Report it privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability**. Include:

- the affected provider (cPay, NLB) and package version
- what an attacker can do, and the steps or request data that show it
- any fix you suggest

We aim to confirm the report within 3 working days and to release a fix, with credit if you want it, before the issue is made public.

## Scope

In scope: request signing, callback and signature verification, and anything that could let a payment count as completed when it was not.

Out of scope: the provider's own systems (report those to cPay/Casys or NLB/Bankart directly) and how an application stores its merchant credentials.

## More

Cream ERP: [cream.mk](https://cream.mk) · Moho: [moho.mk](https://moho.mk)
