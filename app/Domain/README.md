# Domain Layout

The `app/Domain` namespace is organized by business concern to keep ingestion and delivery boundaries clear:

- `Source`: source registration, discovery, and polling orchestration
- `Parsing`: feed parsing, HTML extraction, and schema validation
- `Article`: normalization and deduplication
- `Delivery`: channel-agnostic dispatch plus channel adapters (Telegram first)
- `Subscription`: subscription lookup and fanout metadata

Contracts are declared first. Concrete implementations can be added incrementally without changing upstream jobs/events.
