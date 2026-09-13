<?php

declare(strict_types=1);

namespace CanonicalMapper\Infrastructure\Source\Alpha;

use CanonicalMapper\Application\Port\MalformedSource;
use CanonicalMapper\Application\Port\SourceAdapter;
use CanonicalMapper\Domain\Canonical\ComponentRef;
use CanonicalMapper\Domain\Canonical\Item;
use CanonicalMapper\Domain\Canonical\Money;
use CanonicalMapper\Domain\Canonical\Promotion;
use CanonicalMapper\Domain\Canonical\Sku;
use CanonicalMapper\Domain\InvariantViolated;
use CanonicalMapper\Domain\Resolution\Resolved;
use CanonicalMapper\Domain\Resolution\SourceName;
use CanonicalMapper\Domain\Resolution\Unresolved;
use CanonicalMapper\Domain\Rule\PromotionConflictRule;
use CanonicalMapper\Infrastructure\Source\SourceSystem;
use JsonException;

/**
 * Reads the AlphaPos JSON export.
 *
 * Everything this format does with punctuation lives here: the zero padding on a
 * PLU, and the dot between units and cents. The canonical model is handed the
 * result — a run of digits, and a number of minor units — and would be handed
 * exactly the same thing by a source that wrote neither.
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
 * carrying a field it has not been taught to read — a channel, an allergen list
 * — is refused rather than silently dropped: quietly ignoring a field is a guess
 * about whether it affected the price, and guessing is the one thing this mapper
 * does not do.
 *
 * Promotions are stated as a percentage off the product's own price over a range
 * of days, and a product may carry more than one. The canonical model carries
 * one, so what a product's list of them means is a question for the domain and
 * is asked there; this adapter reads them, checks that repeated definitions of a
 * product agree about them, and hands them over.
 *
 * A product is read into a definition rather than straight into an Item, which
 * is the one structural change promotions forced. Two copies of a product have
 * to be reconciled before anything is decided about either, and an Item cannot
 * be the thing they are reconciled as: it carries at most one promotion, so a
 * copy stating two could not be built into one in order to be compared.
 *
 * @phpstan-type Definition array{plu: string, sku: Sku, name: string, price: Money, components: list<ComponentRef>, promotions: list<Promotion>, context: string}
 */
final class AlphaPosAdapter implements SourceAdapter
{
    private const PRODUCT_KEYS = ['plu', 'name', 'price', 'components', 'promotions'];

    /**
     * A nested copy is a definition of the child in its own right, so it may
     * carry everything a definition carries, promotions included — and must,
     * when the product it copies has them. The alternative was to leave them off
     * a nested copy and let the product's own entry be the only place they
     * appear, which reads as tidier and hides a silent loss: whichever copy the
     * file happens to state first would win, and a promotion on the other would
     * disappear without anyone being told.
     */
    private const COMPONENT_KEYS = ['plu', 'name', 'price', 'quantity', 'promotions'];

    private const PROMOTION_KEYS = ['percent', 'from', 'to'];

    public function sourceName(): SourceName
    {
        return SourceSystem::Alpha->sourceName();
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

        return self::assemble($defined);
    }

    /**
     * Reconciliation first, resolution second.
     *
     * A product stated twice has to be one product before anything can be decided
     * about it. The two copies are checked against each other while they are
     * still statements the file made, and only what survives that is put to the
     * rule — resolving first would ask the same question of both copies and could
     * answer it differently for one product.
     *
     * @param array<string, Definition> $defined
     *
     * @return list<Resolved<Item>|Unresolved>
     *
     * @throws MalformedSource
     */
    private static function assemble(array $defined): array
    {
        $resolutions = [];

        foreach ($defined as $definition) {
            $promotion = PromotionConflictRule::apply(
                SourceSystem::Alpha->sourceName(),
                $definition['plu'],
                $definition['sku'],
                $definition['promotions'],
            );

            // The branch this adapter exists to make unforgettable, and until
            // promotions arrived it had nothing to put in it: the union in the
            // signature was a contract with no case behind it. PHPStan will not
            // accept a body that produces only half of the union it promises.
            if ($promotion instanceof Unresolved) {
                $resolutions[] = $promotion;

                continue;
            }

            $resolutions[] = Resolved::of(self::item($definition, $promotion->value));
        }

        return $resolutions;
    }

