# canonical-mapper

![CI](https://github.com/MisterManjula/canonical-mapper/actions/workflows/ci.yml/badge.svg)

Three point-of-sale systems describe the same café menu in three incompatible
formats. This maps each of them into one canonical model, built around two
guarantees that hold regardless of which source the file came from.

**Every source produces the same canonical output.** The same assortment,
exported from AlphaPos as JSON, from BetaPos as XML and from GammaPos as CSV,
maps to byte-identical canonical JSON. The three files agree about almost
nothing — one nests a composite's children inside the parent, one lists them on
it, one puts the relationship on the child row pointing the other way; one
stores the price a customer sees, one stores a net amount and a tax code. This
is asserted by a test, not assumed.

**Ambiguous data is withheld, never guessed.** When a value cannot be resolved
with confidence, the item is left out of the output and reported with the source
system, the product as that system spells it, and one sentence naming what to
check. Withholding is contained — the other items still pass — and it propagates
upward, so a composite whose component was withheld is withheld too, under a
flag of its own.

The single invariant behind the second guarantee: **every item in the canonical
output has exactly one determinable customer-facing price.** Anything that would
violate it is withheld rather than estimated, defaulted or dropped in silence.

An unresolved value cannot reach the canonical model, and that is a fact about
the types rather than a rule anybody follows. Adapters return `Resolved` or
`Unresolved`; only a `Resolved` can be unwrapped into a canonical constructor.
An adapter that forgets the `Unresolved` branch does not fail review — it fails
static analysis, which is asserted by [a test that runs PHPStan over a
deliberately broken adapter](tests/Architecture/ResolutionCannotBeBypassedTest.php).

Scope is deliberately small: see [Deliberate limitations](#deliberate-limitations)
for what was left out and why.

---

## Running it

Everything runs inside the container. There is nothing to install on the host
beyond Docker — no PHP, no Composer.

```
docker compose build
docker compose run --rm app composer install
docker compose run --rm app vendor/bin/phpunit
docker compose run --rm app vendor/bin/phpstan analyse
```

`composer install` reads `composer.lock`, which is committed: the central claim of
this repository is a static-analysis result, and a floating dependency version
would make that result a statement about one afternoon rather than a reproducible
one. After editing `composer.json`, run `composer update` instead; no image rebuild
is needed, because the image contains no application code.

The container runs as root, so generated files are root-owned on a Linux host.
Harmless for a throwaway toolbox, and worth knowing before it is a surprise.

### The command line

```
bin/normalise <alpha|beta|gamma> <file>
```

Canonical JSON goes to stdout and the flag report to stderr, so redirecting
stdout gives a menu a consumer can parse with the questions still on the
terminal where somebody sees them.

```
$ docker compose run --rm app bin/normalise beta fixtures/ambiguous/tax-basis.xml > menu.json
{"source":"BetaPos","sourceProductId":"1100","sku":"1100","reason":"TAX_BASIS_UNKNOWN","detail":"Product 1100 carries VAT code V99, which has no known rate, so its net price cannot be converted to the gross price the canonical model publishes; confirm the rate for V99 in BetaPos."}
$ echo $?
3
```

| Exit | Meaning |
|---|---|
| `0` | Every item resolved. The menu on stdout is the whole assortment |
| `3` | The mapping completed and at least one item is waiting on a person |
| `1` | Nothing was produced: the arguments or the export could not be used |

The distance between `1` and `3` is the whole argument of the repository, said
to a program rather than to a reader. A file that could not be read is a
failure. A file that was read and left one product in question is not — it
produced a menu and a list of questions — and a pipeline that treated the two
the same would either halt on every ambiguity or publish under every one of
them. A caller that has not thought about it can test for non-zero and get the
cautious reading.

The report is JSON lines rather than a document, one flag per line: readable the
moment it is written, greppable by reason code without a parser, and with no
room at the top for a summary. A report of `COMPONENT_MISSING x3` that does not
say which three is not a work item.

---

## The three sources

Each source differs on format, identity, price, composites and promotions. The
differences are invented, and they are the content of the project: equivalence
between three formats that agreed with each other would prove nothing.

| | AlphaPos | BetaPos | GammaPos |
|---|---|---|---|
| Format | JSON | XML | CSV, semicolon-separated |
| Identity | `"plu": "000099"`, zero-padded | `code="99"` attribute | `P-99` in the `PLU` column |
| Price | Gross, `"1.10"` | **Net**, minor units, plus a VAT code | Gross, `"1,10"` |
| Composites | Children nested inside the parent | `<component code="…" qty="…"/>` on the parent | Child rows carry a `PARENT_PLU` |
| Promotions | Percentage off, ISO 8601 range | Absolute promotional price, net | Not expressible |

One espresso, as each of them writes it:

```json
{ "plu": "000099", "name": "Espresso", "price": "1.10" }
```

```xml
<product code="99" name="Espresso" net="100" vat="V10"/>
```

```
P-99;Espresso;1,10;;
```

and as the canonical model publishes it:

```json
{
    "sku": "99",
    "name": "Espresso",
    "price": 110,
    "components": [],
    "promotion": null
}
```

Only one of those three files contains the number `110`. BetaPos stores a net
amount and a tax code, so every price it contributes is arrived at by
arithmetic — which is why two sources converging on identical bytes is evidence
that this is a model and not a reformatting.

The semicolon in the CSV is not decoration: GammaPos writes decimals with a
comma, so a comma-separated file would need every price quoted.

### Where each format's knowledge lives

The padding, the `P-` prefix, the comma decimal and the VAT table are each in
exactly one adapter, and none of them is named anywhere in the domain. `Sku` has
one constructor and it takes digits; `Money` takes minor units. Tax arithmetic
stayed in the domain, and the line is worth stating: turning `"1,50"` into `150`
is transcription, and turning a net `100` at 10% into a gross `110` is a rule
about what a price means. One of them changes if a source changes its mind about
punctuation; the other changes if the business does.

---

## Canonical model and architecture

The menu is flat. Composites reference components by SKU, and a component is an
ordinary item in the same list, so a product appears exactly once however many
recipes mention it. The three sources express composition in three shapes and a
canonical model that nested them would have had to pick one of those shapes —
and so would still have favoured one source. A reference plus a flat list is the
shape none of them has, which is why all three can reach it.

Ordering is part of the value rather than a presentation choice: items are
sorted by SKU inside `CanonicalMenu`, components inside `Item`. Byte-identical
output is therefore a property of the model instead of a favour done by the
serialiser.

```
Infrastructure   AlphaPosAdapter  BetaPosAdapter  GammaPosAdapter
                 SourceSystem     CanonicalJsonWriter  FlagReportWriter
                 Cli/NormaliseCommand                    ← bin/normalise
                        │
                        ▼
Application      NormaliseMenu      MappingResult
                 Port/SourceAdapter  Port/MalformedSource
                        │
                        ▼
Domain           Canonical/  Money Sku Item ComponentRef Promotion CanonicalMenu
                 Resolution/ Resolved Unresolved Flag FlagReason SourceName
                 Rule/       ComponentCascadeRule PromotionConflictRule
```

Dependencies point inward only, and that is [enforced by a
test](tests/Architecture/DependencyRuleTest.php) rather than drawn in a diagram:
it fails if any file under `src/Domain` names `Application` or `Infrastructure`,
or any file under `src/Application` names `Infrastructure`. Every architecture
document says something like this; the ones that are merely said drift within a
release or two, because the violation arrives as one convenient import in a
hurry and looks like every other import in review.

### Which ambiguity belongs where

**Source-specific ambiguity is detected in the adapter.** Only BetaPos knows
what `V99` means, so only its adapter can say that it means nothing here.

**Source-neutral ambiguity is detected in the domain.** A composite referencing
something the export does not describe, and a product carrying two promotions,
are the same facts whichever system sent the file. They live in `Domain/Rule/`,
and the reason is not tidiness: the dangling-component check used to exist in
one adapter out of three, and the consequence was that BetaPos published
canonical JSON referencing a SKU that was not in the menu — no flag, no error.
Three copies of one idea are three chances to write it differently, and one of
the three had not written it at all.

Adapters translate formats. They never decide business rules.

---

## Tests

```
docker compose run --rm app vendor/bin/phpunit
```

143 test methods. The ones that carry the claims:

| Test | What it proves |
|---|---|
| [Cross-source equivalence](tests/Infrastructure/CrossSourceEquivalenceTest.php) | `alpha.json`, `beta.xml` and `gamma.csv` each map to exactly `expected.json`, and to each other |
| [Promotion equivalence](tests/Infrastructure/PromotionEquivalenceTest.php) | A percentage off and an absolute net price reach the same canonical promotion |
| [Unresolvable tax basis](tests/Infrastructure/TaxBasisTest.php) | The item is absent and one `TAX_BASIS_UNKNOWN` flag names it; the other prices are undisturbed |
| [Dangling component](tests/Infrastructure/DanglingComponentTest.php) | The composite is absent, its resolvable components are present, one `COMPONENT_MISSING` flag |
| [Containment and cascade](tests/Infrastructure/ContainmentAndCascadeTest.php) | In one file: a withheld item does not block unrelated items, and a composite whose component was withheld is withheld too, with a flag of its own |
| [Conflicting promotions](tests/Infrastructure/PromotionConflictTest.php) | The item is absent and one `PROMOTION_CONFLICT` flag names it; a promotion on another item still applies |
| [Resolution cannot be bypassed](tests/Architecture/ResolutionCannotBeBypassedTest.php) | PHPStan rejects an adapter that forgets the `Unresolved` branch, and accepts the same adapter with it handled |
| [Dependency rule](tests/Architecture/DependencyRuleTest.php) | Nothing in `Domain` or `Application` reaches outward, and the scan reads files rather than passing vacuously |
| [Containment, without I/O](tests/Application/NormaliseMenuTest.php) | The use case contains failures, driven by a hand-written adapter returning decided answers — containment is a property of the use case, not of any parser |
| [The command line](tests/Infrastructure/BinNormaliseTest.php) | The exit code reaches the shell, and the menu and the report do not share a stream |

The equivalence test is the one that matters most: it is the only one that shows
three different representations converging on one truth.

Each test was confirmed to fail for a real reason before being kept, and in
several cases the reason was a defect it found. Removing the cascade rule makes
the containment-and-cascade tests fail — through the backstop in
`CanonicalMenu::of`, which is the cost that arrangement was accepted for.
Against the BetaPos adapter as it stood before that rule existed, the dangling
component is published as a SKU no consumer could resolve, silently; that is
what makes it a regression test rather than a description. Neutralising the
promotion-conflict rule turns eleven tests red across both sources and the
domain. Removing AlphaPos's comparison of repeated promotion lists makes a
nested copy that omits a promotion legal, whereupon the offer disappears from
the menu with nobody told. Collapsing exit `3` into `0` turns the spawned CLI
test red, and writing the flag report to stdout fails four of that file's five.

PHPStan runs at level max with five additional opt-in checks and **no baseline**,
and there is no `@var`, `@phpstan-ignore` or `@psalm-suppress` anywhere in
`src/` — which is also [asserted by a
test](tests/Architecture/ResolutionCannotBeBypassedTest.php), because a
guarantee that rests on static analysis is only as good as the absence of ways
to switch it off.

---

## Decisions

- [ADR-001 — Fail closed: withhold the item, not the field or the batch](docs/adr-001-withhold-the-item.md)
- [ADR-002 — One canonical model behind ports and adapters](docs/adr-002-one-canonical-model-behind-adapters.md)

---

## Deliberate limitations

No database, no HTTP API, no persistence of flags: one process and three layers.
No channel-specific payloads. No multi-currency — prices are minor units of one
currency, stated once at the top of the output. No framework, and no ORM,
because the types are the subject here and generated ones would be somebody
else's.

**No rounding policy.** Half-up, half-even and truncation are three defensible
answers that disagree in the last cent, and choosing one silently would put an
invented cent into a customer-facing price. The fixtures are chosen never to
need one, and an operation that would require rounding raises a `LogicException`
with a stack trace rather than an exit code — it means an assumption of this
codebase is wrong, not that a bad file arrived.

**One promotion per product.** A list of them is a price-list schedule, which is
out of scope, so a source stating two promotions the model cannot reduce to one
leaves the product unresolvable rather than published under whichever the file
listed first.

**Cycles are not detected.** A composite containing itself has all of its
components present, so neither the cascade rule nor the structural check in
`CanonicalMenu::of` has anything to say about it.

**A flag raised by a domain rule carries the canonical SKU and not the source's
spelling.** A rule runs after every source has been normalised into one
vocabulary, so a GammaPos composite is named `1310` where that system writes
`P-1310`. The cost is real and was accepted in exchange for the check existing
once for every source instead of in one adapter out of three; the flags adapters
raise still quote the identifier as the file wrote it.

These are omissions, not oversights. The scope was chosen so that the two
guarantees above could be implemented properly and tested, rather than sketched
across a wider surface.

---

## Stack

PHP 8.3 · Docker Compose · PHPUnit 11 · PHPStan at level max, no baseline
