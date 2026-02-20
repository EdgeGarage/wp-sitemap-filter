<?php
/**
 * Plugin Name: WP Sitemap Filter
 * Plugin URI: https://scheibl-partner.com/edgegarage/wp-sitemap-filter/
 * Description: Control which posts, pages, taxonomies and users appear in the WordPress core XML sitemap. Disable sitemap providers and exclude individual items.
 * Version: 3.5.1
 * Author: Michael Scheibl
 * Author URI: https://scheibl-partner.com
 * Donate link: https://ko-fi.com/scheibl
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-sitemap-filter
 * GitHub Plugin URI: https://github.com/EdgeGarage/wp-sitemap-filter
 * Primary Branch: main
 */

if (!defined('ABSPATH')) exit;

class WP_Sitemap_Filter_Plugin {

    private $excluded_option    = 'wp_xsf_excluded_items';
    private $disabled_option    = 'wp_xsf_disabled_providers';
    private $last_update_option = 'wp_xsf_last_update';

    public function __construct() {
        add_action('admin_menu', array($this, 'register_admin_page'));
        add_action('admin_init', array($this, 'handle_form_submit'));

        add_filter('wp_sitemaps_add_provider', array($this, 'filter_providers'), 10, 2);
        add_filter('wp_sitemaps_posts_query_args', array($this, 'filter_posts'), 10, 2);
        add_filter('wp_sitemaps_taxonomies_query_args', array($this, 'filter_taxonomies'), 10, 2);
        add_filter('wp_sitemaps_users_query_args', array($this, 'filter_users'), 10, 1);
    }

    public function register_admin_page() {
        add_menu_page(
            'WP Sitemap Filter',
            'WP Sitemap Filter',
            'manage_options',
            'wp-sitemap-filter',
            array($this, 'render_admin_page'),
            'dashicons-filter',
            80
        );
    }

    public function handle_form_submit() {
        if (!isset($_POST['wp_xsf_nonce'])) return;
        if (!wp_verify_nonce($_POST['wp_xsf_nonce'], 'wp_xsf_save')) return;

        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'pages';

        // 1. Merge excluded items
        $old_excluded = get_option($this->excluded_option, array());
        $new_excluded = isset($_POST['excluded']) ? $_POST['excluded'] : array();

        if (is_array($new_excluded)) {
            foreach ($new_excluded as $group => $types) {
                if (!is_array($types)) continue;
                foreach ($types as $type => $ids) {
                    if (!is_array($ids)) continue;
                    $old_excluded[$group][$type] = array_map('intval', $ids);
                }
            }
        }

        update_option($this->excluded_option, $old_excluded);

        // 2. Providers (only when on providers tab)
        if ($current_tab === 'providers') {
            $new_disabled = isset($_POST['disabled_providers']) ? (array)$_POST['disabled_providers'] : array();
            update_option($this->disabled_option, array_map('sanitize_text_field', $new_disabled));
        }

        // 3. Update last update timestamp
        update_option($this->last_update_option, current_time('mysql'));
    }

