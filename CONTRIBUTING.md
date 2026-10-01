# Contributing to Dendrila Privacy

[Version française](CONTRIBUTING.fr.md)

Thank you for helping improve Dendrila Privacy.

## Before opening a change

- Check whether a similar issue already exists.
- Keep changes focused: one coherent bug fix or feature per branch/pull request when possible.
- Prefer local, privacy-preserving behaviour when a task does not require a remote service.
- Never commit passwords, API keys, personal data, client exports, database dumps, or production credentials.
- Do not make a legal page public or enable visitor tracking/consent behaviour silently.

## Minimum checks

Before a push or pull request that changes code:

- run `php -l` on changed PHP files;
- run `node --check` on changed JavaScript files;
- test plugin activation without fatal errors;
- if the scanner changed, verify that one failing URL does not stop the remaining scan;
- if consent changed, verify deny-by-default behaviour and equal Accept/Reject presentation;
- if legal-document handling changed, verify that drafts and explicit publication rules are preserved.

The detailed checklist for the current build is in `docs/TESTS-0.0.2-test4.md`.

## Pull requests

Describe:

1. what problem is being solved;
2. what changed;
3. how it was tested;
4. whether the user interface or public output changed.

Small, reviewable pull requests are preferred over unrelated batches of changes.
