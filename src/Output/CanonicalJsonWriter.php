<?php

declare(strict_types=1);

namespace CanonicalMapper\Output;

use CanonicalMapper\Canonical\CanonicalMenu;
use CanonicalMapper\Canonical\ComponentRef;
use CanonicalMapper\Canonical\Item;
use CanonicalMapper\Canonical\Promotion;
use JsonException;

/**
 * The canonical menu as bytes.
 *
 * This writer makes no decisions, and that is the design. Ordering was settled in
 * the model, so there is nothing here to sort; the key order is a constant list
 * written out longhand rather than whatever order an array happened to be built
 * in. What remains is encoding, which means two menus that are equal produce
 * identical bytes without anyone having to be careful.
 *
 * Prices are integers in minor units, not formatted strings. A consumer that
 * wants "€1.10" has a locale and this does not; emitting a formatted price would
 * be this project answering a presentation question it has no business answering,
 * and would put a decimal separator — the very thing the three sources disagree
 * about — back into the canonical output.
 */
final class CanonicalJsonWriter
{
    /**
     * Stated once, at the top, rather than repeated on every amount. Multi-currency
     * is out of scope, and a currency field on each price would be a promise this
     * project does not keep.
     */
    private const CURRENCY = 'EUR';

    /**
     * Pretty-printed because this output is read by people during a review as
     * often as it is parsed, and a byte comparison that fails is far easier to
     * read as a line diff than as one very long line.
     */
    private const FLAGS = JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR;

    /**
     * @throws JsonException
     */
    public function write(CanonicalMenu $menu): string
    {
        $document = [
            'currency' => self::CURRENCY,
            'items' => array_map(self::item(...), $menu->items),
        ];

        // A trailing newline, so the file is a well-formed text file and a shell
        // redirect of stdout produces something git and diff are happy with.
        return json_encode($document, self::FLAGS) . "\n";
    }

    /**
     * @return array{sku: string, name: string, price: int, components: list<array{sku: string, quantity: int}>, promotion: array{price: int, from: string, to: string}|null}
     */
    private static function item(Item $item): array
    {
        // Every key is always present, including the empty ones. An item without
        // components emits "components": [] rather than omitting the key: a
        // consumer should not have to distinguish "no components" from "this
        // exporter does not mention components", and the two sources that express
        // composites differently would otherwise produce different shapes for the
        // same product.
        return [
            'sku' => $item->sku->value,
            'name' => $item->name,
            'price' => $item->price->minorUnits,
            'components' => array_map(self::component(...), $item->components),
            'promotion' => $item->promotion === null ? null : self::promotion($item->promotion),
        ];
    }

    /**
     * @return array{sku: string, quantity: int}
     */
    private static function component(ComponentRef $component): array
    {
        return [
            'sku' => $component->sku->value,
            'quantity' => $component->quantity,
        ];
    }

    /**
     * @return array{price: int, from: string, to: string}
     */
    private static function promotion(Promotion $promotion): array
    {
        return [
            'price' => $promotion->price->minorUnits,
            'from' => $promotion->from->format('Y-m-d'),
            'to' => $promotion->to->format('Y-m-d'),
        ];
    }
}