    public function render_admin_page() {
        $tab         = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'pages';
        $excluded    = get_option($this->excluded_option, array());
        $disabled    = get_option($this->disabled_option, array());
        $last_update = get_option($this->last_update_option, 'Never');
        $sitemap_url = home_url('/wp-sitemap.xml');

        $tabs = array(
            'pages'      => 'Pages',
            'posts'      => 'Posts',
            'categories' => 'Categories',
            'tags'       => 'Tags',
            'users'      => 'Users',
            'providers'  => 'Providers',
        );
        ?>
        <div class="wrap">
            <h1>WP Sitemap Filter</h1>
            <p>Control which content appears in the WordPress XML sitemap.</p>

            <div style="padding:12px; background:#f8f8f8; border:1px solid #ddd; margin:15px 0;">
                <strong>Sitemap Status</strong><br>
                Last update: <strong><?php echo esc_html($last_update); ?></strong><br>
                Sitemap URL: <a href="<?php echo esc_url($sitemap_url); ?>" target="_blank"><?php echo esc_html($sitemap_url); ?></a><br>
                <?php if (!empty($disabled)): ?>
                    Disabled providers: <strong><?php echo esc_html(implode(', ', (array)$disabled)); ?></strong>
                <?php else: ?>
                    All providers active.
                <?php endif; ?>
            </div>

            <h2 class="nav-tab-wrapper">
                <?php foreach ($tabs as $key => $label): ?>
                    <?php
                    $class = ($tab === $key) ? 'nav-tab nav-tab-active' : 'nav-tab';
                    $url   = admin_url('admin.php?page=wp-sitemap-filter&tab=' . $key);
                    ?>
                    <a href="<?php echo esc_url($url); ?>" class="<?php echo esc_attr($class); ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <form method="post">
                <?php wp_nonce_field('wp_xsf_save', 'wp_xsf_nonce'); ?>

                <?php
                switch ($tab) {
                    case 'pages':      $this->tab_pages($excluded); break;
                    case 'posts':      $this->tab_posts($excluded); break;
                    case 'categories': $this->tab_categories($excluded); break;
                    case 'tags':       $this->tab_tags($excluded); break;
                    case 'users':      $this->tab_users($excluded); break;
                    case 'providers':  $this->tab_providers($disabled); break;
                }
                ?>

                <p><button class="button button-primary">Save changes</button></p>
            </form>

            <p style="margin-top:30px; font-size:12px; opacity:0.8;">
                Developed by <a href="https://scheibl-partner.com" target="_blank">Scheibl‑Partner.com</a>.
                Support the project on <a href="https://ko-fi.com/scheibl" target="_blank">Ko‑fi</a>.
            </p>
        </div>
        <?php
    }

