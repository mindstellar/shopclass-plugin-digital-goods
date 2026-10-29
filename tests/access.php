<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Who may download: each rule, the buyer rule against core's order lines (and its fallback
 * on a core without them), and the listing API field built from the request's credential.
 * Core's API classes are used when a checkout is found (SHOPCLASS_CORE, or ../shopclass).
 *
 * Usage:  php tests/access.php
 */

declare(strict_types=1);

use mindstellar\digitalgoods\Access;
use mindstellar\digitalgoods\Api;
use mindstellar\digitalgoods\Cart;

define('ABS_PATH', dirname(__DIR__) . '/');
define('DB_TABLE_PREFIX', 'oc_');

require_once ABS_PATH . 'src/Plugin.php';
require_once ABS_PATH . 'src/Files.php';
require_once ABS_PATH . 'src/Access.php';
require_once ABS_PATH . 'src/Api.php';
require_once ABS_PATH . 'src/Cart.php';
require_once __DIR__ . '/lib/harness.php';

$oldCore = getenv('DG_TEST_OLD_CORE') !== false;

$GLOBALS['dg'] = array(
    'rule'       => 'registered',
    'user'       => 0,
    'owners'     => array(10 => 5, 11 => 5, 12 => null),
    'paid'       => array(),
    'categories' => array(3),
    'queries'    => array(),
    'rows'       => array(),
    'hooks'      => array(),
);

function __($text, $domain = '')
{
    return $text;
}

function osc_get_preference($name, $section = '')
{
    return $name === 'access' ? $GLOBALS['dg']['rule'] : '';
}

function osc_is_web_user_logged_in()
{
    return $GLOBALS['dg']['user'] > 0;
}

function osc_logged_user_id()
{
    return $GLOBALS['dg']['user'];
}

if (!$oldCore) {
    // Core's helper: a settled order line for the listing.
    function osc_order_bought(int $userId, int $itemId): bool
    {
        return in_array(array($userId, $itemId), $GLOBALS['dg']['paid'], true);
    }
}

function osc_db_select_one($sql, $params = array())
{
    foreach ($GLOBALS['dg']['rows'] as $row) {
        if (str_contains($sql, 's_token = ?') && ($row['s_token'] ?? '') === ($params[0] ?? null)) {
            return $row;
        }
    }

    return null;
}

function osc_is_this_category($name, $id)
{
    return in_array((int)$id, $GLOBALS['dg']['categories'], true);
}

function osc_route_url($id, $args = array())
{
    return 'https://shop.test/digital-goods/download/' . $args['key'];
}

function osc_db_select($sql, $params = array())
{
    $GLOBALS['dg']['queries'][] = array($sql, $params);

    return array_values(array_filter($GLOBALS['dg']['rows'], static fn (array $r): bool => in_array((int)$r['fk_i_item_id'], $params, true)));
}

function osc_api_register_field(string $object, string $slug, string $name, array $schema, array $views = array('public')): void
{
    $GLOBALS['dg']['hooks'][] = array('field', $object, $slug, $name, $views);
}

function osc_add_hook($name, $fn, $priority = 5)
{
    $GLOBALS['dg']['hooks'][] = array('hook', $name);
}

function osc_add_filter($name, $fn, $priority = 5)
{
    $GLOBALS['dg']['hooks'][] = array('filter', $name);
}

class Item
{
    public static function newInstance(): self
    {
        return new self();
    }

    public function findByPrimaryKey($id)
    {
        if (!array_key_exists((int)$id, $GLOBALS['dg']['owners'])) {
            return array();
        }

        return array('pk_i_id' => (int)$id, 'fk_i_user_id' => $GLOBALS['dg']['owners'][(int)$id]);
    }
}

/** A credential and view context, shaped like core's, for when no core checkout is found. */
class FakeViewer
{
    public function __construct(private ?int $userId)
    {
    }

    public function isUser(): bool
    {
        return $this->userId !== null;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }
}

