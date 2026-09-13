<?php

declare(strict_types=1);

namespace CanonicalMapper\Infrastructure\Source\Beta;

use CanonicalMapper\Application\Port\MalformedSource;
use CanonicalMapper\Application\Port\SourceAdapter;
use CanonicalMapper\Domain\Canonical\ComponentRef;
use CanonicalMapper\Domain\Canonical\Item;
use CanonicalMapper\Domain\Canonical\Money;
use CanonicalMapper\Domain\Canonical\Promotion;
use CanonicalMapper\Domain\Canonical\Sku;
use CanonicalMapper\Domain\InvariantViolated;
use CanonicalMapper\Domain\Resolution\Flag;
use CanonicalMapper\Domain\Resolution\Resolved;
use CanonicalMapper\Domain\Resolution\SourceName;
use CanonicalMapper\Domain\Resolution\Unresolved;
use CanonicalMapper\Domain\Rule\PromotionConflictRule;
use CanonicalMapper\Infrastructure\Source\SourceSystem;
use DOMDocument;
use DOMElement;
use LibXMLError;

/**
 * Reads the BetaPos XML export.
 *
 * BetaPos is the source that does not store the number a customer sees. It keeps
 * net prices in minor units next to a tax code, so the gross price the canonical
 * model publishes has to be computed, and computing it needs a fact that lives
 * outside the file: what the code means. That is where this source can fail to
 * resolve while remaining perfectly well formed, and it is the reason the
 * Resolution type exists.
 *
 * This format's contribution to a SKU is that it makes none: a code attribute is
 * already the digits the canonical model wants. The adapter still hands it to
 * the domain rather than trusting it, and a padded code is refused here where it
 * used to be quietly unpadded — BetaPos writes code="1204" and never code="0099",
 * so the second is an export that is not what it claims to be.
 *
 * Composites are stated as a list of component elements on the parent, and a
 * component carries nothing but a code and a quantity. Unlike the nested source,
 * there is no repeated definition to reconcile — and no definition either, so a
 * component naming a product the file does not contain is a question rather than
 * a contradiction.
 *
 * Promotions are stated the same way, as elements on the product, and they state
 * a promotional price rather than a discount: this source keeps net amounts and
 * a tax code, so a promotion here is one more net amount under the same code.
 * The canonical model stores neither mechanism — it stores the gross price that
 * results — which is why an export saying "160 net, V10, for ten days" and one
 * saying "20% off" produce the same bytes.
 *
 * DOM rather than SimpleXML throughout. SimpleXML returns an object, or null, or
 * false from nearly every call and its property access cannot be typed, which at
 * level max means every value arrives as mixed and every guarantee about it has
 * to be asserted rather than checked. DOM's signatures are honest, and the
 * nullability it forces into the open here is real nullability in the data.
 */
final class BetaPosAdapter implements SourceAdapter
{
    private const PRODUCT_ATTRIBUTES = ['code', 'name', 'net', 'vat'];

    private const COMPONENT_ATTRIBUTES = ['code', 'qty'];

    private const PROMOTION_ATTRIBUTES = ['price', 'from', 'to'];

    /**
     * Both kinds of child a product may have. They are read in document order
     * from one pass rather than by two searches, so an element belonging to
     * neither is refused where it stands.
     */
    private const PRODUCT_CHILDREN = ['component', 'promotion'];

    public function sourceName(): SourceName
    {
        return SourceSystem::Beta->sourceName();
    }

    /**
     * @return list<Resolved<Item>|Unresolved>
     *
     * @throws MalformedSource
     */
    public function read(string $contents): array
    {
        $root = self::load($contents)->documentElement;

        if ($root === null || $root->tagName !== 'catalogue') {
            throw new MalformedSource('The BetaPos export is not a <catalogue> document.');
        }

        $resolutions = [];

        foreach (self::children($root, ['product'], 'catalogue') as $product) {
            $resolutions[] = self::product($product);
        }

        return $resolutions;
    }

    /**
     * Reads one product, and is where a broken domain invariant becomes a broken
     * export.
     *
     * The canonical types refuse a blank name, a quantity of zero and a recipe
     * that lists a child twice, in wording that mentions no file because the
     * model has never seen one. Naming the product element that carried the
     * offending value is something only this layer can do.
     *
     * @return Resolved<Item>|Unresolved
     *
     * @throws MalformedSource
     */
    private static function product(DOMElement $element): Resolved|Unresolved
    {
        $context = sprintf('product code="%s"', $element->getAttribute('code'));

        try {
            return self::readProduct($element, $context);
        } catch (InvariantViolated $violation) {
            throw new MalformedSource(sprintf('%s: %s', $context, $violation->detail));
        }
    }

