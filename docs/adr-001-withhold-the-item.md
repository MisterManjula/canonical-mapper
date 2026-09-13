# ADR-001 — Fail closed: withhold the item, not the field or the batch

**Status:** draft — facts assembled, reasoning to be written

## Context

*Facts available:*

- Three source systems export the same café menu. Two of them can state
  something this mapper cannot interpret while remaining perfectly well formed:
  a net price under a VAT code with no known rate, a composite referencing a
  product the export does not describe, a product carrying two promotions the
  canonical model cannot reduce to one.
- A well-formed file stating an uninterpretable fact is a different situation
  from an unreadable file, and the two answers go to different people. That
  distinction is the thing this ADR has to justify holding onto, because it is
  what costs the extra machinery.
- The invariant the whole model rests on: every item in the canonical output has
  exactly one determinable customer-facing price.
- The consumer of the output is a menu. A wrong price on a menu is charged to a
  customer; a missing product on a menu is noticed by a member of staff.

> **To write:** the framing. Why the question is *where* the uncertainty is
> allowed to stop, rather than whether to handle it.

## Decision

*What the code does:*

- The unit of withholding is **the item** — one product, with its price, its
  recipe and its promotion.
- An adapter returns `list<Resolved<Item>|Unresolved>`. `Unresolved` carries a
  `Flag` and no payload. Only a `Resolved` can be unwrapped into a canonical
  constructor, and PHPStan at level max rejects an adapter that does not handle
  the branch (`tests/Architecture/ResolutionCannotBeBypassedTest.php`).
- `Item` has no nullable price and no "price to be decided later" state, so an
  item whose price could not be determined has no half-built form to exist in.
- Containment is one `continue` in `NormaliseMenu`: a withheld item does not
  block the items around it.
- Cascade is `Domain/Rule/ComponentCascadeRule`: a composite whose component is
  not in the export is withheld too, under a flag of its own rather than
  inheriting its component's.
- Three reasons exist, and the list is meant to stay short: `TAX_BASIS_UNKNOWN`,
  `COMPONENT_MISSING`, `PROMOTION_CONFLICT`.
- A `Flag` is a work item: source system, the product id as that system spells
  it, the canonical SKU where one could be derived, a reason code, and one
  sentence naming what to verify and where.
- A file that cannot be read at all is `MalformedSource` and is not withheld —
  there is no item to withhold and no useful flag to raise. The CLI reports the
  two as exit `3` and exit `1`.

> **To write:** why the item is the right unit — the argument that a field is
> too small (a price-shaped hole in a published product is the guess, wearing a
> null) and the batch too large (one ambiguous row in a thousand-line export
> costing the import).

## Alternatives considered

*The options that were live, and what is true about each:*

**Default the value.** For the tax-basis case there is a defensible default: two
known rates, one of them commoner. The adapter has the information to apply it
in one line.

- Fact: the resulting price is a number nobody chose, and it is indistinguishable
  in the output from a price the file stated.
- Fact: the run would then report nothing, so the error is discovered by whoever
  is charged it.

**Publish the item and attach a warning.** The item stays in the menu; the
uncertainty is reported alongside it.

- Fact: the output is consumed by systems, not read by a person, so the item is
  priced whether or not anybody reads the warning.
- Fact: this is the arrangement that makes a report advisory, and an advisory
  report about a wrong price is a record that somebody was told.

**Reject the whole file.** Any ambiguity fails the import.

- Fact: the tax-basis fixture has three products and one unreadable code; the
  other two are unambiguous in every format.
- Fact: rejecting is safe in the narrow sense — nothing wrong is published — and
  it makes ambiguity expensive enough that the import is turned off or the
  fixture is edited until it passes.

**Withhold the field rather than the item.** A nullable price, or a price
carrying a "not determined" state, published with the rest of the product.

- Fact: this requires `Item` to have a state the invariant above forbids, and
  every consumer to handle it.
- Fact: the canonical model's guarantee would weaken from "every item has one
  determinable price" to "every item has a price or a hole", which is a
  guarantee about the shape of the data rather than about its meaning.

> **To write:** which of these is the honest rebuttal rather than the easy one,
> and why the third is the one that deserves the most respect.

## Consequences

*What follows, stated as facts:*

- A withheld item is **absent** from the output, and an absent item is
  indistinguishable from one that never existed. The flag and the exit code are
  the entire compensation for that, which is why the flag is a work item and
  not a log line, and why exit `3` is distinct from both `0` and `1`.
- A missing item is a support ticket. Somebody in the café will notice that the
  croissant is not on the till before anybody notices the flag, and the business
  has to accept that trade rather than have it explained after the fact.
- Withholding propagates. One unreadable VAT code can remove a breakfast set as
  well as a croissant, and the report says so in two flags rather than one.
- The mechanism is not free: `Resolution`, `Resolved`, `Unresolved`, `Flag`,
  `FlagReason` and `SourceName` exist only to carry it, and every adapter method
  that might fail to resolve is declared as a union.
- `CanonicalMenu::of` refuses a menu referencing a product it does not contain,
  so a bug in the cascade rule cannot publish a dangling reference. The cost is
  that such a bug surfaces at the boundary as `MalformedSource` — "the file is
  broken" — when the file is fine.
- Nothing in `src/` suppresses static analysis, and a test asserts it. The
  guarantee is only as good as the absence of ways to switch it off.

> **To write:** the closing position — what the business is being asked to
> accept, and what it gets for it.
