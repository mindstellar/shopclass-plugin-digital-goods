<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2013 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\digitalgoods;

/**
 * Every read and write of the attachment table.
 *
 * Queries go through the query builder rather than a DAO subclass: values are bound, and
 * the table name is the only thing interpolated. The original built its SQL by
 * concatenation inside a model extending the legacy DAO, which is the layer core has been
 * moving off.
 */
class Files
{
    /** @return string the prefixed table name */
    public static function table()
    {
        return DB_TABLE_PREFIX . 't_item_dg_file';
    }

    /**
     * Create the table. Called on install; safe to call again.
     *
     * @return void
     */
    public static function install()
    {
        osc_db_execute(
            'CREATE TABLE IF NOT EXISTS ' . self::table() . ' ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' fk_i_item_id INT UNSIGNED NOT NULL,'
            // What the seller uploaded, shown to the buyer. Never used to build a path.
            . ' s_name VARCHAR(190) NOT NULL,'
            // What the download URL carries. Random, and separate from the storage key so
            // the address of a file says nothing about where the bytes are kept — the
            // layout of the bucket can change without any published link changing.
            . ' s_token CHAR(32) NOT NULL,'
            // Where the storage adapter holds the bytes. Never derived from anything the
            // uploader controls.
            . ' s_key VARCHAR(190) NOT NULL,'
            . ' s_content_type VARCHAR(120) NOT NULL DEFAULT "application/octet-stream",'
            . ' i_size INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' i_downloads INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' dt_date DATETIME NOT NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' UNIQUE KEY uq_token (s_token),'
            . ' UNIQUE KEY uq_key (s_key),'
            . ' INDEX idx_item (fk_i_item_id),'
            . ' FOREIGN KEY (fk_i_item_id) REFERENCES ' . DB_TABLE_PREFIX . 't_item (pk_i_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
        );
    }

    /**
     * Drop the table. The rows are meaningless without the files, which the caller
     * removes from storage first.
     *
     * @return void
     */
    public static function uninstall()
    {
        osc_db_execute('DROP TABLE IF EXISTS ' . self::table());
    }

    /**
     * Every file attached to one listing.
     *
     * @param int $itemId
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forItem($itemId)
    {
        return osc_db_table(self::table())
            ->where('fk_i_item_id', (int)$itemId)
            ->orderBy('pk_i_id', 'ASC')
            ->get();
    }

    /**
     * One file by the token in its download URL — the only lookup that route performs.
     *
     * @param string $token
     *
     * @return array<string,mixed>|null
     */
    public static function byToken($token)
    {
        $token = (string)$token;
        // The column is a fixed-width random hex string, so anything else cannot match
        // and is not worth a query.
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }

        return osc_db_select_one(
            'SELECT * FROM ' . self::table() . ' WHERE s_token = ? LIMIT 1',
            array($token)
        );
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return int the new id
     */
    public static function add(array $row)
    {
        return osc_db_table(self::table())->insert($row);
    }

    /**
     * @param int $id
     *
     * @return void
     */
    public static function delete($id)
    {
        osc_db_execute('DELETE FROM ' . self::table() . ' WHERE pk_i_id = ?', array((int)$id));
    }

    /**
     * Count one download.
     *
     * Incremented in SQL rather than read-modify-written, so two simultaneous downloads
     * cannot both write the same total back. The original read the row, added one, and
     * wrote it — losing a count whenever that raced.
     *
     * @param int $id
     *
     * @return void
     */
    public static function countDownload($id)
    {
        osc_db_execute(
            'UPDATE ' . self::table() . ' SET i_downloads = i_downloads + 1 WHERE pk_i_id = ?',
            array((int)$id)
        );
    }

    /**
     * Totals for the admin screen.
     *
     * @param int $limit
     * @param int $offset
     *
     * @return array<int,array<string,mixed>>
     */
    public static function report($limit = 50, $offset = 0)
    {
        return osc_db_select(
            'SELECT f.pk_i_id, f.fk_i_item_id, f.s_name, f.i_size, f.i_downloads, f.dt_date,'
            . ' d.s_title'
            . ' FROM ' . self::table() . ' f'
            . ' LEFT JOIN ' . DB_TABLE_PREFIX . 't_item_description d'
            . '   ON d.fk_i_item_id = f.fk_i_item_id AND d.fk_c_locale_code = ?'
            . ' ORDER BY f.i_downloads DESC, f.pk_i_id DESC'
            . ' LIMIT ? OFFSET ?',
            array(osc_current_user_locale(), (int)$limit, (int)$offset)
        );
    }
}