    /**
     * Where a broken domain invariant becomes a broken export, for the values the
     * canonical constructors are the ones to judge: a blank name, a recipe that
     * lists a child twice.
     *
     * @param Definition $definition
     *
     * @throws MalformedSource
     */
    private static function item(array $definition, ?Promotion $promotion): Item
    {
        $components = $definition['components'];

        try {
            return $components === []
                ? Item::simple($definition['sku'], $definition['name'], $definition['price'], $promotion)
                : Item::composite($definition['sku'], $definition['name'], $definition['price'], $components, $promotion);
        } catch (InvariantViolated $violation) {
            throw new MalformedSource(sprintf('%s: %s', $definition['context'], $violation->detail));
        }
    }

    /**
     * Reads one product, and is where a broken domain invariant becomes a broken
     * export.
     *
     * The canonical types refuse a quantity of zero, a promotion that ends before
     * it starts and a date that is not a day, and they refuse them in the
     * vocabulary of the model, which mentions no file and no path. Only this
     * layer knows which entry of which export was being read, so only this layer
     * can turn the one into the other. The helpers below translate the same
     * exception for the two values whose wording is worth keeping sharper than a
     * whole product.
     *
     * @param array<string, Definition> $defined
     *
     * @return array<string, Definition>
     *
     * @throws MalformedSource
     */
    private static function product(array $defined, mixed $entry, string $context): array
    {
        try {
            return self::readProduct($defined, $entry, $context);
        } catch (InvariantViolated $violation) {
            throw new MalformedSource(sprintf('%s: %s', $context, $violation->detail));
        }
    }

    /**
     * @param array<string, Definition> $defined
     *
     * @return array<string, Definition>
     *
     * @throws MalformedSource
     * @throws InvariantViolated
     */
    private static function readProduct(array $defined, mixed $entry, string $context): array
    {
        $product = self::object($entry, $context);
        self::rejectUnknownKeys($product, self::PRODUCT_KEYS, $context);

        $plu = self::text(self::field($product, 'plu', $context), $context . '.plu');
        $sku = self::sku($plu, $context . '.plu');
        $name = self::text(self::field($product, 'name', $context), $context . '.name');
        $price = self::money(
            self::text(self::field($product, 'price', $context), $context . '.price'),
            $context . '.price',
        );
        $promotions = self::promotions($product, $price, $context);

        if (!array_key_exists('components', $product)) {
            return self::define($defined, [
                'plu' => $plu,
                'sku' => $sku,
                'name' => $name,
                'price' => $price,
                'components' => [],
                'promotions' => $promotions,
                'context' => $context,
            ]);
        }

        $components = [];

        foreach (self::listOf($product['components'], $context . '.components') as $index => $entry) {
            $childContext = sprintf('%s.components[%d]', $context, $index);
            $child = self::object($entry, $childContext);
            self::rejectUnknownKeys($child, self::COMPONENT_KEYS, $childContext);

            $childPlu = self::text(self::field($child, 'plu', $childContext), $childContext . '.plu');
            $childSku = self::sku($childPlu, $childContext . '.plu');
            $childPrice = self::money(
                self::text(self::field($child, 'price', $childContext), $childContext . '.price'),
                $childContext . '.price',
            );

            // The nested copy is a definition of the child in its own right, so it
            // is emitted as an item as well as referenced. This is where the two
            // copies of a product meet, and where they are checked against each
            // other.
            $defined = self::define($defined, [
                'plu' => $childPlu,
                'sku' => $childSku,
                'name' => self::text(self::field($child, 'name', $childContext), $childContext . '.name'),
                'price' => $childPrice,
                'components' => [],
                'promotions' => self::promotions($child, $childPrice, $childContext),
                'context' => $childContext,
            ]);

            $components[] = ComponentRef::of(
                $childSku,
                self::integer(self::field($child, 'quantity', $childContext), $childContext . '.quantity'),
            );
        }

        if ($components === []) {
            throw new MalformedSource(sprintf('%s has a "components" list with nothing in it.', $context));
        }

        return self::define($defined, [
            'plu' => $plu,
            'sku' => $sku,
            'name' => $name,
            'price' => $price,
            'components' => $components,
            'promotions' => $promotions,
            'context' => $context,
        ]);
    }

