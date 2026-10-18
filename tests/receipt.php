<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The receipt filter: download links on a paid order only, for the listings on it that have
 * files, and only for a buyer the access rule lets in.
 *
 * Usage:  php tests/receipt.php
 */

declare(strict_types=1);

use mindstellar\digitalgoods\Receipt;

define('ABS_PATH', dirname(__DIR__) . '/');
define('DB_TABLE_PREFIX', 'oc_');

require_once ABS_PATH . 'src/Plugin.php';
require_once ABS_PATH . 'src/Files.php';
require_once ABS_PATH . 'src/Access.php';
require_once ABS_PATH . 'src/Api.php';
require_once ABS_PATH . 'src/Receipt.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/order-line-store.php';

$GLOBALS['dg'] = array(
    'rule'  => 'registered',
    'paid'  => array(),
    'lines' => array(1 => array(array('item_id' => 10), array('item_id' => 11)), 2 => array(array('item_id' => 11))),
    'rows'  => array(
        array('fk_i_item_id' => 10, 's_name' => 'guide.pdf', 's_token' => 'aaa', 'fk_i_user_id' => 5, 'fk_i_category_id' => 3),
        array('fk_i_item_id' => 10, 's_name' => 'bonus.zip', 's_token' => 'bbb', 'fk_i_user_id' => 5, 'fk_i_category_id' => 3),
    ),
);

function __($text, $domain = '')
{
    return $text;
}

function osc_get_preference($name, $section = '')
{
    return $name === 'access' ? $GLOBALS['dg']['rule'] : '';
}

function osc_order_bought(int $userId, int $itemId): bool
{
    return in_array(array($userId, $itemId), $GLOBALS['dg']['paid'], true);
}

function osc_is_this_category($name, $id)
{
    return (int)$id === 3;
}

function osc_route_url($id, $args = array())
{
    return 'https://shop.test/digital-goods/download/' . $args['key'];
}

function osc_db_select($sql, $params = array())
{
    return array_values(array_filter($GLOBALS['dg']['rows'], static fn (array $r): bool => in_array((int)$r['fk_i_item_id'], $params, true)));
}

class FakeOrder
{
    public function __construct(private int $id, private int $user, private bool $paid)
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->user;
    }

    public function isPaid(): bool
    {
        return $this->paid;
    }
}

$base = array('lines' => array(), 'extra' => array(array('label' => 'VAT', 'value' => '123')), 'footer' => '');

harness_section('the receipt filter');

$out = Receipt::details($base, new FakeOrder(1, 7, true));
pin('a paid order lists a link per file, after the rows already there', array('VAT', 'Download: guide.pdf', 'Download: bonus.zip'), array_column($out['extra'], 'label'));
pin('each row holds the download address', array('123', 'https://shop.test/digital-goods/download/aaa', 'https://shop.test/digital-goods/download/bbb'), array_column($out['extra'], 'value'));
pin('nothing else of the details changes', array($base['lines'], $base['footer']), array($out['lines'], $out['footer']));
pin('an order that is not paid shows no link', $base, Receipt::details($base, new FakeOrder(1, 7, false)));
pin('an order with no listing that has files shows none', $base, Receipt::details($base, new FakeOrder(2, 7, true)));
pin('details without an order are returned as they came', $base, Receipt::details($base, null));

$GLOBALS['dg']['rule'] = 'buyer';
pin('under the buyer rule a buyer whose order is not settled in core gets none', $base, Receipt::details($base, new FakeOrder(1, 7, true)));
$GLOBALS['dg']['paid'][] = array(7, 10);
pin('and one who has bought the listing gets them', 3, count(Receipt::details($base, new FakeOrder(1, 7, true))['extra']));

exit(harness_result());
