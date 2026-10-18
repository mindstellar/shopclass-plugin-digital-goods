<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\digitalgoods;

use Item;

/**
 * Who may download an attached file.
 *
 * The original asked nobody: the download script looked the row up and served it, and the
 * file was fetchable by direct URL anyway. For a plugin whose whole purpose is attaching
 * something of value to a listing, that is the part worth getting right, so the rule is a
 * setting and it is enforced in one place.
 *
 * The seller always has access to their own file, whatever the rule — a rule that locks
 * an author out of what they uploaded is a bug rather than a policy.
 */
class Access
{
    public const ANYONE = 'anyone';
    public const REGISTERED = 'registered';
    public const BUYER = 'buyer';
    public const SELLER = 'seller';

    /**
     * @return string[]
     */
    public static function rules()
    {
        return array(self::ANYONE, self::REGISTERED, self::BUYER, self::SELLER);
    }

    /**
     * The configured rule, defaulting to registered users.
     *
     * Not `anyone`: the original behaviour was effectively public, and quietly keeping it
     * that way on a plugin that exists to attach paid goods is the wrong default to
     * inherit. An admin who wants it open can say so.
     *
     * @return string
     */
    public static function rule()
    {
        $value = (string)osc_get_preference('access', Plugin::PREF_SECTION);

        return in_array($value, self::rules(), true) ? $value : self::REGISTERED;
    }

    /**
     * Whether core records who bought what, which the buyer rule needs.
     *
     * @return bool
     */
    public static function buyerRuleWorks()
    {
        return function_exists('osc_order_bought');
    }

    /**
     * Whether the current visitor may download a file attached to this listing.
     *
     * @param int $itemId
     *
     * @return bool
     */
    public static function allows($itemId)
    {
        $itemId = (int)$itemId;
        if ($itemId <= 0) {
            return false;
        }

        $userId = osc_is_web_user_logged_in() ? (int)osc_logged_user_id() : 0;

        return self::decide(self::rule(), $itemId, $userId, self::ownerOf($itemId));
    }

    /**
     * Whether a user may download a listing's files under a rule. The seller always may.
     *
     * @param string   $rule
     * @param int      $itemId
     * @param int      $userId  0 for a visitor who is not signed in
     * @param int|null $ownerId the listing's author, null when it has none
     *
     * @return bool
     */
    public static function decide(string $rule, int $itemId, int $userId, ?int $ownerId): bool
    {
        if ($userId > 0 && $ownerId === $userId) {
            return true;
        }

        switch ($rule) {
            case self::ANYONE:
                return true;
            case self::REGISTERED:
                return $userId > 0;
            case self::BUYER:
                return $userId > 0 && self::bought($userId, $itemId);
            case self::SELLER:
            default:
                return false;
        }
    }

    /**
     * Whether the user has a paid order for the listing. On a core without order lines the
     * buyer rule falls back to signed-in visitors.
     *
     * @param int $userId
     * @param int $itemId
     *
     * @return bool
     */
    public static function bought(int $userId, int $itemId): bool
    {
        return !self::buyerRuleWorks() || osc_order_bought($userId, $itemId);
    }

    /**
     * Whether the visitor is the listing's author.
     *
     * @param int $itemId
     *
     * @return bool
     */
    public static function isSeller($itemId)
    {
        if (!osc_is_web_user_logged_in()) {
            return false;
        }

        return self::ownerOf((int)$itemId) === (int)osc_logged_user_id();
    }

    /**
     * The listing's author, or null when the listing has none or is gone.
     *
     * @param int $itemId
     *
     * @return int|null
     */
    private static function ownerOf(int $itemId): ?int
    {
        $item = Item::newInstance()->findByPrimaryKey($itemId);
        if (!is_array($item) || !isset($item['fk_i_user_id'])) {
            return null;
        }

        return (int)$item['fk_i_user_id'];
    }

    /**
     * What to tell someone who cannot download, so the reason is visible rather than a
     * dead link.
     *
     * @return string
     */
    public static function deniedMessage()
    {
        $rule = self::rule();
        if ($rule === self::BUYER && self::buyerRuleWorks()) {
            return __('The attached files are for buyers. They unlock once your order is paid.', 'digital-goods');
        }
        if ($rule === self::REGISTERED || $rule === self::BUYER) {
            return __('Sign in to download the attached files.', 'digital-goods');
        }

        return __('The attached files are available to the seller only.', 'digital-goods');
    }
}
