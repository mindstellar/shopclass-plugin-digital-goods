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
 * The listing field `ext.digital-goods.downloads`: the files the caller may download, as
 * {name, url}, and an empty list for anyone the access rule refuses. The links are the
 * same gated download route the listing page prints.
 */
final class Api
{
    public const FIELD = 'downloads';

    /** @var array<int,array<int,array<string,mixed>>> listing id => its files */
    private static array $files = array();

    /** @var array<int,bool> category id => whether the plugin is on for it */
    private static array $categories = array();

    /**
     * Declare the field, on a core with the API.
     */
    public static function register(): void
    {
        if (!function_exists('osc_api_register_field')) {
            return;
        }

        osc_api_register_field('listing', Plugin::SHORT_NAME, self::FIELD, self::schema(), array('public'));
        osc_add_hook('api_listings_prefetch', array(self::class, 'prefetch'));
        osc_add_filter('api_listing', array(self::class, 'listing'));
    }

    /**
     * @return array<string,mixed>
     */
    public static function schema(): array
    {
        return array(
            'type'        => 'array',
            'description' => 'Files the caller may download.',
            'items'       => array(
                'type'       => 'object',
                'required'   => array('name', 'url'),
                'properties' => array(
                    'name' => array('type' => 'string'),
                    'url'  => array('type' => 'string', 'format' => 'uri'),
                ),
            ),
        );
    }

    /**
     * Load the files of a page of listings in one query.
     *
     * @param mixed $ids
     */
    public static function prefetch($ids): void
    {
        $ids   = array_map('intval', is_array($ids) ? $ids : array());
        $found = Files::forItems($ids);
        foreach ($ids as $id) {
            self::$files[$id] = $found[$id] ?? array();
        }
    }

    /**
     * The `api_listing` filter.
     *
     * @param mixed $data
     * @param mixed $context a ViewContext
     *
     * @return mixed
     */
    public static function listing($data, $context = null)
    {
        if (!is_array($data) || !isset($data['id'])) {
            return $data;
        }
        $fields = is_object($context) && method_exists($context, 'fields') ? $context->fields() : null;
        if ($fields !== null && !$fields->wants('ext')) {
            return $data;
        }

        $itemId = (int)$data['id'];
        if (!array_key_exists($itemId, self::$files)) {
            self::prefetch(array($itemId));
        }
        $data['ext'][Plugin::SHORT_NAME][self::FIELD] = self::downloads(self::$files[$itemId], self::viewerId($context));

        return $data;
    }

    /**
     * The files of one listing a user may download.
     *
     * @param array<int,array<string,mixed>> $rows   from Files::forItems()
     * @param int                            $userId 0 for a visitor who is not signed in
     *
     * @return array<int,array{name:string,url:string}>
     */
    public static function downloads(array $rows, int $userId): array
    {
        if ($rows === array()) {
            return array();
        }
        $first  = $rows[0];
        $itemId = (int)$first['fk_i_item_id'];
        $owner  = $first['fk_i_user_id'] === null ? null : (int)$first['fk_i_user_id'];
        if (!self::categoryOn((int)$first['fk_i_category_id'])
            || !Access::decide(Access::rule(), $itemId, $userId, $owner)) {
            return array();
        }

        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'name' => (string)$row['s_name'],
                'url'  => (string)osc_route_url('digital-goods-download', array('key' => (string)$row['s_token'])),
            );
        }

        return $out;
    }

    /**
     * The signed-in user behind the request's credential, or 0.
     *
     * @param mixed $context
     */
    private static function viewerId($context): int
    {
        if (!is_object($context) || !method_exists($context, 'viewer')) {
            return 0;
        }
        $viewer = $context->viewer();

        return $viewer->isUser() ? (int)$viewer->userId() : 0;
    }

    private static function categoryOn(int $categoryId): bool
    {
        if (!isset(self::$categories[$categoryId])) {
            self::$categories[$categoryId] = $categoryId > 0 && osc_is_this_category(Plugin::SHORT_NAME, $categoryId);
        }

        return self::$categories[$categoryId];
    }
}
