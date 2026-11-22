# Private Mail Connector

Private Mail brings your own email account into telmi OS. You can read and write messages in a protected mail app, receive summaries, and let a Ghost help with correspondence according to the rules you choose.

## Why Two Ghosts?

The **worker Ghost** is your mailbox helper. It summarizes incoming email, tells you or your chosen group about it, and passes reply work along. Turn on agentic mode for this Ghost and leave autonomous mode off. It does not need the Private Mail tools.

The **autonomous Ghost** is the Ghost that works with you on your email. It can prepare replies, ask for approval in chat, and send them; an auto rule allows it to send without routine approval when the reply recipients qualify. This must be a different Ghost, with agentic and autonomous modes enabled. Private Mail installs its tools on this Ghost.

For example, you could ask for a draft whenever a client writes. The worker summarizes the email and asks the autonomous Ghost to prepare a reply. That Ghost brings the proposed reply to you or your selected group for approval before sending it.

Add both Ghosts to relevant groups, such as a project or team group, so they can reach the people involved and use shared context when available. Group membership alone does not guarantee group memories are included in every request; that depends on the Ghost's access, the conversation context, and the RAG setting. The autonomous Ghost also needs write access to the Files space chosen for requested attachments.

## Getting Started

Have your mailbox connection details and a separate Ghost API key (JWT) for each Ghost ready. In Hades, choose Private Mail, open its installer, and follow the steps to connect the mailbox and both Ghosts. Choose a person or group for notifications and approvals, then schedule the provided Cron URL once per minute.

The [connector README](../connectors/private-mail/php/README.md) has the full setup, rules, privacy, and operations details, including how to update an existing installation.
