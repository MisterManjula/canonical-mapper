<?php

declare(strict_types=1);

namespace CanonicalMapper\Infrastructure\Source\Alpha;

use CanonicalMapper\Application\Port\MalformedSource;
use CanonicalMapper\Application\Port\SourceAdapter;
use CanonicalMapper\Domain\Canonical\ComponentRef;
use CanonicalMapper\Domain\Canonical\Item;
use CanonicalMapper\Domain\Canonical\Money;
use CanonicalMapper\Domain\Canonical\Sku;
use CanonicalMapper\Domain\Resolution\Resolved;
use CanonicalMapper\Domain\Resolution\SourceSystem;
use CanonicalMapper\Domain\Resolution\Unresolved;
use JsonException;

/**
 * Reads the AlphaPos JSON export.
 *
 * AlphaPos expresses a composite by nesting its children's full definitions
 * inside the parent, so a product that is both sold on its own and used in a set
 * is written out twice. The canonical menu is flat, so this adapter emits every
 * product once and checks that the repeated definitions agree. A disagreement is
 * a MalformedSource rather than a withheld item: the file has stated two prices
 * for one product without any indication of which is current, so there is no
 * question to put to a human that the export has not already answered twice.
 *
 * Nesting is read one level deep. A child that carries children of its own is
 * refused, because a composite defined only as somebody else's component has no
 * definition of its own to reconcile against.
 *
 * Every key in a product object must be one this adapter knows. An export
 * carrying a field it has not been taught to read — a promotion, a channel, an
 * allergen list — is refused rather than silently dropped: quietly ignoring a
 * field is a guess about whether it affected the price, and guessing is the one
 * thing this mapper does not do.
 */
final class AlphaPosAdapter implements SourceAdapter
{
    private const PRODUCT_KEYS = ['plu', 'name', 'price', 'components'];

    private const COMPONENT_KEYS = ['plu', 'name', 'price', 'quantity'];

    public function system(): SourceSystem
    {
        return SourceSystem::Alpha;
    }

    /**
     * @return list<Resolved<Item>|Unresolved>
     *
     * @throws MalformedSource
     */
    public function read(string $contents): array
    {
        $document = self::object(self::decode($contents), 'The AlphaPos export');
        $menu = self::object(self::field($document, 'menu', 'The AlphaPos export'), 'menu');
        $products = self::listOf(self::field($menu, 'products', 'menu'), 'menu.products');

        $defined = [];

        foreach ($products as $index => $entry) {
            $defined = self::product($defined, $entry, sprintf('menu.products[%d]', $index));
        }

        $resolutions = [];

        // Nothing in an AlphaPos export is withheld yet: the one ambiguity this
        // source can express is a promotion conflict, and a promotion is currently
        // a key this adapter refuses rather than reads. The signature still names
        // the union, because it is the contract every adapter is held to and
        // narrowing it here would have to be widened again later.
        foreach ($defined as $item) {
            $resolutions[] = Resolved::of($item);
        }

        return $resolutions;
    }

    /**
     * @param array<string, Item> $defined
     *
     * @return array<string, Item>
     *
     * @throws MalformedSource
     */
    private static function product(array $defined, mixed $entry, string $context): array
    {
        $product = self::object($entry, $context);
        self::rejectUnknownKeys($product, self::PRODUCT_KEYS, $context);

        $sku = Sku::fromPaddedString(self::text(self::field($product, 'plu', $context), $context . '.plu'));
        $name = self::text(self::field($product, 'name', $context), $context . '.name');
        $price = Money::fromDecimalString(self::text(self::field($product, 'price', $context), $context . '.price'));

        if (!array_key_exists('components', $product)) {
            return self::define($defined, Item::simple($sku, $name, $price), $context);
        }

        $components = [];

        foreach (self::listOf($product['components'], $context . '.components') as $index => $entry) {
            $childContext = sprintf('%s.components[%d]', $context, $index);
            $child = self::object($entry, $childContext);
            self::rejectUnknownKeys($child, self::COMPONENT_KEYS, $childContext);

            $childSku = Sku::fromPaddedString(self::text(self::field($child, 'plu', $childContext), $childContext . '.plu'));

            // The nested copy is a definition of the child in its own right, so it
            // is emitted as an item as well as referenced. This is where the two
            // copies of a product meet, and where they are checked against each
            // other.
            $defined = self::define($defined, Item::simple(
                $childSku,
                self::text(self::field($child, 'name', $childContext), $childContext . '.name'),
                Money::fromDecimalString(self::text(self::field($child, 'price', $childContext), $childContext . '.price')),
            ), $childContext);

            $components[] = ComponentRef::of(
                $childSku,
                self::integer(self::field($child, 'quantity', $childContext), $childContext . '.quantity'),
            );
        }

        if ($components === []) {
            throw new MalformedSource(sprintf('%s has a "components" list with nothing in it.', $context));
        }

        return self::define($defined, Item::composite($sku, $name, $price, $components), $context);
    }

