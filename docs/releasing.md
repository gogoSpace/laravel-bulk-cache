# Releasing a prerelease

A version becomes installable through Composer as soon as its Git tag exists. The release workflow therefore runs all verification before creating either the tag or the GitHub Release. This workflow publishes alpha, beta and release-candidate versions; stable publication needs a separate review of package maturity and this policy.

## Prepare and verify

1. Prepare the version's changelog section and update the exact installation version in README. Keep the beta maturity notice. Commit the changes and merge the PR after CI passes.
2. Copy the full 40-character SHA of the current `main` commit. Open **Actions → Release → Run workflow**, select `main`, and enter that SHA and a version without the `v` prefix, for example `0.1.0-beta.3`.
3. Leave **publish** disabled for a verification-only run. The workflow runs the same package and distribution matrices as CI, then the `Release acceptance` check. It creates no tag or release. This mode verifies the commit and workflow; it does not assert that version metadata or an existing tag is eligible for publication.
4. To publish, run the workflow with **publish** enabled. All verification runs again for the exact requested SHA. The publisher also checks release metadata, the current workflow run and attempt, the successful acceptance job, and the current remote `main` before any write. If `main` advanced, start a new run for the intended current commit.

The package matrix covers PHP 8.3/8.4 with Laravel 12 and PHP 8.4/8.5 with Laravel 13. The distribution matrix runs clean Laravel 12/13 archive consumers, installed Redis suites, documentation examples, package checks and beta.1 upgrade/rollback checks on PHP 8.4 and 8.5. Failed, cancelled or skipped prerequisites fail the acceptance gate.

Only the publication job has `contents: write`; verification has read access. The publisher uses the job's short-lived `GITHUB_TOKEN`. No personal access token or additional GitHub App is required. Checkouts do not retain credentials. A repository-wide release concurrency group prevents overlapping publication runs.

## Protect the tag itself

The repository's active tag ruleset must target `v*`, have no bypass actors, and:

- Require `Release acceptance` from the **GitHub Actions** app on the target commit, including when the tag is first created.
- Reject tag updates, deletions and force pushes.
- Leave **Restrict creations** disabled: the required check controls eligibility, while that option with an empty bypass list would prohibit all tag creation.

The versioned configuration is [release-ruleset.json](https://github.com/gogoSpace/laravel-bulk-cache/blob/main/.github/release-ruleset.json). GitHub settings enforce the live ruleset; committing this JSON alone does not activate it. GitHub accepts skipped or neutral required checks in some cases, so our acceptance job always runs and explicitly fails unless every prerequisite reports `success`. The publisher independently requires an actual completed, successful gate from its current workflow run and attempt.

These protections prevent ordinary tagging of an unverified commit. They do not remove the repository owner's power to change rules or workflows. GitHub binds a required check to its name and app, not exclusively to one workflow file. An authorized workflow editor remains a trusted maintainer. A manual tag on a commit that already passed the required gate is permitted; exclusive publication by a separate actor would need a separately managed identity.

## Interrupted publication

The publisher creates an annotated tag on the verified SHA, verifies the remote tag, and then creates a prerelease. It never moves or deletes a tag. A matching existing tag with no release can be completed by rerunning verification. A matching finished prerelease is a verified no-op. A tag on another commit, a lightweight tag, or conflicting release metadata fails without rewriting anything.

If a write loses its response, the publisher reads the current server state before continuing. An unconfirmed result fails and can be inspected before a new run. Do not delete a version tag to work around a failure: it may already be installed by consumers.

Do not rely on tag-triggered CI as the publication gate. GitHub usually does not trigger another workflow for writes made with `GITHUB_TOKEN`; the required full verification is already part of the release run. After publication, verify the actual Composer installation of the public tag and its distributed files. Packagist registration is a separate step.

The workflow and publisher have executable regression tests in `composer check`, including failure states, immutable SHA propagation, permissions, conflicting tags, repeat invocation and uncertain write responses.
