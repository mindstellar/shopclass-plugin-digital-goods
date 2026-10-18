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

/**
 * Lists the download links of a paid order on its receipt.
 */
class Receipt
{
    /**
     * Core's `billing_receipt_details` filter. A paid order that holds listings with files gets
     * one extra row per file the buyer may download. Anything else is returned as it came.
     *
     * @param mixed $details
     * @param mixed $order
     *
     * @return mixed
     */
    public static function details($details, $order = null)
    {
        if (!is_array($details) || !is_object($order) || !$order->isPaid() || !class_exists('mindstellar\\billing\\OrderLineStore')) {
            return $details;
        }
        $ids = array();
        foreach (\mindstellar\billing\OrderLineStore::itemLines($order->getId()) as $line) {
            $ids[] = (int)($line['item_id'] ?? 0);
        }
        $files = Files::forItems($ids);
        if ($files === array()) {
            return $details;
        }
        $extra = isset($details['extra']) && is_array($details['extra']) ? $details['extra'] : array();
        foreach ($files as $rows) {
            foreach (Api::downloads($rows, $order->getUserId()) as $download) {
                $extra[] = array('label' => sprintf(__('Download: %s', 'digital-goods'), $download['name']), 'value' => $download['url']);
            }
        }
        $details['extra'] = $extra;

        return $details;
    }
}
