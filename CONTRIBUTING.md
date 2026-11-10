# Contributing

Mantis Bat is maintained by Teleport AI. Community contributions are welcome, and maintainers make final decisions about scope, design, compatibility, security and releases. Opening an issue or pull request does not guarantee a response by a particular date or that a change will be merged.

## Where to start

- Use **GitHub Discussions** for questions, early ideas and design conversations.
- Use **GitHub Issues** for reproducible bugs and concrete feature proposals.
- Use a **pull request** to propose a code or documentation change. For substantial or architectural work, open an issue first so maintainers can discuss the direction before you invest in implementation.

The issue forms ask for the details maintainers need to triage reports. Search existing discussions and issues before opening a duplicate.

## Pull request process

- `main` for stable work
- feature branches for new work
- fix branches for targeted corrections
- docs branches for documentation-only changes

Contributors should work from a fork and open a pull request against `main`. Keep each pull request focused and link the related issue when there is one. A Teleport AI maintainer reviews and merges accepted changes; external contributors do not need write access to this repository.

There is no automated deployment or CI workflow in this project. Describe the checks you ran and include manual verification notes. Do not add GitHub Actions, deployment workflows, or other repository automation without discussing the proposal with maintainers first.

## Development Priorities

1. Keep the connector shared-hosting friendly
2. Keep Ghost API assumptions isolated
3. Avoid committing secrets or runtime state
4. Prefer clear operational docs over clever abstractions

## Pull Request Notes

- explain user-visible behavior changes
- call out Ghost API contract assumptions
- mention security-sensitive changes explicitly
- include manual verification notes and limitations; do not claim checks you did not run
- update user-facing documentation when behavior or setup changes
- never include credentials, customer data, or unredacted logs

## Contribution terms

This repository is licensed under Apache-2.0. Contributions intentionally submitted for inclusion are made under that license unless a separate written agreement applies; see section 5 of [LICENSE](LICENSE).
