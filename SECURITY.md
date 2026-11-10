# Security Policy

If you discover a security issue related to Mantis Bat, do not open a public issue with exploit details.

Use GitHub's **Report a vulnerability** option on the repository's Advisories page to send a private report. Repository administrators should enable GitHub private vulnerability reporting before directing contributors to this route.

If private reporting is unavailable, contact the repository owner through Teleport AI's private support channel. Do not post credentials, exploit details, or sensitive customer data in a public issue or discussion.

Please include the affected module and version, impact, and steps to reproduce when safe to share. The maintainers will coordinate a response privately.

## Sensitive Areas

- Ghost JWT handling
- Telegram bot token handling
- webhook authentication
- pairing and authorization
- storage exposure on shared hosting
- log redaction
