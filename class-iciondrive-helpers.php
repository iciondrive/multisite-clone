<?php

if (!class_exists('IOD_Helpers')) {
    /**
     * Class IOD_Helpers.
     */
    class IOD_Helpers
    {
        public static function maybe_set_primary_site($user_id, $site_id)
        {
            if (!is_super_admin($user_id) && !get_user_meta('primary_blog', $user_id, true)) {
                update_user_meta($user_id, 'primary_blog', $site_id);
            }
        }

        public static function bypass_server_limit()
        {
            @ini_set('memory_limit', '1024M');
            @ini_set('max_execution_time', '0');
        }

        public static function get_primary_tables()
        {
            $default_tables = array_keys(self::get_default_tables());

            foreach ($default_tables as $key => $default_table) {
                $default_tables[$key] = $from_site_prefix.$default_table;
            }

            return $default_tables;
        }

        public static function get_default_tables()
        {
            return [
                'terms' => [],
                'termmeta' => [],
                'term_taxonomy' => [],
                'term_relationships' => [],
                'commentmeta' => [],
                'comments' => [],
                'postmeta' => ['meta_value'],
                'posts' => ['post_content', 'guid'],
                'links' => ['link_url',     'link_image'],
                'options' => ['option_name',  'option_value'],
            ];
        }

        public static function do_sql_query($sql_query, $type = '')
        {
            global $wpdb;

            $wpdb->hide_errors();

            switch ($type) {
                case 'col':
                    $results = $wpdb->get_col($sql_query);
                    break;
                case 'row':
                    $results = $wpdb->get_row($sql_query);
                    break;
                case 'var':
                    $results = $wpdb->get_var($sql_query);
                    break;
                case 'results':
                    $results = $wpdb->get_results($sql_query, ARRAY_A);
                    break;
                default:
                    $results = $wpdb->query($sql_query);
                    break;
            }

            $wpdb->show_errors();

            return $results;
        }

        public static function update($table, $fields, $from_string, $to_string)
        {
            global $wpdb;

            if (empty($fields) || !is_array($fields)) {
                return;
            }

            foreach ($fields as $field) {
                $sql_query = $wpdb->prepare('SELECT `'.$field.'` FROM `'.$table.'` WHERE `'.$field.'` LIKE "%s" ', '%'.$wpdb->esc_like($from_string).'%');
                $results = self::do_sql_query($sql_query, 'results', false);

                if (empty($results)) {
                    continue;
                }

                $update = 'UPDATE `'.$table.'` SET `'.$field.'` = "%s" WHERE `'.$field.'` = "%s"';

                foreach ($results as $row) {
                    $old_value = $row[$field];
                    $new_value = self::try_replace($row, $field, $from_string, $to_string);
                    $sql_query = $wpdb->prepare($update, $new_value, $old_value);
                    $results = self::do_sql_query($sql_query);
                }
            }
        }

        public static function replace($value, $from_string, $to_string)
        {
            $new = $value;

            if (is_string($value)) {
                $pos = strpos($value, $to_string);
                if (false === $pos) {
                    $new = str_replace($from_string, $to_string, $value);
                }
            }

            return $new;
        }

        public static function replace_recursive($value, $from_string, $to_string)
        {
            $unset = [];

            if (is_array($value)) {
                foreach (array_keys($value) as $key) {
                    $value[$key] = self::try_replace($value, $key, $from_string, $to_string);
                }
            } else {
                $value = self::replace($value, $from_string, $to_string);
            }

            foreach ($unset as $key) {
                unset($value[$key]);
            }

            return $value;
        }

        public static function try_replace($row, $field, $from_string, $to_string)
        {
            if (is_serialized($row[$field])) {
                $double_serialize = false;
                $row[$field] = @unserialize($row[$field]);

                if (is_serialized($row[$field])) {
                    $row[$field] = @unserialize($row[$field]);
                    $double_serialize = true;
                }

                if (is_array($row[$field])) {
                    $row[$field] = self::replace_recursive($row[$field], $from_string, $to_string);
                } elseif (is_object($row[$field]) || $row[$field] instanceof __PHP_Incomplete_Class) {
                    $array_object = (array) $row[$field];
                    $array_object = self::replace_recursive($array_object, $from_string, $to_string);

                    foreach ($array_object as $key => $field) {
                        $row[$field]->$key = $field;
                    }
                } else {
                    $row[$field] = self::replace($row[$field], $from_string, $to_string);
                }

                $row[$field] = serialize($row[$field]);

                if ($double_serialize) {
                    $row[$field] = serialize($row[$field]);
                }
            } else {
                $row[$field] = self::replace($row[$field], $from_string, $to_string);
            }

            return $row[$field];
        }

        public static function copy($src, $dst, $files_to_copy)
        {
            $src = rtrim($src, '/');
            $dst = rtrim($dst, '/');
            $dir = opendir($src);
            @mkdir($dst);

            while (false !== ($file = readdir($dir))) {
                if (('.' != $file) && ('..' != $file) && ('sites' != $file)) {
                    if (is_dir($src.'/'.$file)) {
                        self::copy($src.'/'.$file, $dst.'/'.$file, $files_to_copy);
                    } else {
                        foreach ($files_to_copy as $key => $file_data) {
                            $filename = preg_replace('/\\.[^.\\s]{3,4}$/', '', $file_data['filename']);
                            if (false !== strpos($file, $filename)) {
                                copy($src.'/'.$file, $dst.'/'.$file);
                            }
                        }
                    }
                }
            }

            closedir($dir);
        }

        public static function init_directory($path)
        {
            $e = error_reporting(0);

            if (!file_exists($path)) {
                return mkdir($path, 0777);
            } elseif (is_dir($path)) {
                if (!is_writable($path)) {
                    return chmod($path, 0777);
                }

                return true;
            }

            error_reporting($e);

            return false;
        }

        public static function remove_directory($dir)
        {
            if (is_dir($dir)) {
                $objects = scandir($dir);
                foreach ($objects as $object) {
                    if ('.' != $object && '..' != $object) {
                        if ('dir' == filetype($dir.'/'.$object)) {
                            self::remove_dir($dir.'/'.$object);
                        } else {
                            unlink($dir.'/'.$object);
                        }
                    }
                }
                reset($objects);
                rmdir($dir);
            }
        }
    }
}
