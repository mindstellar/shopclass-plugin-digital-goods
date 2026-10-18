<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\digitalgoods;

use mindstellar\billing\event\CartShipping;

/**
 * Tells core's checkout when a cart holds downloads only, so it asks for no address or delivery.
 */
class Cart
{
    /** Typed listener for `cart_needs_shipping` on cores with the cart events. */
    public static function onShipping(CartShipping $event): void
    {
        if ($event->needsShipping() && self::downloadsOnly($event->cartArray())) {
            $event->setNeedsShipping(false);
        }
    }

    /**
     * Core's `cart_needs_shipping` filter, for cores without the cart events. Under the buyer rule, a cart whose every listing has
     * files is downloads only. One query.
     *
     * @param mixed $needs
     * @param mixed $cart
     *
     * @return mixed
     */
    public static function needsShipping($needs, $cart = array())
    {
        if ($needs === false || !is_array($cart)) {
            return $needs;
        }

        return self::downloadsOnly($cart) ? false : $needs;
    }

    /**
     * @param array<string,mixed> $cart
     */
    private static function downloadsOnly(array $cart): bool
    {
        if (Access::rule() !== Access::BUYER || !Access::buyerRuleWorks()) {
            return false;
        }
        $ids = array();
        foreach ((array)($cart['lines'] ?? array()) as $line) {
            $ids[] = is_array($line) ? (int)($line['item_id'] ?? 0) : 0;
        }
        $ids = array_values(array_unique($ids));
        if ($ids === array() || in_array(0, $ids, true)) {
            return false;
        }
        $files = Files::forItems($ids);
        foreach ($ids as $id) {
            if (empty($files[$id])) {
                return false;
            }
        }

        return true;
    }
}
