# Graph Report - sdk-php  (2026-07-30)

## Corpus Check
- 8 files · ~4,208 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 60 nodes · 69 edges · 8 communities
- Extraction: 100% EXTRACTED · 0% INFERRED · 0% AMBIGUOUS
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `f3f68842`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- KrynoxCaptcha.php
- KrynoxCaptcha
- composer.json
- keywords
- require
- krynox/captcha (PHP)
- [0.1.0] - 2026-07-22

## God Nodes (most connected - your core abstractions)
1. `KrynoxCaptcha` - 7 edges
2. `keywords` - 6 edges
3. `KrynoxResult` - 6 edges
4. `krynox/captcha (PHP)` - 6 edges
5. `require` - 4 edges
6. `KrynoxAgent` - 4 edges
7. `KrynoxHuman` - 4 edges
8. `support` - 3 edges
9. `KrynoxErrorCode` - 3 edges
10. `KrynoxFeedback` - 3 edges

## Surprising Connections (you probably didn't know these)
- None detected - all connections are within the same source files.

## Import Cycles
- None detected.

## Communities (8 total, 0 thin omitted)

### Community 0 - "KrynoxCaptcha.php"
Cohesion: 0.23
Nodes (4): KrynoxAgent, KrynoxFeedback, KrynoxHuman, KrynoxResult

### Community 1 - "KrynoxCaptcha"
Cohesion: 0.33
Nodes (3): KrynoxCaptcha, KrynoxClassification, KrynoxErrorCode

### Community 2 - "composer.json"
Cohesion: 0.17
Nodes (11): autoload, psr-4, description, homepage, license, name, Krynox\\Captcha\\, support (+3 more)

### Community 3 - "keywords"
Cohesion: 0.33
Nodes (6): keywords, bot, captcha, krynox, proof-of-work, verification

### Community 4 - "require"
Cohesion: 0.50
Nodes (4): require, ext-curl, ext-json, php

### Community 5 - "krynox/captcha (PHP)"
Cohesion: 0.29
Nodes (6): API, Content classification (spam/abuse), Feedback (false-positive correction), krynox/captcha (PHP), Reasons, agents & attested humans, Reliability

### Community 6 - "[0.1.0] - 2026-07-22"
Cohesion: 0.33
Nodes (5): [0.1.0] - 2026-07-22, Added, Changelog, Notes, [Unreleased]

## Knowledge Gaps
- **24 isolated node(s):** `name`, `description`, `type`, `license`, `homepage` (+19 more)
  These have ≤1 connection - possible missing edges or undocumented components.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `keywords` connect `keywords` to `composer.json`?**
  _High betweenness centrality (0.053) - this node is a cross-community bridge._
- **Why does `require` connect `require` to `composer.json`?**
  _High betweenness centrality (0.033) - this node is a cross-community bridge._
- **Why does `KrynoxCaptcha` connect `KrynoxCaptcha` to `KrynoxCaptcha.php`?**
  _High betweenness centrality (0.029) - this node is a cross-community bridge._
- **What connects `name`, `description`, `type` to the rest of the system?**
  _24 weakly-connected nodes found - possible documentation gaps or missing edges._