    /**
     * AlphaPos states a promotion as a percentage off the product's own price
     * over an inclusive range of days, and a product may carry several.
     *
     * The percentage is turned into a price here and never reaches the canonical
     * model, which stores the result and not the mechanism (ADR-002). What that
     * costs is that "20% off" is not recoverable from the output; what it buys is
     * that this source and the one stating an absolute promotional price produce
     * the same bytes.
     *
     * An empty list is allowed and means what it says. Unlike "components", where
     * an empty list contradicts the key that introduced it, a product with no
     * promotions is the ordinary case and a file is entitled to say so
     * explicitly.
     *
     * @param array<string, mixed> $product
     *
     * @return list<Promotion>
     *
     * @throws MalformedSource
     * @throws InvariantViolated
     */
    private static function promotions(array $product, Money $price, string $context): array
    {
        if (!array_key_exists('promotions', $product)) {
            return [];
        }

        $promotions = [];

        foreach (self::listOf($product['promotions'], $context . '.promotions') as $index => $entry) {
            $promotionContext = sprintf('%s.promotions[%d]', $context, $index);
            $promotion = self::object($entry, $promotionContext);
            self::rejectUnknownKeys($promotion, self::PROMOTION_KEYS, $promotionContext);

            $promotions[] = Promotion::percentageOff(
                $price,
                self::percent(self::field($promotion, 'percent', $promotionContext), $promotionContext . '.percent'),
                self::text(self::field($promotion, 'from', $promotionContext), $promotionContext . '.from'),
                self::text(self::field($promotion, 'to', $promotionContext), $promotionContext . '.to'),
            );
        }

        return $promotions;
    }

    /**
     * A whole number of per cent, between none of it and all of it.
     *
     * The bounds are checked here rather than left to the domain because they are
     * what makes the discount arithmetic total: a negative percentage would raise
     * a price and one above a hundred would produce a negative one, and both are
     * refused before the canonical model is asked to hold the result.
     *
     * @return int<0, 100>
     *
     * @throws MalformedSource
     */
    private static function percent(mixed $value, string $context): int
    {
        $percent = self::integer($value, $context);

        if ($percent < 0 || $percent > 100) {
            throw new MalformedSource(sprintf('%s is %d, which is not a percentage.', $context, $percent));
        }

        return $percent;
    }

    /**
     * AlphaPos writes the PLU as a zero-padded JSON string: "001204".
     *
     * Stripping the padding is this adapter's whole contribution to the identity
     * of a product; what is left has to be a product identifier, and the domain
     * is what says whether it is. The padding used to be stripped inside Sku,
     * which meant the canonical model knew that one of three formats pads.
     *
     * @throws MalformedSource
     */
    private static function sku(string $plu, string $context): Sku
    {
        $digits = ltrim($plu, '0');

        // ltrim leaves nothing behind when every digit was a zero. Reading that as
        // SKU 0 would invent a product; it is a padded field nobody filled in, and
        // saying so is better than reporting the empty string the domain would see.
        if ($digits === '') {
            throw new MalformedSource(sprintf('%s "%s" is entirely zeroes.', $context, $plu));
        }

        try {
            return Sku::ofDigits($digits);
        } catch (InvariantViolated $violation) {
            throw new MalformedSource(sprintf('%s "%s": %s', $context, $plu, $violation->detail));
        }
    }

