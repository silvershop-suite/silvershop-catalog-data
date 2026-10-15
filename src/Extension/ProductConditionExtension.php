<?php

declare(strict_types=1);

namespace SilverShop\CatalogData\Extension;

use SilverShop\CatalogData\Condition;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;

/**
 * Adds the catalogue {@code Condition} (new / refurbished / used) to {@link \SilverShop\Page\Product}.
 *
 * Condition is a catalogue/marketing attribute shared by the feed ({@code g:condition}), SEO JSON-LD
 * ({@code itemCondition}) and tracking, so it lives here alongside the mapper rather than in the pure
 * barcode-focused {@code silvershop/product-identity}. {@link \SilverShop\CatalogData\CatalogProductDataFactory}
 * reads it (variations inherit their product's condition); the stored default is {@code new}.
 *
 * @extends Extension<\SilverShop\Page\Product>
 * @property string $Condition
 */
class ProductConditionExtension extends Extension
{
    private static array $db = [
        'Condition' => "Enum('new,refurbished,used','new')",
    ];

    /**
     * Place the Condition dropdown on the main Content tab, next to the other catalogue-identity fields.
     *
     * {@link \SilverShop\Page\Product} auto-scaffolds extension {@code $db} fields into the edit form, so an Enum
     * {@code Condition} dropdown is already present when this hook runs. Removing it first and re-inserting it once
     * guarantees {@link \SilverStripe\Forms\FieldList::getDataFields()} can never see the field twice (the
     * "appears twice" fatal). {@code insertAfter()} drops it after the Model code; if that field is ever absent it
     * falls back to appending to the Main tab.
     */
    protected function updateCMSFields(FieldList $fields): void
    {
        $fields->removeByName('Condition');

        $field = DropdownField::create(
            'Condition',
            _t(self::class . '.ConditionLabel', 'Condition'),
            $this->conditionSource()
        )->setDescription(_t(
            self::class . '.ConditionDesc',
            'Item condition reported to shopping channels and rich results. Most catalogue items are new.'
        ));

        if (!$fields->insertAfter('Model', $field)) {
            $fields->addFieldToTab('Root.Main', $field);
        }
    }

    /**
     * Localised dropdown source keyed by the channel token.
     *
     * @return array<string, string>
     */
    private function conditionSource(): array
    {
        $source = [];
        foreach (Condition::cases() as $case) {
            $source[$case->value] = _t($case->labelKey(), ucfirst($case->value));
        }

        return $source;
    }
}
