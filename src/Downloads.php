<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\digitalgoods;

/**
 * What one buyer has bought that comes with files: the data behind the Downloads page.
 */
final class Downloads
{
    /** How many of the newest orders are read. */
    public const ORDERS = 100;

    /** @var array<int,bool> */
    private static array $has = array();

    /**
     * Whether the buyer has a paid order with a listing that carries files: one query, kept
     * for the request.
     */
    public static function has(int $userId): bool
    {
        if ($userId <= 0 || !class_exists('mindstellar\\billing\\OrderLineStore') || !defined('mindstellar\\billing\\Order::SETTLED')) {
            return false;
        }
        if (!isset(self::$has[$userId])) {
            $settled = \mindstellar\billing\Order::SETTLED;
            // The paid rule of core's bought check: a cash on delivery order counts once collected.
            self::$has[$userId] = osc_db_select_one(
                'SELECT 1 FROM ' . Files::table() . ' f'
                . ' INNER JOIN ' . DB_TABLE_PREFIX . 't_billing_order_line l ON l.fk_i_item_id = f.fk_i_item_id'
                . ' INNER JOIN ' . DB_TABLE_PREFIX . 't_billing_order o ON o.pk_i_id = l.fk_i_order_id'
                . ' WHERE o.fk_i_user_id = ? AND o.s_status IN (' . implode(', ', array_fill(0, count($settled), '?')) . ')'
                . " AND (o.s_gateway <> 'cod' OR o.dt_paid_date IS NOT NULL) LIMIT 1",
                array_merge(array($userId), $settled)
            ) !== null;
        }

        return self::$has[$userId];
    }

    /**
     * The account menu with the Downloads entry, for a signed-in buyer who has bought one.
     *
     * @param mixed $options
     *
     * @return array<int,mixed>
     */
    public static function menu($options): array
    {
        $options = is_array($options) ? $options : array();
        if (osc_is_web_user_logged_in() && self::has((int)osc_logged_user_id())) {
            $options[] = array(
                'name'  => __('Downloads', 'digital-goods'),
                'url'   => osc_route_url('digital-goods-downloads'),
                'class' => 'opt_downloads',
            );
        }

        return $options;
    }

    /**
     * The buyer's paid orders that carry files, newest first.
     *
     * @return array<int,array{order:int,date:string,files:array<int,array{name:string,url:string}>}>
     */
    public static function forUser(int $userId): array
    {
        if ($userId <= 0 || !class_exists('mindstellar\\billing\\OrderStore')) {
            return array();
        }
        $out = array();
        foreach (\mindstellar\billing\OrderStore::forUser($userId, self::ORDERS) as $order) {
            if ($order->getUserId() !== $userId) {
                continue;
            }
            $files = Receipt::downloads($order);
            if ($files !== array()) {
                $out[] = array('order' => $order->getId(), 'date' => $order->getDate(), 'files' => $files);
            }
        }

        return $out;
    }
}
