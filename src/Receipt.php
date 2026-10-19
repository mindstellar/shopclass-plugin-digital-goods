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

use mindstellar\billing\event\ReceiptComposing;

/**
 * Lists the download links of a paid order on its receipt.
 */
class Receipt
{
    /** Typed listener for `billing_receipt_details` on cores with the receipt event. */
    public static function onDetails(ReceiptComposing $event): void
    {
        foreach (self::rows($event->order()) as $row) {
            $event->addExtraRow($row['label'], $row['value']);
        }
    }

    /**
     * Core's `billing_receipt_details` filter, for cores without the receipt event. A paid order
     * that holds listings with files gets one extra row per file the buyer may download. Anything
     * else is returned as it came.
     *
     * @param mixed $details
     * @param mixed $order
     *
     * @return mixed
     */
    public static function details($details, $order = null)
    {
        if (!is_array($details) || !is_object($order)) {
            return $details;
        }
        $rows = self::rows($order);
        if ($rows === array()) {
            return $details;
        }
        $extra = isset($details['extra']) && is_array($details['extra']) ? $details['extra'] : array();
        $details['extra'] = array_merge($extra, $rows);

        return $details;
    }

    /**
     * The download rows of a paid order; none for any other.
     *
     * @param object $order
     *
     * @return array<int,array{label:string,value:string}>
     */
    private static function rows($order): array
    {
        $rows = array();
        foreach (self::downloads($order) as $download) {
            $rows[] = array('label' => sprintf(__('Download: %s', 'digital-goods'), $download['name']), 'value' => $download['url']);
        }

        return $rows;
    }

    /**
     * The files of a paid order the buyer may download; none for any other order.
     *
     * @param object $order
     *
     * @return array<int,array{name:string,url:string}>
     */
    public static function downloads($order): array
    {
        if (!$order->isPaid() || !class_exists('mindstellar\\billing\\OrderLineStore')) {
            return array();
        }
        $ids = array();
        foreach (\mindstellar\billing\OrderLineStore::itemLines($order->getId()) as $line) {
            $ids[] = (int)($line['item_id'] ?? 0);
        }
        $out = array();
        foreach (Files::forItems($ids) as $files) {
            foreach (Api::downloads($files, $order->getUserId()) as $download) {
                $out[] = $download;
            }
        }

        return $out;
    }
}
