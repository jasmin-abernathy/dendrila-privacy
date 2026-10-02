# CI / GitHub Actions budget policy

These rules apply to automated work in this repository.

- Work in batches: edit many, validate once, build once.
- Do not trigger a heavy build after every small commit.
- README, documentation and this policy should not trigger CI.
- APK/ZIP/package artifacts should be built manually or on deliberate release tags unless continuous packaging is explicitly required.
- Keep lightweight syntax, security and deployment-safety checks automatic for relevant code paths.
- Use path filters, timeouts, caches and `concurrency` with `cancel-in-progress: true` for CI when safe.
- Do not cancel a production deployment mid-run unless the deployment is explicitly designed to be safely interruptible.
- Group related automated edits into as few pushes as practical.
- Prefer self-hosted runners for heavy builds when one is available.

# WordPress.org release visibility policy

These rules also apply to automated release work in this repository.

- Any user-visible feature added since the previous WordPress.org release must be reviewed for visibility in the main `== Description ==` section of `readme.txt`, not only documented under External services or in the changelog.
- Before bumping a new stable version, explicitly ask: “Should this change be discoverable from the public plugin description?” If yes, update the main feature list before creating the SVN tag.
- Do not wait until after the SVN tag exists to make that decision. Treat description review as a release-blocking checklist item.
- External APIs/services must still be fully documented in the External services section even when also mentioned in the main description.
