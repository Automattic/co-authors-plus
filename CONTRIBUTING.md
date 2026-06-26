# Contributing to Co-Authors Plus

## Pull Requests

Please make pull requests against the `develop` branch.

Ideally include tests.

## Local development

Run `wp-env start` to spin up a local WordPress environment. On start it
activates Query Monitor and seeds demo data (`bin/seed-demo-data.php`): a spread
of users, standalone and linked guest authors, and posts covering single,
multiple, mixed, fallback, and orphaned-author bylines. All demo users share the
password `password`.

Re-seed at any time (the script is idempotent) with:

```sh
composer dev:seed
```

## Terminology: co-author vs coauthor

If talking about co-authors in a documentation, DocBlock, comment, or user-facing string, please include the hyphen.

Variables, constants, class, trait, interface, function, method, hook, and other programmatic names can all use no-hyphen.
