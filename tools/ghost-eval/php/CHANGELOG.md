# Changelog

## 0.1.0

- standalone Ghost evaluation tool scaffold
- group Files suite discovery and Markdown report upload
- realtime Ghost challenge and same-Ghost judge calls
- protected setup dashboard, one-call cron worker, status, health, and maintenance pages
- `src/Config.php` adapter following the Mantis Bat module convention
- optional daily or weekly suite scheduling through the existing cron worker
- 15-second chat connection timeout and 30-second total request timeout
- challenge chat uses realtime personal-context `/chat` with configurable `options.use_rag`, `options.use_history`, and selected `active_groups`
- judge chat explicitly disables RAG and history, with no selected memory groups
- Files destination is independent from chat context and RAG memory groups
