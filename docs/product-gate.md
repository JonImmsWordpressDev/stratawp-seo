# Product gate for specs

Every design spec in `docs/superpowers/specs/` starts with a **Product gate**
section, directly under the title and date. The gate is scored before an
implementation plan is written.

Adopted 2026-11-20 after a trial over the five newest specs: all five failed,
and the hours row was missing from every one. See
`docs/reviews/2026-11-20-product-gate-trial.md`.

## Scoring

Each row scores 0, 1 or 2:

- **0:** absent.
- **1:** implied or vague.
- **2:** explicit and checkable.

Any row scoring 0 means no implementation plan yet: fill it in or drop the
feature.

- "Unknown, measure first via X" counts as a 1 for baseline, which keeps the
  gate cheap for small features.
- A target release or version number is not an hours estimate.
- A list of outputs ("a sidebar exists") is not a metric.
- Jon is often the user. "Jon (dogfood)" plus the concrete trigger is valid
  evidence, for example "a Semrush audit of jonimms.com caught 4 defect classes
  we missed".

## Template

```markdown
## Product gate
- **User + evidence:** <who> — <what shows they need it: request, support ticket, audit finding, own-site defect, competitor gap with link>
- **Metric:** <the one number this should move>
- **Baseline → target:** <today's value, or "unknown, measure first via X"> → <target by when>
- **Riskiest assumption:** <the thing that, if wrong, makes this not worth building> — cheap test: <how>
- **Hours:** build ~<n>h, maintain ~<n>h/quarter

### AI builds only (calls a model at runtime)
- **Eval set:** <n fixtures of real inputs + what "good output" means and how it's checked>
- **Guardrails:** <review-before-write, budget cap, injection fencing, undo>
- **Run cost:** <tokens per call × expected calls/month at current model price = $/month>
```

## Why the AI rows exist

In the trial, guardrails were strong in every AI spec, but none of the three
had an eval set. Two releases also fixed gaps these rows would have caught at
spec time:

- **4.32.1:** sidebar AI calls weren't counted toward the monthly budget.
- **4.32.2:** the rollout depended on an admin notice that the block editor
  hides. That was the riskiest assumption, and it was never named.
