<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The settings page talks about the owner's own shop in shop mode and offers no Charging
 * group there; classifieds keeps the seller wording and the group.
 *
 * Usage:  php tests/settings-wording.php
 */

declare(strict_types=1);

namespace mindstellar\billing {
    final class ItemUpgradeStore
    {
    }
}

namespace mindstellar\settings {
    final class SettingsPageRegistry
    {
        public const MENUS = array('billing');
    }
}

namespace {
    define('ABS_PATH', dirname(__DIR__) . '/');
    require_once __DIR__ . '/lib/harness.php';
    require_once ABS_PATH . 'src/Plugin.php';
    require_once ABS_PATH . 'src/Access.php';
    require_once ABS_PATH . 'src/Billing.php';
    require_once ABS_PATH . 'src/Storage.php';
    require_once ABS_PATH . 'src/Uploads.php';

    function __($text, $domain = '')
    {
        return $text;
    }

    function osc_get_preference($name, $section = '')
    {
        return '';
    }

    function osc_register_billing_feature()
    {
    }

    function osc_order_bought()
    {
    }

    function osc_is_shop_mode()
    {
        return $GLOBALS['dgShop'];
    }

    function page(bool $shop): array
    {
        $GLOBALS['dgShop'] = $shop;

        return require ABS_PATH . 'admin/settings.php';
    }

    function texts(array $page): string
    {
        $out = array();
        foreach ($page['groups'] as $group) {
            $out[] = (string)$group['title'];
            foreach ($group['fields'] as $field) {
                $out[] = (string)($field['label'] ?? '') . ' ' . (string)($field['help'] ?? '');
                foreach ($field['options'] ?? array() as $option) {
                    $out[] = (string)$option;
                }
            }
        }

        return implode("\n", $out);
    }

    function names(array $page): array
    {
        $names = array();
        foreach ($page['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                $names[] = $field['name'] ?? '';
            }
        }

        return $names;
    }

    harness_section('Shop mode');
    $shop = page(true);
    check('no "seller" word', stripos(texts($shop), 'seller') === false);
    check('no require_purchase field', !in_array('require_purchase', names($shop), true));
    check('no Charging group', stripos(texts($shop), 'Charging') === false);
    $access = array_values(array_filter($shop['groups'][1]['fields'], static fn ($f) => ($f['name'] ?? '') === 'access'))[0];
    pin('who can download', array('Signed-in visitors', 'Anyone', 'Anyone who bought it', 'Nobody but you'), array_values($access['options']));
    pin('help', 'You can always download your own files.', $access['help']);

    harness_section('Classifieds');
    $classifieds = page(false);
    check('seller wording kept', stripos(texts($classifieds), 'seller') !== false);
    check('require_purchase present', in_array('require_purchase', names($classifieds), true));

    exit(harness_result());
}
