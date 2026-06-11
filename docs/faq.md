<p><img src="../assets/logo/mantis-mini.svg" width="56" alt="Mantis Bat"></p>

# FAQ

## What is Mantis Bat?

Mantis Bat is the self-hosted connector framework for Telmi OS Ghost API v2. The first module connects a user-owned Telegram bot to a user-owned Ghost.

## Is Mantis Bat the AI itself?

No. The Ghost and its operating environment live in telmi OS. Mantis Bat is the connector layer that carries messages between an external channel and Ghost API v2.

## Why not just use a shared bot?

Because the project is built around user-owned channels. You control the bot token, the channel, and the hosting path. That keeps the edge connector private while telmi OS provides the secure intelligence layer.

## What does telmi OS add beyond chat?

According to the public Teleport AI documentation, telmi OS combines conversation with governed memory, Ghost execution, groups, Board work, tool permissions, approvals, and operational visibility.

## Can a Ghost act automatically through Mantis Bat?

Not by default. Public Ghost API v2 separates:

- agentic interpretation
- Action Mode and tool permission
- autonomy registration
- approvals and group permissions

The connector should preserve those limits.

## Does Mantis Bat store my Telegram account?

It stores the minimum pairing and delivery data needed to operate the connector, such as Telegram user ID, chat ID, and delivery records. It should not store more than the connector runtime needs.

## Does the memory command talk to chat?

No. `bat_memory_up:` should bypass normal chat and call the public memory upsert endpoint directly.

## Will other channels be supported?

The repository is structured as a connector framework, but the only shipped connector in `v0.1.0` is the Telegram PHP module.