class FakeContext
{
    public function __construct(private ?int $userId)
    {
    }

    public function viewer(): FakeViewer
    {
        return new FakeViewer($this->userId);
    }

    public function fields()
    {
        return null;
    }
}

$as = static function (int $userId, string $rule, int $itemId = 10): bool {
    $GLOBALS['dg']['user'] = $userId;
    $GLOBALS['dg']['rule'] = $rule;

    return Access::allows($itemId);
};

if ($oldCore) {
    harness_section('a core without order lines');

    check('the buyer rule is reported as not working', !Access::buyerRuleWorks());
    check('so a signed-in visitor may download, as under registered', $as(7, Access::BUYER));
    check('a visitor who is not signed in may not', !$as(0, Access::BUYER));
    pin('and is asked to sign in', 'Sign in to download the attached files.', Access::deniedMessage());

    exit(harness_result());
}

harness_section('rules');

pin('the buyer rule is offered', array('anyone', 'registered', 'buyer', 'seller'), Access::rules());
check('the buyer rule works on this core', Access::buyerRuleWorks());
$GLOBALS['dg']['rule'] = 'nonsense';
pin('an unknown stored rule reads as registered', Access::REGISTERED, Access::rule());
$GLOBALS['dg']['rule'] = Access::BUYER;
pin('a stored buyer rule is kept', Access::BUYER, Access::rule());

check('anyone: a visitor who is not signed in', $as(0, Access::ANYONE));
check('registered: a signed-in visitor', $as(7, Access::REGISTERED));
check('registered: not a visitor who is not signed in', !$as(0, Access::REGISTERED));
check('seller only: not another user', !$as(7, Access::SELLER));
check('seller only: the seller', $as(5, Access::SELLER));
check('a listing with no author has no seller to let in', !$as(5, Access::SELLER, 12));
check('no listing id, no download', !$as(5, Access::ANYONE, 0));

harness_section('buyer rule');

$GLOBALS['dg']['paid'] = array(array(7, 10));
check('a buyer with a paid order may download', $as(7, Access::BUYER));
check('only for the listing bought', !$as(7, Access::BUYER, 11));
check('a stranger may not', !$as(8, Access::BUYER));
check('nor a visitor who is not signed in', !$as(0, Access::BUYER));
check('the seller always may', $as(5, Access::BUYER));
$GLOBALS['dg']['paid'] = array();
check('a pending order is not a purchase (core counts settled orders only)', !$as(7, Access::BUYER));
$GLOBALS['dg']['user'] = 7;
pin('the refusal says why', 'The attached files are for buyers. They unlock once your order is paid.', Access::deniedMessage());

harness_section('the download route');

$GLOBALS['dg']['rule'] = Access::BUYER;
$GLOBALS['dg']['rows'] = array(
    array('pk_i_id' => 1, 'fk_i_item_id' => 10, 's_name' => 'Guide.pdf', 's_token' => str_repeat('a', 32)),
    array('pk_i_id' => 2, 'fk_i_item_id' => 11, 's_name' => 'Other.pdf', 's_token' => str_repeat('d', 32)),
);
$route = static function (int $userId, string $key): array {
    $GLOBALS['dg']['user'] = $userId;
    list($status, $row) = Access::forKey($key);

    return array($status, $row === null ? null : $row['pk_i_id']);
};
$GLOBALS['dg']['paid'] = array(array(7, 10));
pin('an unknown key is a 404', array(404, null), $route(7, str_repeat('f', 32)));
pin('so is a malformed one', array(404, null), $route(7, '../../config.php'));
pin('a stranger is refused', array(403, 1), $route(8, str_repeat('a', 32)));
pin('the buyer gets the file the key names', array(200, 1), $route(7, str_repeat('a', 32)));
pin('but not a file of a listing they did not buy', array(403, 2), $route(7, str_repeat('d', 32)));
$GLOBALS['dg']['paid'] = array();
pin('a buyer whose order is pending is refused', array(403, 1), $route(7, str_repeat('a', 32)));
check('the route asks forKey() and serves only a 200', (static function (): bool {
    $src = (string)file_get_contents(ABS_PATH . 'public/download.php');
    $gate = strpos($src, 'Access::forKey(');
    $serve = strpos($src, 'Delivery::sendFile(');

    return $gate !== false && $serve !== false && $gate < $serve && str_contains($src, 'if ($dgStatus !== 200) {') && substr_count($src, 'Files::byToken') === 0;
})());
$GLOBALS['dg']['rows'] = array();

