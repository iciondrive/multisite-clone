<?php
/**
 * Plugin Name: Ici On Drive - Multisite clone
 * Description: Clones an existing site into a new site in a multi-site installation. Plugin developed for the "Ici On Drive" platform.
 * Plugin URI: https://github.com/iciondrive/multisite-clone
 * Author: Thomas Navarro
 * Version: 1.0.0
 * Network: true
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Author URI: http://github.com/thomasnavarro.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('IOD_Multisite_Clone')) {
    class IOD_Multisite_Clone
    {
        /**
         * @var int Original site ID
         */
        private $from_site_id = 91;

        /**
         * @var int New site ID
         */
        private $to_site_id = 0;

        /**
         * @var string Original site prefix
         */
        private $from_site_prefix = '';

        /**
         * @var string New site prefix
         */
        private $to_site_prefix = '';

        /**
         * @var array Original path to upload dir
         */
        private $from_dir_path = '';
        /**
         * @var array New path to upload dir
         */
        private $to_dir_path = '';

        /**
         * @var string Post type name
         */
        private $post_type = '';

        /**
         * @var string URL of the new site
         */
        private $home_url = '';

        /**
         * @var array Arguments for the new site
         */
        private $args = [];

        /**
         * @var array Options fields for the new site
         */
        private $options = [];

        /**
         * @var array Files to copy for the new site
         */
        public $files_to_copy = [];

        public function __construct()
        {
            // WP hooks
            add_action('init', [$this, 'init_hook']);

            $this->post_type = 'store_request';

            $this->uniquid = uniqid();

            // Default args
            $this->args = [
                'domain' => get_network()->domain,
                'path' => '/mon-commerce-'.$this->uniquid,
                'title' => 'Mon commerce ('.$this->uniquid.')',
                'user_id' => 2,
                'options' => ['public' => 1],
            ];
        }

        public function init_hook()
        {
            if (is_user_logged_in()) {
                // Custom hook "duplicate_site"
                add_action('admin_action_duplicate_site', [$this, 'duplicate_site']);

                // Add row actions "Vérifier", "Rejeter", "Créer"
                add_filter('post_row_actions', [$this, 'custom_action_row'], 10, 2);

                // Fix: drop all tables when remove site (plugins too)
                add_filter('wpmu_drop_tables', [$this, 'database_drop_tables'], 10, 2);

                // Update default role by shop_manager
                add_action('wpmu_new_blog', [$this, 'update_role'], 10, 2);

                add_action('wp_new_user_notification_email', [$this, 'new_user_notification_email'], 10, 3);
            }
        }

        public function custom_action_row($actions, $post)
        {
            if ($this->post_type == $post->post_type) {
                if ('trash' != $post->post_status) {
                    unset($actions['view']); // Voir
                    unset($actions['preview']); // Prévisualiser
                    unset($actions['inline hide-if-no-js']); // Modification rapide

                    $actions['edit'] = str_replace('Modifier', 'Vérifier les informations', $actions['edit']);
                    $actions['trash'] = str_replace('Corbeille', 'Rejeter la demande', $actions['trash']);
                    $actions['approved'] = '<a href="'.wp_nonce_url(admin_url('/edit.php?post='.$post->ID.'&action=duplicate_site&post_type=store_request'), 'duplicate_site_'.$post->ID).'" style="color:#059669;" aria-label="Créer la boutique" rel="permalink">Créer la boutique</a>';

                    if ('publish' == $post->post_status) {
                        $actions['approved'] = '<a href="" style="pointer-events:none;color:#059669;" aria-label="Créer la boutique" rel="permalink"><del>Créer la boutique</del></a>';
                    }
                }
            }

            return $actions;
        }

        public function duplicate_site()
        {
            if (empty($_REQUEST['post'])) {
                wp_die(__('Aucun site à dupliquer n\'a été fourni !'));
            }

            // Get Post ID
            $post_id = !empty($_REQUEST['post']) ? absint($_REQUEST['post']) : '';
            check_admin_referer('duplicate_site_'.$post_id);

            // Get Post
            $post = get_post($post_id);
            if (false === $post) {
                wp_die(sprintf(__('La création du site a échoué, le site original n\'a pas pu être trouvé: %s'), $post_id));
            }

            // Get ACF Options
            $this->options = get_fields($post_id);
            if (false === $this->options) {
                wp_die(sprintf(__('La création du site a échoué, Aucune options n\'a pas pu être trouvé: %s'), $post_id));
            }

            // Create user
            $this->user_id = $this->create_user();
            if (false === $this->user_id) {
                wp_die(__('Il y a eu une erreur lors de la création de l\'utilisateur.'));
            }

            // Create site
            $this->to_site_id = $this->create_site();
            if (false === $this->to_site_id) {
                wp_die(__('Il y a eu une erreur lors de la création du site.'));
            }

            // Define home URL
            $this->home_url = get_home_url($this->to_site_id);

            // Define user
            IOD_Helpers::maybe_set_primary_site($this->user_id, $this->to_site_id);

            // Bypass limit server if possible
            IOD_Helpers::bypass_server_limit();

            // Clones data from the original site
            $this->database_copy_tables();
            $this->database_set_options();
            $this->database_update_data();

            $this->copy_files();

            // Update data new site
            switch_to_blog($this->to_site_id);

            // Update attachment
            $attachments = $this->set_attachment();
            $this->options['business_customization']['logo'] = $attachments['logo'];
            $this->options['business_customization']['cover'] = $attachments['cover'];

            // Update options
            $this->business_update_fields();
            $this->woocommerce_update_options();

            restore_current_blog();

            // Update post status
            wp_update_post(['ID' => $post->ID, 'post_status' => 'publish']);
            wp_redirect(admin_url('/edit.php?post_type=store_request'));

            exit;
        }

        private function create_user()
        {
            $user_name = str_replace('-', '_', sanitize_title($this->options['business_details']['name']));
            $password = wp_generate_password(12, false);
            $email = $this->options['personal_details']['email'];

            $user_id = email_exists($email);

            if (!$user_id) {
                $user_id = username_exists($this->args['domain']);
                if ($user_id) {
                    wp_die(__('Le nom d\'utilisateur entré est en conflit avec un nom d\'utilisateur existant.'));
                }

                // Create user
                $user_id = wpmu_create_user($user_name, $password, $email);

                wp_new_user_notification($user_id, $password);
            }

            return !is_wp_error($user_id) ? $user_id : false;
        }

        public function new_user_notification_email($wp_new_user_notification_email, $user, $blogname)
        {
            $message = get_site_option('welcome_email');
            $key = get_password_reset_key($user);

            $wp_new_user_notification_email['headers'] = 'From: Niort - ICI ON DRIVE <no-reply@iciondrive.fr>';
            $wp_new_user_notification_email['subject'] = 'Bienvenue sur ICI ON DRIVE';
            $wp_new_user_notification_email['message'] = str_replace([
                'USERNAME',
                'SITE_NAME',
                'BLOG_URL',
                'CREATE_PASSWORD',
            ], [
                $user->user_login,
                $blogname,
                network_site_url(),
                network_site_url("mon-espace?action=rp&key=$key&login=".rawurlencode($user->user_login), 'login'),
            ],
            $message);

            return $wp_new_user_notification_email;
        }

        private function create_site()
        {
            global $wpdb;

            $this->args['title'] = $this->options['business_details']['name'] ?? 'Mon commerce';
            $this->args['path'] = '/'.sanitize_title($this->args['title']);
            $this->args['user_id'] = $this->user_id;

            $site_id = domain_exists($this->args['domain'], $this->args['path']);
            if ($site_id) {
                wp_die(__('Le site est déjà existant.'));
            }

            $wpdb->hide_errors();
            $site_id = wpmu_create_blog($this->args['domain'], $this->args['path'], $this->args['title'], $this->args['user_id'], $this->args['options']);
            $wpdb->show_errors();

            return !is_wp_error($site_id) ? $site_id : false;
        }

        private function database_copy_tables()
        {
            global $wpdb;

            $this->to_site_prefix = $wpdb->get_blog_prefix($this->to_site_id);
            $this->from_site_prefix = $wpdb->get_blog_prefix($this->from_site_id);

            if ($this->from_site_id === get_current_site()->blog_id) {
                $from_site_tables = IOD_Helpers::get_primary_tables($this->from_site_prefix);
            } else {
                $sql_query = $wpdb->prepare('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME LIKE \'%s\'', $wpdb->esc_like($this->from_site_prefix).'%');
                $from_site_tables = IOD_Helpers::do_sql_query($sql_query, 'col');
            }

            foreach ($from_site_tables as $table) {
                $table_name = $this->to_site_prefix.substr($table, strlen($this->from_site_prefix));

                // Drop table if exists
                IOD_Helpers::do_sql_query('DROP TABLE IF EXISTS `'.$table_name.'`');

                // Create new table from source table
                IOD_Helpers::do_sql_query('CREATE TABLE IF NOT EXISTS `'.$table_name.'` LIKE `'.$table.'`');

                // Populate database with data from source table
                IOD_Helpers::do_sql_query('INSERT `'.$table_name.'` SELECT * FROM `'.$table.'`');
            }
        }

        private function database_set_options()
        {
            $site_options = [
                'siteurl' => $this->home_url,
                'home' => $this->home_url,
                'blogname' => $this->args['title'],
                'admin_email' => get_userdata($this->args['user_id'])->user_email,
            ];

            switch_to_blog($this->to_site_id);
            foreach ($site_options as $option_name => $option_value) {
                update_option($option_name, $option_value);
            }
            restore_current_blog();
        }

        private function database_update_data()
        {
            global $wpdb;

            switch_to_blog($this->from_site_id);

            $dir = wp_upload_dir();
            $from_upload_url = str_replace(network_site_url(), get_bloginfo('url').'/', $dir['baseurl']);
            $from_blog_url = get_blog_option($this->from_site_id, 'siteurl');

            switch_to_blog($this->to_site_id);

            $dir = wp_upload_dir();
            $to_upload_url = str_replace(network_site_url(), get_bloginfo('url').'/', $dir['baseurl']);
            $to_blog_url = get_blog_option($this->to_site_id, 'siteurl');

            restore_current_blog();

            $tables = [];

            $results = IOD_Helpers::do_sql_query('SHOW TABLES LIKE \''.$wpdb->esc_like($this->to_site_prefix).'%\'', 'col');

            foreach ($results as $key => $value) {
                $tables[str_replace($this->to_site_prefix, '', $value)] = [];
            }

            foreach (array_keys($tables) as $table) {
                $results = IOD_Helpers::do_sql_query('SHOW COLUMNS FROM `'.$this->to_site_prefix.$table.'`', 'col');
                $columns = [];

                foreach ($results as $key => $value) {
                    $columns[] = $value;
                }

                $tables[$table] = $columns;
            }

            $default_tables = IOD_Helpers::get_default_tables();
            if (!get_blog_option($this->from_site_id, 'link_manager_enabled', 0)) {
                unset($default_tables['links']);
            }

            foreach ($default_tables as $table => $fields) {
                $tables[$table] = $fields;
            }

            $string_to_replace = [
                $from_upload_url => $to_upload_url,
                $from_blog_url => $to_blog_url,
                $this->from_site_prefix => $this->to_site_prefix,
            ];

            foreach ($tables as $table => $fields) {
                foreach ($string_to_replace as $from_string => $to_string) {
                    IOD_Helpers::update($this->to_site_prefix.$table, $fields, $from_string, $to_string);
                }
            }

            restore_current_blog();
            refresh_blog_details($this->to_site_id);
        }

        public function database_drop_tables($tables, $site_id)
        {
            if (empty($site_id) || 1 == $site_id || $site_id != $GLOBALS['blog_id']) {
                return $tables;
            }

            global $wpdb;
            $prefix = $wpdb->get_blog_prefix($site_id);

            $sql_query = $wpdb->prepare('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME LIKE \'%s\'', $wpdb->esc_like($this->from_site_prefix).'%');
            $results = IOD_Helpers::do_sql_query($sql_query, 'col');

            foreach ($results as $table) {
                $table_name = $prefix.substr($table, strlen($prefix));
                IOD_Helpers::do_sql_query('DROP TABLE IF EXISTS `'.$table_name.'`');
            }

            return $tables;
        }

        public function update_role($blog_id, $user_id)
        {
            switch_to_blog($blog_id);
            $user = new WP_User($user_id);
            if ($user->exists()) {
                $user->set_role('shop_manager');
            }
            restore_current_blog();
        }

        private function business_update_fields()
        {
            // Business post ID === 6
            $business_page_id = 6;

            foreach ($this->options as $group_name => $group_fields) {
                update_field($group_name, $group_fields, $business_page_id);
            }
        }

        private function woocommerce_update_options()
        {
            // Stock
            update_option('woocommerce_stock_email_recipient', $this->options['personal_details']['email'] ?? null);

            // Adress
            $address = $this->options['business_details']['address'];
            update_option('woocommerce_store_postcode', $address['post_code'] ?? null);
            update_option('woocommerce_store_city', $address['city'] ?? null);
            update_option('woocommerce_store_address', $address['name'] ?? null);

            // E-mail
            update_option('woocommerce_email_from_address', $this->options['personal_details']['email'] ?? null);
            update_option('woocommerce_email_from_name', $this->options['business_details']['name'] ?? null);
        }

        private function copy_files()
        {
            switch_to_blog(get_current_network_id());
            $favicon_id = get_option('site_icon');
            $iod_logo_id = get_theme_mod('custom_logo');

            $this->files_to_copy = [
                'cover' => $this->options['business_customization']['cover'],
                'logo' => $this->options['business_customization']['logo'],
                'favicon' => acf_get_attachment($favicon_id),
                'iod_logo' => acf_get_attachment($iod_logo_id),
            ];

            $wp_upload_info = wp_upload_dir();
            $this->from_dir_path = str_replace(' ', '\\ ', trailingslashit($wp_upload_info['basedir']));

            switch_to_blog($this->to_site_id);
            $wp_upload_info = wp_upload_dir();
            $this->to_dir_path = str_replace(' ', '\\ ', trailingslashit($wp_upload_info['basedir']));
            restore_current_blog();

            if (isset($this->to_dir_path) && !IOD_Helpers::init_directory($this->to_dir_path)) {
                wp_die('Erreur lors de la copie des fichiers !');
            }
            IOD_Helpers::copy($this->from_dir_path, $this->to_dir_path, $this->files_to_copy);

            return true;
        }

        private function set_attachment()
        {
            $attachments = false;

            if (!empty($this->files_to_copy)) {
                foreach ($this->files_to_copy as $key => $file_data) {
                    $wp_filetype = $file_data['mime_type'];
                    $wp_upload_dir = wp_upload_dir();
                    $filename = $wp_upload_dir['path'].'/'.$file_data['filename'];
                    $attachment = [
                        'guid' => $wp_upload_dir['url'].'/'.basename($filename),
                        'post_mime_type' => $wp_filetype,
                        'post_title' => preg_replace('/\.[^.]+$/', '', basename($filename)),
                        'post_content' => '',
                        'post_status' => 'inherit',
                    ];
                    require_once ABSPATH.'wp-admin/includes/image.php';
                    $attach_id = wp_insert_attachment($attachment, $filename);
                    $attachments[$key] = acf_get_attachment($attach_id);
                    $attach_data = wp_generate_attachment_metadata($attach_id, $filename);
                    wp_update_attachment_metadata($attach_id, $attach_data);
                }
            }

            return $attachments;
        }
    }

    // Instantiate
    include_once 'class-iciondrive-helpers.php';
    new IOD_Multisite_Clone();
}
