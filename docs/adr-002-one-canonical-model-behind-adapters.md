# ADR-002 — One canonical model behind ports and adapters

**Status:** draft — facts assembled, reasoning to be written

## Context

*Facts available:*

- Three source systems, and consumers that need a menu: a till, a printed card,
  a delivery integration. Point-to-point, that is N × M translations, each of
  which has to decide what a price means. Through a canonical model it is N + M,
  and the decision is made once.
- The mapper is an anti-corruption layer. Its entire job is to stop three
  systems' vocabularies from reaching anything downstream.
- The three formats disagree about identity (`"000099"`, `code="99"`, `P-99`),
  about price (gross decimal with a dot, net minor units plus a tax code, gross
  decimal with a comma), about composition (nested children, a list on the
  parent, a column on the child pointing upward), and about promotions
  (percentage, absolute price, not expressible at all).
- Guarantee 1 is a byte comparison. Anything a source contributes that another
  source cannot express would break it.

> **To write:** the framing. Why the shape of the *model* is the decision, and
> the layering only follows from it.

## Decision

*What the code does:*

- One canonical model: `CanonicalMenu`, `Item`, `ComponentRef`, `Money`, `Sku`,
  `Promotion`. Three layers — Domain, Application, Infrastructure — with
  dependencies pointing inward, enforced by
  `tests/Architecture/DependencyRuleTest.php` rather than drawn.
- One driven port, `SourceAdapter`, whose contract includes `MalformedSource`.
  No output port: `NormaliseMenu` returns a `MappingResult`, and presenting it
  is the driving adapter's job.
- The CLI is a driving adapter. `NormaliseCommand` returns values;
  `bin/normalise` is the only file that writes to a stream or ends a process.
- The canonical menu is flat: composites reference components by SKU, and a
  component is an ordinary item in the same list.
- Money is always **gross**, in minor units, one currency.
- The split of responsibility: **source-specific ambiguity is detected in the
  adapter** — only BetaPos knows what `V99` means. **Source-neutral ambiguity is
  detected in the domain** — a dangling component and a pair of promotions are
  the same facts whichever system sent the file, and they live in `Domain/Rule/`.
- The domain names no source and no format. `Sku::ofDigits` takes digits;
  `Money::fromMinorUnits` takes minor units; `Flag` carries a `SourceName` value
  object, and the `SourceSystem` enum lives in Infrastructure where adding a
  fourth source is supposed to cause edits.

> **To write:** why a reference plus a flat list is the right canonical shape —
> the argument that it is the shape *none* of the three sources has, and that
> adopting any source's shape would have favoured that source.

## Alternatives considered

*The options that were live, and what is true about each:*

**A layered design: a service that calls a parser per format.** The common
arrangement, and the one that needs no ports.

- Fact: the direction of the dependency is the only difference that matters. The
  core would name its parsers, so a fourth source would edit the core.
- Fact: the test that drives `NormaliseMenu` through a hand-written adapter
  returning decided answers (`tests/Application/NormaliseMenuTest.php`) is only
  possible because the dependency is inverted. Under a layered design the test
  of containment would have to express "an item that could not be resolved" as a
  tax code in an XML document, and would fail when the XML parser broke.

**Point-to-point translation, no canonical model.** Each consumer reads each
source.

- Fact: N × M, and every one of those translations decides independently what a
  price means.
- Fact: it is genuinely cheaper at N = 1.

**Let the canonical model keep each source's shape where it can** — nesting for
the source that nests, a percentage for the source that states one.

- Fact: two menus describing the same assortment would then serialise
  differently depending on which system exported them, and guarantee 1 is a byte
  comparison.

**Keep the price net, with the tax code.** The basis BetaPos stores.

- Fact: two of the three sources have no tax code to contribute, so the field
  would be empty for them, and the canonical output would carry a difference
  that exists only in one exporter's bookkeeping.
- Fact: gross is the number a customer is shown. Net plus a code is a fact about
  how a till stores it.

> **To write:** where the adapter/domain line would have been drawn wrongly, and
> what it would have cost. The evidence is in the history: the
> dangling-component check lived in `GammaPosAdapter` alone until step 7, and
> `BetaPosAdapter` therefore published canonical JSON referencing a SKU that was
> not in the menu — no flag, no error. That is the concrete form of "business
> rules duplicated across sources", and it is worth using rather than asserting.

## Consequences

*What follows, stated as facts:*

- **What the canonical model loses, deliberately:** the promotion mechanism (a
  consumer cannot recover "20% off" from the output, only the resulting price);
  the source's spelling of an identifier (kept on flags, where somebody has to
  search for it, and nowhere else); the net/VAT basis; the file's own ordering;
  the direction in which GammaPos expresses composition.
- A fourth source is an adapter, a `SourceSystem` case and a `match` arm. It
  touches no canonical type. The exhaustive matches in `SourceSystem` are what
  make the compiler ask for the arm.
- **The cost is types and indirection for a small program.** `SourceName` exists
  so that the domain does not enumerate sources. `Resolution` is declared as a
  union at every signature rather than as an interface, because PHPStan narrows
  unions and not base types. Three adapters and a port carry what could have
  been three functions. This is accepted on one condition: that a fourth source
  must not touch the core.
- The hexagonal shape was not present from the first commit. It arrived as a
  refactor in four steps, during which the domain imported `MalformedSource` and
  the port returned an enum on its way out of the domain — two deliberate
  violations, each named in the commit that introduced it. The dependency test
  exists so that the next one cannot be unnamed.
- Format knowledge is now in exactly one place per format, and the domain's
  error messages mention no file and no path. The adapter translates
  `InvariantViolated` into `MalformedSource` at the boundary, adding the path
  and the raw text only it has.

> **To write:** the closing position — what this shape buys that a layered
> design does not, said without repeating the diagram.