    /**
     * AlphaPos writes gross prices as a decimal string with a dot: "1.50".
     *
     * Exactly two decimals, no more and no fewer. "1.5" is rejected rather than
     * read as 1.50, and "1.505" rejected rather than rounded. Three decimals are
     * the only way a decimal string could require a rounding policy, so refusing
     * them is what makes the absence of one structural instead of a matter of
     * luck. And a source has exactly one spelling for a price: accepting a second
     * one would weaken what the byte-identical output proves, from "three formats
     * were reconciled" to "three formats were shrugged at".
     *
     * @throws MalformedSource
     */
    private static function money(string $value, string $context): Money
    {
        if (preg_match('/^\d{1,10}\.\d{2}$/', $value) !== 1) {
            throw new MalformedSource(sprintf(
                '%s "%s" is not an amount with exactly two decimals separated by ".".',
                $context,
                $value,
            ));
        }

        // The pattern above fixes the shape, so the last two characters are the
        // cents and everything before the separator is the units. Taken by
        // position rather than by splitting, which keeps both halves plain
        // strings instead of offsets that would then have to be proved to exist.
        $units = substr($value, 0, -3);
        $cents = substr($value, -2);

        try {
            return Money::fromMinorUnits((int) $units * 100 + (int) $cents);
        } catch (InvariantViolated $violation) {
            throw new MalformedSource(sprintf('%s "%s": %s', $context, $value, $violation->detail));
        }
    }

    /**
     * @param array<string, Definition> $defined
     * @param Definition $definition
     *
     * @return array<string, Definition>
     *
     * @throws MalformedSource
     */
    private static function define(array $defined, array $definition): array
    {
        $existing = $defined[$definition['sku']->value] ?? null;

        if ($existing !== null && !self::agree($existing, $definition)) {
            throw new MalformedSource(sprintf(
                '%s defines product %s differently from an earlier definition in the same export.',
                $definition['context'],
                $definition['sku']->value,
            ));
        }

        $defined[$definition['sku']->value] = $existing ?? $definition;

        return $defined;
    }

    /**
     * Whether two definitions of the same product say the same thing.
     *
     * Components are compared too, which is what refuses a composite that appears
     * nested inside another one: its nested copy carries no components and its own
     * definition does, so the two disagree and the export is refused rather than
     * half-read.
     *
     * Promotions are compared for a sharper reason. A nested copy that omits a
     * promotion its owner states is not a harmless abbreviation — whichever copy
     * came first would win, and an offer would go missing from the menu with
     * nobody told. Order counts as part of the agreement: the rule names the
     * first two promotions when it withholds, so two copies that list them in
     * different orders have not said the same thing.
     *
     * @param Definition $first
     * @param Definition $second
     */
    private static function agree(array $first, array $second): bool
    {
        if ($first['name'] !== $second['name'] || $first['price']->minorUnits !== $second['price']->minorUnits) {
            return false;
        }

        if (!self::sameOffers($first['promotions'], $second['promotions'])) {
            return false;
        }

        return self::recipe($first['components']) === self::recipe($second['components']);
    }

    /**
     * @param list<Promotion> $first
     * @param list<Promotion> $second
     */
    private static function sameOffers(array $first, array $second): bool
    {
        if (count($first) !== count($second)) {
            return false;
        }

        foreach ($first as $index => $promotion) {
            // The count above already settles this, and the offset is still asked
            // for rather than assumed: proving it to the analyser costs one line,
            // and an assumption about a list's shape is the kind this project
            // does not make on the reader's behalf.
            $counterpart = $second[$index] ?? null;

            if ($counterpart === null || !$promotion->equals($counterpart)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A recipe as a comparable value, in canonical component order.
     *
     * Sorted here rather than relied upon, because these are the file's own lists
     * rather than a canonical Item's: sorting is something Item does on the way
     * in, and these definitions have not been through one yet. Two copies of a
     * composite that list the same children in different orders state the same
     * recipe, and this is what says so.
     *
     * @param list<ComponentRef> $components
     *
     * @return list<array{string, int}>
     */
    private static function recipe(array $components): array
    {
        usort($components, static fn (ComponentRef $a, ComponentRef $b): int => Sku::compare($a->sku, $b->sku));

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
