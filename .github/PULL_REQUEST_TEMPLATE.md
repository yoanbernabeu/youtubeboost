## What this changes

<!-- One or two sentences, from the point of view of someone using the tool. -->

## Why

<!-- The problem it solves. Link the issue if there is one: Fixes #123 -->

## How you convinced yourself it works

<!-- Which test covers it, and anything you checked by hand. -->

## Checklist

- [ ] `make check` is green (style, PHPStan, linters, Symfony Language Tools, full test suite)
- [ ] A test exercises the change the way a caller would
- [ ] Any new user-visible string is a translation key, present in **both** `messages.en.yaml` and `messages.fr.yaml`
- [ ] Schema changes go through a migration, not `doctrine:schema:update`
- [ ] No API key, token, password or personal data in the diff
- [ ] `docs/decisions-techniques.md` records the reasoning, if this made a non-obvious choice
