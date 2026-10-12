# Security

Report suspected credential exposure or vulnerabilities privately to the repository owner; do not paste API keys, admin cookies, wp-config.php or private logs into public Issues.

Only WordPress administrators with sufficient capabilities should configure API providers or start paid campaigns. Use HTTPS, keep WordPress/PHP updated, and rotate exposed keys via the relevant provider portal. The plugin stores keys encrypted with WordPress salts; changing salts may require re-entering keys. Do not commit real keys, `.env`, WordPress database exports or production-specific configs.

Release packages are built from a tracked commit, then manually installed. CI does not connect to a live WordPress site or use paid API keys.
