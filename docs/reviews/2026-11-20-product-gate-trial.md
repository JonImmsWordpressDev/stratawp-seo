# Product gate trial: last 5 StrataWP SEO specs

Date: 2026-11-20
Scope: `docs/superpowers/specs/`, the five newest design docs. Implementation plans aren't counted.
Scoring: 0 = absent, 1 = implied or vague, 2 = explicit and checkable. A spec **fails if any question scores 0**.

## Verdict

**5 of 5 fail. The threshold was 3, so the gate becomes standard.**

The result holds under a looser rule too. If a spec only had to total 5 of 10, all five would still fail: the best scored 4.

## Scorecard

| Spec | Shipped | 1 User + evidence | 2 Metric | 3 Baseline → target | 4 Riskiest assumption | 5 Hours build + maintain | Total | Result |
|---|---|---|---|---|---|---|---|---|
| Editor sidebar (2026-10-08) | 4.32.0 | 1 | 1 | 0 | 1 | 0 | 3 | FAIL |
| Generate pages / images / sources (2026-09-06) | 4.31.0 | 1 | 0 | 0 | 0 | 0 | 1 | FAIL |
| Audit Fix-It engine (2026-08-21) | 4.28.0 | 1 | 1 | 0 | 1 | 0 | 3 | FAIL |
| Site Audit dashboard (2026-08-14) | 4.27.0 | 2 | 1 | 1 | 0 | 0 | 4 | FAIL |
| Owned domains cross-site links (2026-07-25) | 4.25.0 | 1 | 0 | 0 | 0 | 0 | 1 | FAIL |
| **Specs scoring 0** | | 0/5 | 2/5 | 4/5 | 3/5 | **5/5** | | |

## Why each score

**Editor sidebar**
1. One: "the writer", and the goal is "clearly better than Yoast and Rank Math". It names no user request or data.
2. One: the proof snapshot stores "score, focus keyword position and clicks", so a metric is implied. The spec never says which number should move.
3. Zero: no baseline, no target. "Success looks like" lists outputs (a sidebar exists, multi-keyword works).
4. One: risks are handled but never named as the assumption. Showing old and new scores "so old posts do not swing" is an example.
5. Zero: no estimate.

**Generate pages / images / sources**
1. One: the problem is written from the product's side ("can only produce blog posts"). No user is named and there's no evidence anyone asked.
2. Zero.
3. Zero.
4. Zero. "Zero behaviour change for cron" is a constraint, and the dropped parallel fetch was a review finding. Neither is the riskiest assumption.
5. Zero. "Target release: 4.31.0" is a version number, not an estimate.

**Audit Fix-It engine**
1. One: "every fix is manual". The claim about "highest-volume issues" has no counts behind it.
2. One: the health score is the obvious metric, but it's only shown as a UI example ("Health 61 → projected 78").
3. Zero: 61 → 78 is illustrative, not a target.
4. One: it names some risky things ("canonical_mismatch ... too risky", ~25% of URLs are archives). It never states the assumption the feature rests on, which is that AI drafts are good enough to accept.
5. Zero.

**Site Audit dashboard** (the strongest spec)
1. Two: a real Semrush audit of jonimms.com "caught four classes of real defect the plugin's own audit missed". This is the only spec with evidence, though from a single site (Jon's own).
2. One: Semrush parity is the implied measure, and the health score is product output, not a product metric.
3. One: baseline (4 defect classes missed) to target ("~25 checks", Semrush parity) is close to checkable.
4. Zero.
5. Zero.

**Owned domains**
1. One: "Design supplied by owner". Jon is the user, and nothing else is cited.
2–5. Zero. "Constraints discovered in the codebase" is technical design, not an assumption about value.

## AI build rows

These are scored only for the three AI specs and recorded separately from the verdict.

| Spec | Eval set | Guardrails | Run cost |
|---|---|---|---|
| Editor sidebar | 0: the parity fixtures cover the instant JS checks, not AI fix quality | 2: an explicit "Guardrails" section covering the budget cap, explicit Apply, and checking that the target text still exists | 1: an estimated cost per click "when the model price is known", with no monthly figure |
| Generate pages / sources | 0: the prompt-structure unit tests and a prompt diff check shape, not output quality | 2: fenced source block, "do not invent facts", SSRF-safe fetch | 0: up to 20,000 chars of source per call, with no token cost discussed |
| Fix-It engine | 0 | 2: review before apply, snapshot and undo, rate limiter | 1: cost is "shown on the review panel" after the fact, with no estimate beforehand |

The pattern is that guardrails are strong everywhere, while eval sets are missing everywhere.

## Did the missing rows cost anything? (changelog check)

All five features shipped. In three cases, follow-up releases fixed something an assumption or AI row would likely have flagged at spec time:

- **4.32.1**: the editor sidebar's "AI keyword suggestions and AI visibility re-scoring were not counted toward the monthly AI budget". That's a run-cost or guardrail gap in a spec that had a Guardrails section.
- **4.32.2**: "sites upgraded from earlier versions could not see the prompt to turn on the new editor sidebar, because WordPress hides admin notices inside the block editor". The rollout plan assumed an onboarding notice would be seen. That was the riskiest assumption, and nobody named it.
- **4.32.3**: Site Audit recorded redirecting URLs as pages, "every moved or consolidated post was reported as a duplicate title". It also flagged tiny core `.min.js` files as unminified. Both were false positives from heuristics that weren't checked against real sites.

The **hours** row was 0 in all five specs. That's the most consistent gap, and the one a solo developer can least afford.

## Caveats

- These are engineering design docs produced by the brainstorming skill. They were never meant to be product briefs, so the gate is measuring a section nobody asked them to write. That's an argument for *adding* a short header, not for blaming the specs.
- I scored the specs myself, from the files. A second reader might move some 1s to 0s, or the reverse. The verdict doesn't depend on any single cell, because the hours row alone fails every spec.
- Evidence for "user" is structurally thin here because Jon is often the user. The gate template (`docs/product-gate.md`) allows "Jon (dogfood)" plus a concrete trigger, the way the Site Audit spec did it.

## Outcome

The gate was adopted. The template and scoring rules live in `docs/product-gate.md`.
