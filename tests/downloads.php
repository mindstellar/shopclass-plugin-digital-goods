<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The buyer's Downloads page: only the signed-in buyer's paid orders, newest first, and a
 * redirect when signed out.
 *
 * Usage:  php tests/downloads.php
 */

declare(strict_types=1);

namespace mindstellar\billing {
    class OrderStore
    {
        public static function forUser(int $userId, int $limit = 25): array
        {
            return $GLOBALS['dg']['orders'][$userId] ?? array();
        }
    }

    class Order
    {
        public const SETTLED = array('paid', 'shipped', 'delivered');
    }

    class OrderLineStore
    {
        public static function itemLines(int $orderId): array
        {
            return $GLOBALS['dg']['lines'][$orderId] ?? array();
        }
    }
}

namespace {
    use mindstellar\digitalgoods\Downloads;

    define('ABS_PATH', dirname(__DIR__) . '/');
    define('DB_TABLE_PREFIX', 'oc_');

    require_once ABS_PATH . 'src/Plugin.php';
    require_once ABS_PATH . 'src/Files.php';
    require_once ABS_PATH . 'src/Access.php';
    require_once ABS_PATH . 'src/Api.php';
    require_once ABS_PATH . 'src/Receipt.php';
    require_once ABS_PATH . 'src/Downloads.php';
    require_once __DIR__ . '/lib/harness.php';

    class FakeOrder
    {
        public function __construct(private int $id, private int $user, private bool $paid, private string $date)
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

        public function getDate(): string
        {
            return $this->date;
        }
    }

    class Redirected extends \RuntimeException
    {
    }

    $GLOBALS['dg'] = array(
        'rule'   => 'registered',
        'signed' => true,
        'orders' => array(
            7 => array(new FakeOrder(3, 7, true, '2026-03-01'), new FakeOrder(2, 7, false, '2026-02-01'), new FakeOrder(1, 7, true, '2026-01-01')),
            8 => array(new FakeOrder(4, 8, true, '2026-03-02')),
        ),
        'lines'  => array(1 => array(array('item_id' => 10)), 2 => array(array('item_id' => 10)), 3 => array(array('item_id' => 11)), 4 => array(array('item_id' => 12))),
        'rows'   => array(
            array('fk_i_item_id' => 10, 's_name' => 'guide.pdf', 's_token' => 'aaa', 'fk_i_user_id' => 5, 'fk_i_category_id' => 3),
            array('fk_i_item_id' => 11, 's_name' => 'bonus.zip', 's_token' => 'bbb', 'fk_i_user_id' => 5, 'fk_i_category_id' => 3),
            array('fk_i_item_id' => 12, 's_name' => 'other.pdf', 's_token' => 'ccc', 'fk_i_user_id' => 5, 'fk_i_category_id' => 3),
        ),
    );

    function __($text, $domain = '')
    {
        return $text;
    }

    function osc_get_preference($name, $section = '')
    {
        return $GLOBALS['dg']['rule'];
    }

    function osc_is_this_category($name, $id)
    {
        return true;
    }

    function osc_route_url($id, $args = array())
    {
        return 'https://shop.test/digital-goods/download/' . ($args['key'] ?? '');
    }

    function osc_db_select($sql, $params = array())
    {
        return array_values(array_filter($GLOBALS['dg']['rows'], static fn (array $r): bool => in_array((int)$r['fk_i_item_id'], $params, true)));
    }

    function osc_db_select_one($sql, $params = array())
    {
        $GLOBALS['dg']['queries'][] = array($sql, $params);

        return in_array($params[0], $GLOBALS['dg']['buyers'] ?? array(), true) ? array('1' => 1) : null;
    }

    function osc_is_web_user_logged_in()
    {
        return $GLOBALS['dg']['signed'];
    }

    function osc_user_login_url()
    {
        return 'https://shop.test/login';
    }

    function osc_logged_user_id()
    {
        return 7;
    }

    function osc_redirect_to($url)
    {
        throw new Redirected($url);
    }

    function osc_esc_html($text)
    {
        return htmlspecialchars((string)$text, ENT_QUOTES);
    }

    function osc_format_date($date)
    {
        return $date;
    }

    harness_section('the data');

    $mine = Downloads::forUser(7);
    pin('only paid orders with files, newest first', array(3, 1), array_column($mine, 'order'));
    pin('the buyer sees their own tokens', array('Order 3' => 'https://shop.test/digital-goods/download/bbb'), array('Order 3' => $mine[0]['files'][0]['url']));
    $all = json_encode(Downloads::forUser(7));
    check('another buyer\'s file is absent', strpos((string)$all, 'ccc') === false);
    pin('a visitor gets nothing', array(), Downloads::forUser(0));

    harness_section('the account menu');

    $GLOBALS['dg']['buyers'] = array(7);
    $menu = Downloads::menu(array(array('name' => 'Dashboard')));
    pin('a buyer with a paid download gets the entry', array('Dashboard', 'Downloads'), array_column($menu, 'name'));
    $sql = (string)($GLOBALS['dg']['queries'][0][0] ?? '');
    check('one query over files, order lines and settled orders', count($GLOBALS['dg']['queries'] ?? array()) === 1
        && strpos($sql, 't_item_dg_file') !== false && strpos($sql, 't_billing_order_line') !== false && strpos($sql, 'LIMIT 1') !== false);
    pin('for this buyer and the settled statuses', array(7, 'paid', 'shipped', 'delivered'), $GLOBALS['dg']['queries'][0][1] ?? null);
    Downloads::menu(array());
    pin('asked again in the request, no second query', 1, count($GLOBALS['dg']['queries'] ?? array()));
    pin('a buyer with none gets no entry', false, Downloads::has(8));
    $GLOBALS['dg']['signed'] = false;
    pin('nor does a visitor', array(), Downloads::menu(array()));
    $GLOBALS['dg']['signed'] = true;

    harness_section('the page');

    ob_start();
    include ABS_PATH . 'public/downloads.php';
    $html = (string)ob_get_clean();
    check('lists the buyer\'s files', strpos($html, 'guide.pdf') !== false && strpos($html, 'bonus.zip') !== false);
    check('leaves out another buyer\'s file', strpos($html, 'other.pdf') === false);

    $GLOBALS['dg']['orders'][7] = array();
    ob_start();
    include ABS_PATH . 'public/downloads.php';
    $html = (string)ob_get_clean();
    check('says so when nothing was bought', strpos($html, 'You have not bought any downloads yet.') !== false);

    $GLOBALS['dg']['signed'] = false;
    $went = '';
    try {
        ob_start();
        include ABS_PATH . 'public/downloads.php';
    } catch (Redirected $e) {
        $went = $e->getMessage();
    } finally {
        ob_end_clean();
    }
    pin('signed out goes to the login page', 'https://shop.test/login', $went);

    exit(harness_result());
}