harness_section('a cart of downloads');

$GLOBALS['dg']['rows'] = array(
    array('fk_i_item_id' => 10, 's_name' => 'Guide.pdf', 's_token' => str_repeat('a', 32)),
    array('fk_i_item_id' => 11, 's_name' => 'Other.pdf', 's_token' => str_repeat('d', 32)),
);
$cart = static fn (array $ids): array => array('lines' => array_map(static fn (int $id): array => array('item_id' => $id, 'qty' => 1), $ids));
$GLOBALS['dg']['rule'] = Access::BUYER;
pin('every listing has files: no shipping', false, Cart::needsShipping(true, $cart(array(10, 11))));
pin('one listing without files still ships', true, Cart::needsShipping(true, $cart(array(10, 12))));
pin('an empty cart is left alone', true, Cart::needsShipping(true, $cart(array())));
$typed = static function (array $ids) use ($cart): bool {
    $event = new \mindstellar\billing\event\CartShipping($cart($ids));
    Cart::onShipping($event);

    return $event->needsShipping();
};
pin('typed: every listing has files, no shipping', false, $typed(array(10, 11)));
pin('typed: one listing without files still ships', true, $typed(array(10, 12)));
$GLOBALS['dg']['rule'] = Access::REGISTERED;
pin('outside the buyer rule, files are extras and the cart ships', true, Cart::needsShipping(true, $cart(array(10))));
$GLOBALS['dg']['rows'] = array();
$GLOBALS['dg']['queries'] = array();

harness_section('the listing API field');

Api::register();
pin('declared on listings, public, with the prefetch and the filter', array(
    array('field', 'listing', 'digital-goods', 'downloads', array('public')),
    array('hook', 'api_listings_prefetch'),
    array('filter', 'api_listing'),
), $GLOBALS['dg']['hooks']);

$GLOBALS['dg']['rows'] = array(
    array('fk_i_item_id' => 10, 's_name' => 'Guide.pdf', 's_token' => str_repeat('a', 32), 'fk_i_user_id' => 5, 'fk_i_category_id' => 3),
    array('fk_i_item_id' => 10, 's_name' => 'Extras.zip', 's_token' => str_repeat('b', 32), 'fk_i_user_id' => 5, 'fk_i_category_id' => 3),
    array('fk_i_item_id' => 13, 's_name' => 'Off.pdf', 's_token' => str_repeat('c', 32), 'fk_i_user_id' => 5, 'fk_i_category_id' => 4),
);
$GLOBALS['dg']['rule'] = Access::BUYER;
$GLOBALS['dg']['paid'] = array(array(7, 10));
$GLOBALS['dg']['user'] = 0;

Api::prefetch(array(10, 11, 13));
pin('a page of listings costs one query', 1, count($GLOBALS['dg']['queries']));
check('on the files table joined to the listing', str_contains($GLOBALS['dg']['queries'][0][0], 'FROM oc_t_item_dg_file f JOIN oc_t_item i ON i.pk_i_id = f.fk_i_item_id WHERE f.fk_i_item_id IN (?,?,?)'));