    private function render_table($items, $columns, $excluded_ids, $group, $type) {
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Exclude</th>';
        foreach ($columns as $key => $label) {
            echo '<th>' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if (empty($items)) {
            echo '<tr><td colspan="' . (count($columns) + 1) . '">No entries found.</td></tr>';
        } else {
            foreach ($items as $item) {
                $id      = isset($item['id']) ? intval($item['id']) : 0;
                $checked = in_array($id, $excluded_ids, true) ? 'checked' : '';
                echo '<tr>';
                echo '<td><input type="checkbox" name="excluded[' . esc_attr($group) . '][' . esc_attr($type) . '][]" value="' . esc_attr($id) . '" ' . $checked . '></td>';
                foreach ($columns as $key => $label) {
                    $val = isset($item[$key]) ? $item[$key] : '';
                    echo '<td>' . esc_html($val) . '</td>';
                }
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
    }

    private function tab_pages($excluded) {
        $excluded_ids = isset($excluded['posts']['page']) ? (array)$excluded['posts']['page'] : array();
        $pages        = get_pages(array('post_status' => 'publish'));

        $items = array();
        foreach ($pages as $p) {
            $items[] = array(
                'id'    => $p->ID,
                'title' => $p->post_title,
                'date'  => $p->post_date,
                'type'  => 'page',
            );
        }

        echo '<h2>Exclude pages from sitemap</h2>';
        $columns = array(
            'title' => 'Title',
            'id'    => 'ID',
            'date'  => 'Date',
            'type'  => 'Type',
        );
        $this->render_table($items, $columns, $excluded_ids, 'posts', 'page');
    }

    private function tab_posts($excluded) {
        $excluded_ids = isset($excluded['posts']['post']) ? (array)$excluded['posts']['post'] : array();
        $posts        = get_posts(array(
            'post_type'      => 'post',
            'posts_per_page' => 200,
            'post_status'    => 'publish',
        ));

        $items = array();
        foreach ($posts as $p) {
            $items[] = array(
                'id'    => $p->ID,
                'title' => $p->post_title,
                'date'  => $p->post_date,
                'type'  => 'post',
            );
        }

        echo '<h2>Exclude posts from sitemap</h2>';
        $columns = array(
            'title' => 'Title',
            'id'    => 'ID',
            'date'  => 'Date',
            'type'  => 'Type',
        );
        $this->render_table($items, $columns, $excluded_ids, 'posts', 'post');
    }

    private function tab_categories($excluded) {
        $excluded_ids = isset($excluded['taxonomies']['category']) ? (array)$excluded['taxonomies']['category'] : array();
        $terms        = get_terms(array(
            'taxonomy'   => 'category',
            'hide_empty' => false,
        ));

        if (is_wp_error($terms)) {
            $terms = array();
        }

        $items = array();
        foreach ($terms as $t) {
            $items[] = array(
                'id'    => $t->term_id,
                'title' => $t->name,
                'slug'  => $t->slug,
                'type'  => 'category',
            );
        }

        echo '<h2>Exclude categories from sitemap</h2>';
        $columns = array(
            'title' => 'Name',
            'id'    => 'ID',
            'slug'  => 'Slug',
            'type'  => 'Type',
        );
        $this->render_table($items, $columns, $excluded_ids, 'taxonomies', 'category');
    }

    private function tab_tags($excluded) {
        $excluded_ids = isset($excluded['taxonomies']['post_tag']) ? (array)$excluded['taxonomies']['post_tag'] : array();
        $terms        = get_terms(array(
            'taxonomy'   => 'post_tag',
            'hide_empty' => false,
        ));

        if (is_wp_error($terms)) {
            $terms = array();
        }

        $items = array();
        foreach ($terms as $t) {
            $items[] = array(
                'id'    => $t->term_id,
                'title' => $t->name,
                'slug'  => $t->slug,
                'type'  => 'post_tag',
            );
        }

        echo '<h2>Exclude tags from sitemap</h2>';
        $columns = array(
            'title' => 'Name',
            'id'    => 'ID',
            'slug'  => 'Slug',
            'type'  => 'Type',
        );
        $this->render_table($items, $columns, $excluded_ids, 'taxonomies', 'post_tag');
    }

    private function tab_users($excluded) {
        $excluded_ids = isset($excluded['users']['users']) ? (array)$excluded['users']['users'] : array();
        $users        = get_users(array(
            'fields' => array('ID', 'user_login', 'user_email'),
        ));

        $items = array();
        foreach ($users as $u) {
            $items[] = array(
                'id'    => $u->ID,
                'title' => $u->user_login,
                'email' => $u->user_email,
                'type'  => 'user',
            );
        }

        echo '<h2>Exclude users from sitemap</h2>';
        $columns = array(
            'title' => 'Login',
            'id'    => 'ID',
            'email' => 'Email',
            'type'  => 'Type',
        );
        $this->render_table($items, $columns, $excluded_ids, 'users', 'users');
    }

    private function tab_providers($disabled) {
        $providers = array(
            'posts'      => 'Posts provider (posts, pages, custom post types)',
            'taxonomies' => 'Taxonomies provider (categories, tags, custom taxonomies)',
            'users'      => 'Users provider',
        );

        echo '<h2>Disable sitemap providers</h2>';
        echo '<p>If a provider is disabled, its sitemap will not be generated.</p>';
        echo '<ul>';
        foreach ($providers as $key => $label) {
            $checked = in_array($key, (array)$disabled, true) ? 'checked' : '';
            echo '<li><label><input type="checkbox" name="disabled_providers[]" value="' . esc_attr($key) . '" ' . $checked . '> ' . esc_html($label) . '</label></li>';
        }
        echo '</ul>';
    }

    public function filter_providers($provider, $name) {
        $disabled = get_option($this->disabled_option, array());
        if (in_array($name, (array)$disabled, true)) {
            return false;
        }
        return $provider;
    }

    public function filter_posts($args, $post_type) {
        $excluded = get_option($this->excluded_option, array());
        if (!empty($excluded['posts'][$post_type])) {
            $args['post__not_in'] = (array)$excluded['posts'][$post_type];
        }
        return $args;
    }

    public function filter_taxonomies($args, $taxonomy) {
        $excluded = get_option($this->excluded_option, array());
        if (!empty($excluded['taxonomies'][$taxonomy])) {
            $args['exclude'] = (array)$excluded['taxonomies'][$taxonomy];
        }
        return $args;
    }

    public function filter_users($args) {
        $excluded = get_option($this->excluded_option, array());
        if (!empty($excluded['users']['users'])) {
            $args['exclude'] = (array)$excluded['users']['users'];
        }
        return $args;
    }
}

new WP_Sitemap_Filter_Plugin();
