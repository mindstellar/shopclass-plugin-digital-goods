<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\digitalgoods;

/**
 * Charging a seller for attaching files, where the site is set up to charge.
 *
 * Core's billing is seller-side: a seller holds credits and spends them on features,
 * per account or per listing. So the thing sold here is the ability to attach files to
 * one listing — an item upgrade, alongside bump and highlight — rather than a buyer-side
 * purchase, which core has no model for.
 *
 * Every entry point is guarded on the API being there. Billing arrived in Shopclass
 * 6.2.0 and this plugin supports 6.1.0, so on an older install none of it registers, the
 * setting cannot be turned on, and attaching files stays free. That is why the plugin
 * does not simply declare `Requires Shopclass: 6.2.0`: the feature is worth having where
 * it exists without making the rest of the plugin unavailable where it does not.
 */
class Billing
{
    /** The feature id sellers spend credits on. */
    public const FEATURE = 'digital_goods.attachments';

    /**
     * Whether this install has the billing subsystem at all.
     *
     * @return bool
     */
    public static function available()
    {
        return function_exists('osc_register_billing_feature')
            && class_exists('mindstellar\billing\ItemUpgrades');
    }

    /**
     * Whether attaching files has to be paid for.
     *
     * @return bool
     */
    public static function required()
    {
        return self::available()
            && (int)osc_get_preference('require_purchase', Plugin::PREF_SECTION) === 1;
    }

    /**
     * Credits one listing's attachment allowance costs.
     *
     * @return int
     */
    public static function price()
    {
        $value = (int)osc_get_preference('price_credits', Plugin::PREF_SECTION);

        return max(1, $value ?: 5);
    }

    /**
     * Register the feature so it appears wherever core lists what credits buy.
     *
     * Registered whenever billing exists, not only when the charge is switched on: a
     * feature that appears and disappears from the catalogue as a preference is toggled
     * would strand any credits already spent against it.
     *
     * @return void
     */
    public static function register()
    {
        if (!self::available()) {
            return;
        }

        osc_register_billing_feature(self::FEATURE, array(
            'label'       => __('Downloadable files', 'digital-goods'),
            'description' => __('Attach downloadable files to this listing.', 'digital-goods'),
            'consumes'    => \mindstellar\billing\Feature::CONSUMES_QUANTITY,
            'scope'       => \mindstellar\billing\Feature::SCOPE_ITEM,
            'price'       => static function () {
                return self::price();
            },
            'apply'       => static function (int $userId, array $ctx): bool {
                $itemId = (int)($ctx['itemId'] ?? 0);
                if ($itemId <= 0) {
                    return false;
                }

                // No expiry: what was bought is the right to have attached these files,
                // and taking the downloads away later from a listing that is still up
                // would break a sale that already happened.
                return \mindstellar\billing\ItemUpgrades::grant($itemId, self::FEATURE);
            },
        ));
    }

    /**
     * Whether this listing may carry files.
     *
     * True when nothing is being charged, so the check can be called unconditionally.
     *
     * @param int $itemId
     *
     * @return bool
     */
    public static function itemMayAttach($itemId)
    {
        if (!self::required()) {
            return true;
        }

        return \mindstellar\billing\ItemUpgrades::has((int)$itemId, self::FEATURE);
    }

    /**
     * Where a seller goes to buy the allowance, or '' when there is nothing to buy.
     *
     * @param int $itemId
     *
     * @return string
     */
    public static function checkoutUrl($itemId)
    {
        if (!self::required() || !function_exists('osc_item_upgrade_url')) {
            return '';
        }

        return (string)osc_item_upgrade_url((int)$itemId, self::FEATURE);
    }
}
