# Fixture

The key words MUST, MUST NOT, SHOULD, SHOULD NOT and MAY in this chapter are to be interpreted as described in RFC 2119.

- An app MUST commit its lock file.
- An app MUST pin its components. PROCTOR check: `preflight-pinned`.
- An app SHOULD read the key file. Held by: SESAME config.
- An app MUST rotate its keys
  every 12 months. No check exists yet.
- An app MUST name its tables well. PROCTOR check, planned.
  - A nested rule MUST carry its own tag.
- Only `MUST` in a code span is not a rule.

A paragraph that SHOULD be tagged
runs over two lines.

```text
An app MUST NOT be flagged inside a code block.
```

| Rule | Status |
|---|---|
| An app MUST NOT be flagged in a table | n/a |

## Section

- An app MAY stay 90 days. PROCTOR checks: `mysql-eol`, `laravel-eol`.
