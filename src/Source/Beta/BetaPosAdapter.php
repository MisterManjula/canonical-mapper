<?php

declare(strict_types=1);

namespace CanonicalMapper\Source\Beta;

use CanonicalMapper\Canonical\ComponentRef;
use CanonicalMapper\Canonical\Item;
use CanonicalMapper\Canonical\Money;
use CanonicalMapper\Canonical\Sku;
use CanonicalMapper\MalformedSource;
use CanonicalMapper\Resolution\Flag;
use CanonicalMapper\Resolution\Resolved;
use CanonicalMapper\Resolution\SourceSystem;
use CanonicalMapper\Resolution\Unresolved;
use CanonicalMapper\Source\SourceAdapter;
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
 * Composites are stated as a list of component elements on the parent, and a
 * component carries nothing but a code and a quantity. Unlike the nested source,
 * there is no repeated definition to reconcile — and no definition either, so a
 * component naming a product the file does not contain is a question rather than
 * a contradiction.
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

    public function system(): SourceSystem
    {
        return SourceSystem::Beta;
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

        foreach (self::children($root, 'product', 'catalogue') as $product) {
            $resolutions[] = self::product($product);
        }

        return $resolutions;
    }

    /**
     * @return Resolved<Item>|Unresolved
     *
     * @throws MalformedSource
     */
    private static function product(DOMElement $element): Resolved|Unresolved
    {
        $code = self::attribute($element, 'code', 'product');
        $context = sprintf('product code="%s"', $code);

        self::rejectUnknownAttributes($element, self::PRODUCT_ATTRIBUTES, $context);

        $sku = Sku::fromAttribute($code);
        $name = self::attribute($element, 'name', $context);

        $components = [];

        foreach (self::children($element, 'component', $context) as $component) {
            $componentContext = sprintf('%s component', $context);
            self::rejectUnknownAttributes($component, self::COMPONENT_ATTRIBUTES, $componentContext);

            $components[] = ComponentRef::of(
                Sku::fromAttribute(self::attribute($component, 'code', $componentContext)),
                self::wholeNumber(self::attribute($component, 'qty', $componentContext), $componentContext . ' qty'),
            );
        }

        // Everything structural is read before anything is resolved, so that a
        // malformed file is reported as one whatever its tax codes happen to say.
        // Resolving first would make the difference between "this export is
        // broken" and "this item needs a decision" depend on which of the two the
        // parser met first, and those two answers go to different people.
        $price = self::price($element, $code, $sku, $context);

        // The one branch this adapter exists to make unforgettable. Handling it is
        // not a courtesy to the reader: the method promises a union, and PHPStan
        // will not accept a body that produces only half of it.
        if ($price instanceof Unresolved) {
            return $price;
        }

        if ($components === []) {
            return Resolved::of(Item::simple($sku, $name, $price->value));
        }

        return Resolved::of(Item::composite($sku, $name, $price->value, $components));
    }

    /**
     * @return Resolved<Money>|Unresolved
     *
     * @throws MalformedSource
     */
    private static function price(DOMElement $element, string $code, Sku $sku, string $context): Resolved|Unresolved
    {
        $vat = self::attribute($element, 'vat', $context);

        // Read before the rate is looked up, for the reason given in product():
        // a net price that is not a number is a broken export either way.
        $net = self::wholeNumber(self::attribute($element, 'net', $context), $context . ' net');

        $rate = VatCode::rateFor($vat);

        if ($rate === null) {
            // Withheld rather than refused, and withheld rather than defaulted.
            // The file is fine; the question is what this code means, and it is
            // one a person can answer in BetaPos in under a minute. Defaulting to
            // the commoner rate would publish a price nobody chose.
            return Unresolved::because(Flag::taxBasisUnknown(SourceSystem::Beta, $code, $sku, $vat));
        }

        return Resolved::of(Money::fromNetMinorUnitsAndVatPercent($net, $rate));
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
     * @return list<DOMElement>
     *
     * @throws MalformedSource
     */
    private static function children(DOMElement $parent, string $name, string $context): array
    {
        $found = [];

        foreach ($parent->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            if ($node->tagName !== $name) {
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
