# Canonical mapper

> **Work in progress.** The guarantees below describe the target design and are
> being implemented test by test.

Three point-of-sale systems describe the same menu in three incompatible formats.
This mapper turns each of them into a single canonical model, built around two
guarantees.

**Every source produces the same canonical output.** The same assortment,
expressed in AlphaPos (JSON), BetaPos (XML) and GammaPos (CSV), maps to
byte-identical canonical JSON. This is asserted by a test rather than assumed:
equivalence across sources is the property every downstream consumer depends on.

**Ambiguous data is withheld, never guessed.** When a value cannot be resolved
with confidence — a net price with an unknown VAT code, a composite item that
references a missing component, two promotions that overlap with different
discounts — the item is excluded from the output and reported with the source,
the product and the reason. A missing item is a support ticket; a wrong price
reaching a customer is a much more expensive problem.