    /**
     * @return Resolved<Item>|Unresolved
     *
     * @throws MalformedSource
     * @throws InvariantViolated
     */
    private static function readProduct(DOMElement $element, string $context): Resolved|Unresolved
    {
        $code = self::attribute($element, 'code', 'product');

        self::rejectUnknownAttributes($element, self::PRODUCT_ATTRIBUTES, $context);

        $sku = self::sku($code, $context);
        $name = self::attribute($element, 'name', $context);

        // Read before the rate is looked up, for the reason given below: a net
        // price that is not a number is a broken export whatever its tax code
        // says.
        $vat = self::attribute($element, 'vat', $context);
        $net = self::wholeNumber(self::attribute($element, 'net', $context), $context . ' net');

        $components = [];
        $offers = [];

        foreach (self::children($element, self::PRODUCT_CHILDREN, $context) as $child) {
            if ($child->tagName === 'component') {
                $components[] = self::component($child, sprintf('%s component', $context));

                continue;
            }

            $offers[] = self::offer($child, sprintf('%s promotion', $context));
        }

        // Everything structural is read before anything is resolved, so that a
        // malformed file is reported as one whatever its tax codes happen to say.
        // Resolving first would make the difference between "this export is
        // broken" and "this item needs a decision" depend on which of the two the
        // parser met first, and those two answers go to different people.
        $rate = VatCode::rateFor($vat);

        // The one branch this adapter exists to make unforgettable. Handling it is
        // not a courtesy to the reader: the method promises a union, and PHPStan
        // will not accept a body that produces only half of it.
        //
        // Withheld rather than refused, and withheld rather than defaulted. The
        // file is fine; the question is what this code means, and it is one a
        // person can answer in BetaPos in under a minute. Defaulting to the
        // commoner rate would publish a price nobody chose.
        if ($rate === null) {
            return Unresolved::because(Flag::taxBasisUnknown(SourceSystem::Beta->sourceName(), $code, $sku, $vat));
        }

        $price = Money::fromNetMinorUnitsAndVatPercent($net, $rate);

        $promotion = PromotionConflictRule::apply(
            SourceSystem::Beta->sourceName(),
            $code,
            $sku,
            self::promotions($offers, $rate),
        );

        if ($promotion instanceof Unresolved) {
            return $promotion;
        }

        if ($components === []) {
            return Resolved::of(Item::simple($sku, $name, $price, $promotion->value));
        }

        return Resolved::of(Item::composite($sku, $name, $price, $components, $promotion->value));
    }

    /**
     * @throws MalformedSource
     * @throws InvariantViolated
     */
    private static function component(DOMElement $element, string $context): ComponentRef
    {
        self::rejectUnknownAttributes($element, self::COMPONENT_ATTRIBUTES, $context);

        return ComponentRef::of(
            self::sku(self::attribute($element, 'code', $context), $context),
            self::wholeNumber(self::attribute($element, 'qty', $context), $context . ' qty'),
        );
    }

    /**
     * One promotion as the file states it, before anything has been computed.
     *
     * BetaPos states a promotional price rather than a discount, and states it
     * the way it states every other price: net, in minor units, under the
     * product's own tax code. A gross amount here would be the one number in the
     * file a customer could read, which is not what this source is.
     *
     * @return array{net: int, from: string, to: string}
     *
     * @throws MalformedSource
     */
    private static function offer(DOMElement $element, string $context): array
    {
        self::rejectUnknownAttributes($element, self::PROMOTION_ATTRIBUTES, $context);

        return [
            'net' => self::wholeNumber(self::attribute($element, 'price', $context), $context . ' price'),
            'from' => self::attribute($element, 'from', $context),
            'to' => self::attribute($element, 'to', $context),
        ];
    }

    /**
     * The promotions at gross, in the order the file stated them.
     *
     * This runs after the rate has been resolved and not before, which leaves one
     * gap worth naming rather than hiding: a promotion whose date is not a real
     * day, on a product whose tax code this mapper does not know, is withheld
     * under the tax-code flag instead of refusing the export. The dates are
     * checked by the canonical type, a promotion cannot be built without a price,
     * and the price is the thing that is unknown — so the alternative was to
     * invent an amount purely to validate a date against, which is a worse thing
     * to have in the code than this paragraph. Nothing wrong is published either
     * way: the item is withheld in both readings, and only the wording of the
     * report differs.
     *
     * @param list<array{net: int, from: string, to: string}> $offers
     * @param int<0, 100> $rate
     *
     * @return list<Promotion>
     *
     * @throws InvariantViolated
     */
    private static function promotions(array $offers, int $rate): array
    {
        $promotions = [];

        foreach ($offers as $offer) {
            $promotions[] = Promotion::atPrice(
                Money::fromNetMinorUnitsAndVatPercent($offer['net'], $rate),
                $offer['from'],
                $offer['to'],
            );
        }

        return $promotions;
    }

