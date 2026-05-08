<?php

declare(strict_types=1);

namespace App\database;

use App\config\Config;

final class Database
{
    private static ?object $wpdb_wrapper = null;

    /**
     * Get a PDO-like wrapper for the WordPress database object ($wpdb).
     * This allows the existing PDO code in models to work with MySQL via WordPress.
     */
    public static function getConnection(): object
    {
        if (self::$wpdb_wrapper !== null) {
            return self::$wpdb_wrapper;
        }

        self::$wpdb_wrapper = new class {
            public function prepare(string $query): object
            {
                return new class ($query) {
                    private string $query;
                    public function __construct(string $q)
                    {
                        $this->query = $q; }
                    public function execute(array $params = []): bool
                    {
                        global $wpdb;
                        $sql = $this->query;
                        $bindings = [];

                        $bindings = [];
                        // Use regex to find all :placeholder occurrences and replace them in order
                        $sql = preg_replace_callback('/:([a-zA-Z0-9_]+)/', function ($matches) use ($params, &$bindings) {
                            $key = $matches[1];
                            if (array_key_exists($key, $params)) {
                                $bindings[] = $params[$key];
                                return is_int($params[$key]) ? '%d' : (is_float($params[$key]) ? '%f' : '%s');
                            }
                            return $matches[0]; // Keep as is if not found in params
                        }, $sql);

                        // Prefix and Map Table Names
                        $map = [
                        'support_requests' => 'request',
                        'support_issues' => 'issue',
                        'issue_attachments' => 'attachment',
                        'ticket_queue' => 'queue',
                        'clients_cache' => 'client_cache',
                        'client_domains' => 'client_domain',
                        'client_jira_maps' => 'client_jira_map',
                        ];

                        foreach ($map as $old => $new) {
                            $fullTable = $wpdb->prefix . 'kgr_support_' . $new;
                            // Replace standalone logical table names even when followed by
                            // newlines/tabs/aliases, not only plain spaces/parentheses.
                            $sql = (string) preg_replace('/\b' . preg_quote($old, '/') . '\b/', $fullTable, $sql);
                        }

                        if (!empty($bindings)) {
                            $sql = $wpdb->prepare($sql, ...$bindings);
                        }

                        $result = $wpdb->query($sql);
                        return $result !== false;
                    }
                    public function fetch()
                    {
                        global $wpdb;
                        return $wpdb->get_row($wpdb->last_query, ARRAY_A);
                    }
                    public function fetchAll()
                    {
                        global $wpdb;
                        return $wpdb->get_results($wpdb->last_query, ARRAY_A);
                    }
                    public function fetchColumn()
                    {
                        global $wpdb;
                        return $wpdb->get_var($wpdb->last_query);
                    }
                    public function rowCount()
                    {
                        global $wpdb;
                        return $wpdb->rows_affected;
                    }
                };
            }

            public function query(string $sql)
            {
                global $wpdb;
                // Prefix and Map Table Names for raw queries
                $map = [
                    'support_requests' => 'request',
                    'support_issues' => 'issue',
                    'issue_attachments' => 'attachment',
                    'ticket_queue' => 'queue',
                    'clients_cache' => 'client_cache',
                    'client_domains' => 'client_domain',
                    'client_jira_maps' => 'client_jira_map',
                ];

                foreach ($map as $old => $new) {
                    $fullTable = $wpdb->prefix . 'kgr_support_' . $new;
                    $sql = (string) preg_replace('/\b' . preg_quote($old, '/') . '\b/', $fullTable, $sql);
                }
                $wpdb->query($sql);
                return $this->prepare($sql);
            }

            public function lastInsertId()
            {
                global $wpdb;
                return $wpdb->insert_id;
            }

            public function exec(string $sql)
            {
                global $wpdb;
                return $wpdb->query($sql);
            }
        };

        return self::$wpdb_wrapper;
    }
}