$field = static fn (array $data): array => $data['ext']['digital-goods']['downloads'] ?? array('missing');
$links = array(
    array('name' => 'Guide.pdf', 'url' => 'https://shop.test/digital-goods/download/' . str_repeat('a', 32)),
    array('name' => 'Extras.zip', 'url' => 'https://shop.test/digital-goods/download/' . str_repeat('b', 32)),
);
pin('the buyer\'s credential gets the gated links', $links, $field(Api::listing(array('id' => 10), new FakeContext(7))));
pin('a stranger gets an empty list', array(), $field(Api::listing(array('id' => 10), new FakeContext(8))));
pin('so does a guest', array(), $field(Api::listing(array('id' => 10), new FakeContext(null))));
pin('the seller gets the links', $links, $field(Api::listing(array('id' => 10), new FakeContext(5))));
pin('the signed-in web visitor does not count: only the credential does', array(), $field((function () {
    $GLOBALS['dg']['user'] = 7;
    $out = Api::listing(array('id' => 10), new FakeContext(8));
    $GLOBALS['dg']['user'] = 0;

    return $out;
})()));
pin('a listing with no files gets an empty list', array(), $field(Api::listing(array('id' => 11), new FakeContext(7))));
pin('a category the plugin is off for shows nothing', array(), $field(Api::listing(array('id' => 13), new FakeContext(5))));
pin('still one query', 1, count($GLOBALS['dg']['queries']));
Api::listing(array('id' => 99), new FakeContext(7));
pin('one more for a single listing outside a page', 2, count($GLOBALS['dg']['queries']));
pin('other members are left alone', array('id' => 10, 'title' => 'Tee'), array_diff_key(Api::listing(array('id' => 10, 'title' => 'Tee'), new FakeContext(7)), array('ext' => true)));

$core = rtrim((string)(getenv('SHOPCLASS_CORE') ?: dirname(__DIR__, 2) . '/shopclass'), '/');
if (is_file($core . '/oc-includes/osclass/classes/api/serializer/ViewContext.php')) {
    harness_section('against core\'s API classes');

    spl_autoload_register(static function (string $class) use ($core): void {
        foreach (array('mindstellar\\apikey\\' => 'apikey/', 'mindstellar\\api\\' => 'api/', 'mindstellar\\utility\\' => 'utility/') as $prefix => $dir) {
            if (strncmp($class, $prefix, strlen($prefix)) === 0) {
                $file = $core . '/oc-includes/osclass/classes/' . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }

                return;
            }
        }
    });

    $validator = new \mindstellar\api\schema\Validator();
    pin('core accepts the schema', array(), $validator->schemaProblems(Api::schema()));
    $logged = array();
    $members = \mindstellar\api\serializer\ExtensionMembers::fromDeclarations(
        array(array('listing', 'digital-goods', 'downloads', Api::schema(), array('public'))),
        $validator,
        static function (string $m) use (&$logged): void {
            $logged[] = $m;
        }
    );
    check('and the declaration', $logged === array() && $members->find('listing', 'digital-goods', 'downloads') !== null, implode('; ', $logged));

    $context = static fn (\mindstellar\apikey\Credential $c): \mindstellar\api\serializer\ViewContext => new \mindstellar\api\serializer\ViewContext($c, 'en_US', null, array(), 'public', 'v1');
    $session = new \mindstellar\apikey\Credential(\mindstellar\apikey\CredentialKind::SESSION, array('listings:read'), 7);
    $guest   = new \mindstellar\apikey\Credential(\mindstellar\apikey\CredentialKind::GUEST, array('listings:read'));
    $value   = $field(Api::listing(array('id' => 10), $context($session)));
    pin('a session credential for the buyer gets the links', $links, $value);
    pin('which match the schema', array(), $validator->check(Api::schema(), $value));
    pin('a guest page token gets none', array(), $field(Api::listing(array('id' => 10), $context($guest))));
}

$child = proc_open(array(PHP_BINARY, __FILE__), array(1 => array('pipe', 'w')), $pipes, null, array('DG_TEST_OLD_CORE' => '1') + getenv());
$out   = stream_get_contents($pipes[1]);
fclose($pipes[1]);
check('the old-core run passes', proc_close($child) === 0, $out);

exit(harness_result());