    /**
     * @param array<string, Item> $defined
     *
     * @return array<string, Item>
     *
     * @throws MalformedSource
     */
    private static function define(array $defined, Item $item, string $context): array
    {
        $existing = $defined[$item->sku->value] ?? null;

        if ($existing !== null && !self::agree($existing, $item)) {
            throw new MalformedSource(sprintf(
                '%s defines product %s differently from an earlier definition in the same export.',
                $context,
                $item->sku->value,
            ));
        }

        $defined[$item->sku->value] = $existing ?? $item;

        return $defined;
    }

    /**
     * Whether two definitions of the same product say the same thing.
     *
     * Components are compared too, which is what refuses a composite that appears
     * nested inside another one: its nested copy carries no components and its own
     * definition does, so the two disagree and the export is refused rather than
     * half-read.
     */
    private static function agree(Item $first, Item $second): bool
    {
        if ($first->name !== $second->name || $first->price->minorUnits !== $second->price->minorUnits) {
            return false;
        }

        // Components are sorted by SKU inside the item, so comparing the two lists
        // element by element is comparing recipes rather than orderings.
        return self::recipe($first->components) === self::recipe($second->components);
    }

    /**
     * @param list<ComponentRef> $components
     *
     * @return list<array{string, int}>
     */
    private static function recipe(array $components): array
    {
        return array_map(
            static fn (ComponentRef $component): array => [$component->sku->value, $component->quantity],
            $components,
        );
    }

    /**
     * @throws MalformedSource
     */
    private static function decode(string $contents): mixed
    {
        try {
            return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MalformedSource(sprintf('The AlphaPos export is not valid JSON: %s.', $exception->getMessage()));
        }
    }

    /**
     * json_decode returns mixed, and at level max every step away from it has to
     * be justified. These five helpers are that justification: each one narrows by
     * one step and names the path it failed at, so a broken export produces
     * "menu.products[2].price is not a string" rather than a type error thrown
     * from somewhere in the canonical model.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedSource
     */
    private static function object(mixed $value, string $context): array
    {
        if (!is_array($value)) {
            throw new MalformedSource(sprintf('%s is not an object.', $context));
        }

        $object = [];

        foreach ($value as $key => $entry) {
            if (!is_string($key)) {
                throw new MalformedSource(sprintf('%s is a list where an object was expected.', $context));
            }

            $object[$key] = $entry;
        }

        return $object;
    }

    /**
     * @return list<mixed>
     *
     * @throws MalformedSource
     */
    private static function listOf(mixed $value, string $context): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new MalformedSource(sprintf('%s is not a list.', $context));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $object
     *
     * @throws MalformedSource
     */
    private static function field(array $object, string $key, string $context): mixed
    {
        if (!array_key_exists($key, $object)) {
            throw new MalformedSource(sprintf('%s has no "%s".', $context, $key));
        }

        return $object[$key];
    }

    /**
     * @throws MalformedSource
     */
    private static function text(mixed $value, string $context): string
    {
        if (!is_string($value)) {
            throw new MalformedSource(sprintf('%s is not a string.', $context));
        }

        return $value;
    }

    /**
     * @throws MalformedSource
     */
    private static function integer(mixed $value, string $context): int
    {
        // Deliberately not is_numeric: "1" is how a different export would write a
        // quantity, and accepting both spellings here is the kind of tolerance
        // that makes the equivalence test prove less than it claims.
        if (!is_int($value)) {
            throw new MalformedSource(sprintf('%s is not a whole number.', $context));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $object
     * @param list<string> $allowed
     *
     * @throws MalformedSource
     */
    private static function rejectUnknownKeys(array $object, array $allowed, string $context): void
    {
        $unknown = array_diff(array_keys($object), $allowed);

        if ($unknown !== []) {
            throw new MalformedSource(sprintf(
                '%s carries %s, which this adapter has not been taught to read; '
                . 'a field that might affect the price cannot be ignored.',
                $context,
                implode(', ', array_map(static fn (string $key): string => '"' . $key . '"', $unknown)),
            ));
        }
    }
}
