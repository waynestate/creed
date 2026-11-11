<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tools\Mysql56;

/**
 * The databases chapter's claims about MySQL 5.6, one probe each.
 *
 * The SQL is what Laravel 13 sends (its MySQL grammars, read at 13.40.0),
 * or the raw statement the chapter tells an app to write instead. The ids
 * are cited in handbook/databases.md as "probe `id`", and
 * tests/Mysql56ProbesTest.php keeps the two lists level. Run them with
 * bin/mysql56-probe.php (scripts/mysql56-probe.sh or .ps1 start the server).
 *
 * The expected answers were read from mysql:5.6.51, the image Squire runs
 * and Preflight's mysql56-migrate component tests against, with its stock
 * settings (innodb_large_prefix off, innodb_file_format Antelope). The shared
 * server's own settings are its owners' business, which is why the chapter
 * still asks them.
 */
final class Probes
{
    /** The table Laravel 13 would create for `$table->string('email')`, with the stack's default charset. */
    private const string CHARSET = "default character set utf8mb4 collate 'utf8mb4_unicode_ci'";

    /** @return list<Probe> */
    public static function all(): array
    {
        $cs = self::CHARSET;

        return [
            // Conventions that keep 5.6 working.
            new Probe(
                'collation-0900',
                'An 8.0-only collation such as utf8mb4_0900_ai_ci is unknown to 5.6.',
                [],
                "create table `creed_probe_collation` (`name` varchar(10) not null) default character set utf8mb4 collate 'utf8mb4_0900_ai_ci'",
                'error:1273',
            ),
            new Probe(
                'collation-unicode',
                'utf8mb4 with utf8mb4_unicode_ci, the stack default, works.',
                [],
                "create table `creed_probe_unicode` (`name` varchar(10) not null) {$cs}",
                'ok',
            ),
            new Probe(
                'index-255',
                'An indexed utf8mb4 string of 255 characters passes 767 bytes and is refused.',
                ["create table `creed_probe_index255` (`email` varchar(255) not null) {$cs}"],
                'alter table `creed_probe_index255` add unique `creed_probe_index255_email_unique`(`email`)',
                'error:1071',
            ),
            new Probe(
                'index-191',
                'An indexed utf8mb4 string of 191 characters fits.',
                ["create table `creed_probe_index191` (`email` varchar(191) not null) {$cs}"],
                'alter table `creed_probe_index191` add unique `creed_probe_index191_email_unique`(`email`)',
                'ok',
            ),
            new Probe(
                'json-column',
                'There is no JSON column type.',
                [],
                "create table `creed_probe_json` (`data` json not null) {$cs}",
                'error:1064',
            ),
            new Probe(
                'json-functions',
                'There are no JSON functions, so whereJson* and JSON-path updates fail.',
                [],
                "select json_unquote(json_extract('{\"a\": 1}', '$.\"a\"'))",
                'error:1305',
            ),
            new Probe(
                'upsert-alias',
                'The row alias that use_upsert_alias switches upsert() to is a syntax error.',
                ["create table `creed_probe_upsert` (`id` int not null primary key, `votes` int not null) {$cs}"],
                'insert into `creed_probe_upsert` (`id`, `votes`) values (1, 2) as laravel_upsert_alias on duplicate key update `votes` = `laravel_upsert_alias`.`votes`',
                'error:1064',
            ),
            new Probe(
                'upsert-values',
                'upsert() with VALUES(), the default, works.',
                ["create table `creed_probe_upsert_values` (`id` int not null primary key, `votes` int not null) {$cs}"],
                'insert into `creed_probe_upsert_values` (`id`, `votes`) values (1, 2) on duplicate key update `votes` = values(`votes`)',
                'ok',
            ),
            new Probe(
                'column-introspection',
                "Laravel's column query (getColumns, hasColumn, getColumnListing) reads information_schema.columns.generation_expression, which 5.6 lacks.",
                ["create table `creed_probe_columns` (`id` int not null) {$cs}"],
                'select column_name as `name`, data_type as `type_name`, column_type as `type`, collation_name as `collation`, is_nullable as `nullable`, '
                    . 'column_default as `default`, column_comment as `comment`, generation_expression as `expression`, extra as `extra` '
                    . "from information_schema.columns where table_schema = schema() and table_name = 'creed_probe_columns' order by ordinal_position asc",
                'error:1054',
            ),
            new Probe(
                'has-table',
                "Laravel's hasTable query works.",
                ["create table `creed_probe_has_table` (`id` int not null) {$cs}"],
                'select exists (select 1 from information_schema.tables where table_schema = schema() and '
                    . "table_name = 'creed_probe_has_table' and table_type in ('BASE TABLE', 'SYSTEM VERSIONED')) as `exists`",
                'value:1',
            ),
            new Probe(
                'rename-column-change',
                'Renaming a column with ALTER TABLE ... CHANGE works.',
                ["create table `creed_probe_change` (`old_name` int null) {$cs}"],
                'alter table `creed_probe_change` change `old_name` `new_name` int null',
                'ok',
            ),
            new Probe(
                'rename-column-syntax',
                'RENAME COLUMN, the 8.0 syntax, is a syntax error.',
                ["create table `creed_probe_rename_column` (`old_name` int null) {$cs}"],
                'alter table `creed_probe_rename_column` rename column `old_name` to `new_name`',
                'error:1064',
            ),
            new Probe(
                'rename-index',
                'RENAME INDEX, which renameIndex emits, is a syntax error (it arrived in 5.7).',
                ["create table `creed_probe_rename_index` (`votes` int null, index `creed_probe_rename_index_votes_index`(`votes`)) {$cs}"],
                'alter table `creed_probe_rename_index` rename index `creed_probe_rename_index_votes_index` to `creed_probe_votes`',
                'error:1064',
            ),
            new Probe(
                'use-current-date',
                'On 5.6 Laravel drops useCurrent() from a date column, so the column has no default and an insert without it fails.',
                ["create table `creed_probe_use_current` (`id` int not null, `day` date not null) {$cs}"],
                'insert into `creed_probe_use_current` (`id`) values (1)',
                'error:1364',
            ),
            new Probe(
                'date-current-default',
                'A date column cannot take a current-timestamp default.',
                [],
                "create table `creed_probe_date_default` (`day` date not null default CURRENT_TIMESTAMP) {$cs}",
                'error:1067',
            ),
            new Probe(
                'timestamp-current-default',
                'A timestamp column with useCurrent() works.',
                [],
                "create table `creed_probe_timestamp_default` (`at` timestamp not null default CURRENT_TIMESTAMP) {$cs}",
                'ok',
            ),

            // Avoid until the server is upgraded.
            new Probe(
                'generated-column',
                'There are no generated columns (storedAs, virtualAs).',
                [],
                "create table `creed_probe_generated` (`a` int not null, `b` int as (`a` + 1)) {$cs}",
                'error:1064',
            ),
            new Probe(
                'invisible-column',
                'There are no invisible columns.',
                [],
                "create table `creed_probe_invisible` (`a` int null invisible) {$cs}",
                'error:1064',
            ),
            new Probe(
                'vector-column',
                'There is no vector column type.',
                [],
                "create table `creed_probe_vector` (`embedding` vector(3) not null) {$cs}",
                'error:1064',
            ),
            new Probe(
                'spatial-index-innodb',
                'InnoDB has no spatial index before 5.7.5.',
                [],
                "create table `creed_probe_spatial` (`spot` geometry not null, spatial index `creed_probe_spatial_spot_spatialindex`(`spot`)) {$cs} engine = InnoDB",
                'error:1464',
            ),
            new Probe(
                'desc-index-ignored',
                'A DESC index is accepted and silently built ascending.',
                ["create table `creed_probe_desc` (`at` int not null, index `creed_probe_desc_at_index`(`at` desc)) {$cs}"],
                "select count(*) from information_schema.statistics where table_schema = schema() and table_name = 'creed_probe_desc' and collation = 'D'",
                'value:0',
            ),
            new Probe(
                'check-constraint-ignored',
                'A CHECK constraint is accepted and never enforced: a row that breaks it goes in.',
                [
                    "create table `creed_probe_check` (`votes` int not null, check (`votes` > 0)) {$cs}",
                    'insert into `creed_probe_check` (`votes`) values (-1)',
                ],
                'select count(*) from `creed_probe_check` where `votes` < 0',
                'value:1',
            ),
            new Probe(
                'cte',
                'Common table expressions (WITH) are a syntax error.',
                [],
                'with `creed_probe_cte` as (select 1 as `a`) select `a` from `creed_probe_cte`',
                'error:1064',
            ),
            new Probe(
                'window-function',
                'Window functions are a syntax error.',
                ["create table `creed_probe_window` (`id` int not null) {$cs}"],
                'select row_number() over (order by `id`) from `creed_probe_window`',
                'error:1064',
            ),
            new Probe(
                'skip-locked',
                'SKIP LOCKED is a syntax error.',
                ["create table `creed_probe_skip` (`id` int not null) {$cs}"],
                'select * from `creed_probe_skip` for update skip locked',
                'error:1064',
            ),
            new Probe(
                'nowait',
                'NOWAIT is a syntax error.',
                ["create table `creed_probe_nowait` (`id` int not null) {$cs}"],
                'select * from `creed_probe_nowait` for update nowait',
                'error:1064',
            ),
            new Probe(
                'join-lateral',
                'LATERAL joins (joinLateral) are a syntax error.',
                ["create table `creed_probe_lateral` (`id` int not null) {$cs}"],
                'select * from `creed_probe_lateral` inner join lateral (select 1 as `z`) as `l` on true',
                'error:1064',
            ),
            new Probe(
                'joined-delete-order-limit',
                'A joined delete with an order or a limit is a syntax error.',
                [
                    "create table `creed_probe_posts` (`id` int not null) {$cs}",
                    "create table `creed_probe_comments` (`id` int not null, `post_id` int not null) {$cs}",
                ],
                'delete `creed_probe_comments` from `creed_probe_comments` inner join `creed_probe_posts` on `creed_probe_posts`.`id` = `creed_probe_comments`.`post_id` order by `creed_probe_comments`.`id` asc limit 1',
                'error:1064',
            ),

            // Moving to 8.4.
            new Probe(
                'row-format-dynamic',
                "ROW_FORMAT=DYNAMIC passed through the connection's engine setting falls back to COMPACT on the default Antelope file format.",
                [],
                "create table `creed_probe_dynamic` (`email` varchar(255) not null) {$cs} engine = InnoDB ROW_FORMAT=DYNAMIC",
                'warning:1478',
            ),
            new Probe(
                'row-format-dynamic-index',
                'So a 255 character utf8mb4 index is still refused on a DYNAMIC table, until the server enables large prefixes.',
                ["create table `creed_probe_dynamic_index` (`email` varchar(255) not null) {$cs} engine = InnoDB ROW_FORMAT=DYNAMIC"],
                'alter table `creed_probe_dynamic_index` add unique `creed_probe_dynamic_index_email_unique`(`email`)',
                'error:1071',
            ),
            new Probe(
                'large-prefix-off',
                'innodb_large_prefix is off by default.',
                [],
                'select @@innodb_large_prefix',
                'value:0',
            ),
        ];
    }
}
