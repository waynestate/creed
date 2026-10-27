# Deploying to Citadel

- Citadel runs the deploy gate: `proctor gate` in `runGitPull()`, against the
  fetched commit, before it is checked out (ADR-0006).
- The deploy-time rule set is small, offline and fast: the target image's
  status and its PHP version against `policy.json`, the framework and stack
  package versions in `composer.lock`, and known advisories from a cached
  database (ADR-0006).
- EOL fails: a deploy that regresses a rule, a new app that fails any rule, or
  an expired override is blocked (ADR-0006).
- It ratchets: a deploy that doesn't make things worse always passes
  (ADR-0006).
- Each rule moves through stages, `report`, `warn`, `block`, with dates in
  `policy.json` (ADR-0006).
- The gate is pending the Citadel team (D-005). Until it is adopted,
  enforcement is CI on GitLab (ADR-0006).
