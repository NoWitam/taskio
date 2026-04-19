---
description: Analyze the existing forms module, identify reusable parts, and propose a minimal migration plan without changing code.
agent: forms-maintainer
---

Analyze the current forms module in this repository.

Do not change code.

First inspect what already exists and what can be reused.
You must preserve the spirit of the application, its architecture, and the author's coding style.

Identify:
- current data model
- current state logic
- validation paths
- indexing paths
- reporting/filtering paths
- entity assignment logic
- reusable services
- reusable components
- reusable hooks
- reusable utilities
- existing migration patterns
- existing tests

Then provide:
- files inspected
- reusable building blocks
- risks of duplication
- likely files to change
- the smallest safe implementation plan