    /**
     * BetaPos writes the PLU as an XML attribute with no padding: code="1204".
     *
     * There is nothing to strip, which is the point: the same canonical
     * constructor serves a format that pads and a format that does not, and
     * neither of them is named in it.
     *
     * @throws MalformedSource
     */
    private static function sku(string $code, string $context): Sku
    {
        try {
            return Sku::ofDigits($code);
        } catch (InvariantViolated $violation) {
            throw new MalformedSource(sprintf('%s: %s', $context, $violation->detail));
        }
    }

    /**
     * @throws MalformedSource
     */
    private static function load(string $contents): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new DOMDocument();

        // LIBXML_NONET, because a menu import has no business fetching anything.
        // Entity substitution is off by default in this version of PHP and is not
        // switched on here: an export from a till is untrusted input, and turning
        // "read a price list" into "read a file off the server" is a short trip.
        $loaded = $document->loadXML($contents, LIBXML_NONET);

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            throw new MalformedSource(sprintf('The BetaPos export is not valid XML: %s.', self::firstError($errors)));
        }

        return $document;
    }

    /**
     * @param list<LibXMLError> $errors
     */
    private static function firstError(array $errors): string
    {
        $first = reset($errors);

        if ($first === false) {
            return 'the parser gave no reason';
        }

        return sprintf('line %d, %s', $first->line, trim($first->message));
    }

    /**
     * The element children of a node, with anything unexpected refused.
     *
     * Whitespace between elements arrives as text nodes and is skipped; an element
     * this adapter does not know is not skipped. A silently ignored element is a
     * guess that it did not affect the price, which is the guess this project
     * exists to avoid making.
     *
     * A list of names rather than one, since a product has two kinds of child.
     * They come back in document order and mixed together, which is how the file
     * writes them and is the caller's to sort out — the alternative was two
     * passes with two names, and an element belonging to neither would then be
     * refused twice or, more likely, once and by accident.
     *
     * @param non-empty-list<string> $names
     *
     * @return list<DOMElement>
     *
     * @throws MalformedSource
     */
    private static function children(DOMElement $parent, array $names, string $context): array
    {
        $found = [];

        foreach ($parent->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            if (!in_array($node->tagName, $names, true)) {
                throw new MalformedSource(sprintf(
                    '%s contains a <%s>, which this adapter has not been taught to read.',
                    $context,
                    $node->tagName,
                ));
            }

            $found[] = $node;
        }

        return $found;
    }

    /**
     * @throws MalformedSource
     */
    private static function attribute(DOMElement $element, string $name, string $context): string
    {
        // getAttribute returns an empty string for an attribute that is absent and
        // for one that is present and empty, so the question has to be asked
        // separately. An empty name or code is caught downstream by the canonical
        // constructors rather than guessed at here.
        if (!$element->hasAttribute($name)) {
            throw new MalformedSource(sprintf('%s has no %s attribute.', $context, $name));
        }

        return $element->getAttribute($name);
    }

    /**
     * @param list<string> $allowed
     *
     * @throws MalformedSource
     */
    private static function rejectUnknownAttributes(DOMElement $element, array $allowed, string $context): void
    {
        $attributes = $element->attributes;
        $unknown = [];

        foreach ($attributes as $attribute) {
            if (!in_array($attribute->nodeName, $allowed, true)) {
                $unknown[] = $attribute->nodeName;
            }
        }

        if ($unknown !== []) {
            throw new MalformedSource(sprintf(
                '%s carries %s, which this adapter has not been taught to read; '
                . 'an attribute that might affect the price cannot be ignored.',
                $context,
                implode(', ', $unknown),
            ));
        }
    }

    /**
     * @throws MalformedSource
     */
    private static function wholeNumber(string $value, string $context): int
    {
        // Digits only. "1.0" and " 1" and "1e2" are all things a cast would accept
        // and a till never writes, and accepting a second spelling for a number
        // weakens what the cross-source comparison proves.
        if (preg_match('/^\d{1,12}$/', $value) !== 1) {
            throw new MalformedSource(sprintf('%s is "%s", which is not a whole number.', $context, $value));
        }

        return (int) $value;
    }
}
