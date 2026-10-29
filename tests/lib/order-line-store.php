<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\billing;

/** The one call the receipt test needs from core's order lines. */
class OrderLineStore
{
    public static function itemLines(int $orderId): array
    {
        return $GLOBALS['dg']['lines'][$orderId] ?? array();
    }
}
