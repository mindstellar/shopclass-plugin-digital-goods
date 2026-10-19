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
