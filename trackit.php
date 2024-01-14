<?php
/**
* Plugin Name: Trackit
* Description: Track generic page visits and custom elements.
* Version: 1.0
* Author: Pau Serrat Gutiérrez
* Text Domain: trackit
*/

if (!defined('ABSPATH')) {
  exit;
}

class Trackit {
  const DB_TABLE_TRACKING = 'trackit_trackings';
  const OPTION_TRACK_ROLES = 'trackit_option_track_roles';
  const OPTION_ERASE_UNINSTALL = 'trackit_option_erase_uninstall';

  public function __construct() {
    add_action('admin_menu', array($this, 'add_admin_menu'));
    add_action('admin_init', array($this, 'register_settings'));
    add_action('admin_post_delete_tracking_data', array($this, 'delete_tracking_data'));

    register_activation_hook(__FILE__, array($this, 'activate_plugin'));
    register_uninstall_hook(__FILE__, array('Trackit', 'uninstall_plugin'));

    add_action('wp', array($this, 'trackit_track_visit'));
  }

  public function activate_plugin() {
    global $wpdb;
    $table_name = $wpdb->prefix . self::DB_TABLE_TRACKING;
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
      id bigint(20) NOT NULL AUTO_INCREMENT,
      time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
      source_url varchar(255) DEFAULT '' NOT NULL,
      source_custom_element varchar(55) DEFAULT '' NOT NULL,
      PRIMARY KEY  (id)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
  }

  public function add_admin_menu() {
    add_menu_page('Trackit', 'Trackit', 'manage_options', 'trackit-dashboard', array($this, 'dashboard_page'), 'dashicons-chart-area', 6);
    add_submenu_page('trackit-dashboard', 'Dashboard', 'Dashboard', 'manage_options', 'trackit-dashboard', array($this, 'dashboard_page'));
    add_submenu_page('trackit-dashboard', 'Settings', 'Settings', 'manage_options', 'trackit-settings', array($this, 'settings_page'));
  }

  public function dashboard_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . self::DB_TABLE_TRACKING;

    $total_24h = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE time > NOW() - INTERVAL 1 DAY");
    $total_30d = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE time > NOW() - INTERVAL 30 DAY");
  
    echo '<div class="wrap">';
    echo '<h1>Trackit Dashboard</h1>';

    echo '<h2>Daily Stats</h2>';
    echo '<div style="display: flex; justify-content: space-around; column-gap: 20px; margin-bottom: 20px;">';
    echo '<div style="display: flex; flex-direction: column; flex-grow: 1; row-gap: 10px; align-items: center; justify-content: center; border: 1px solid lightgray; border-radius: 4px; padding: 20px;">';
    echo "<span style=\"font-size: 20px;\">{$total_24h}</span><span>24 hours</span></div>";
    echo '<div style="display: flex; flex-direction: column; flex-grow: 1; row-gap: 10px; align-items: center; justify-content: center; border: 1px solid lightgray; border-radius: 4px; padding: 20px;">';
    echo "<span style=\"font-size: 20px;\">{$total_30d}</span><span>30 days</span></div>";
    echo '</div>';

    echo '<h2>All Stats</h2>';

    // Pagination
    $perPageResults = 25;
    $pageCurrent = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
    $countTotal = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $pagesTotal = ceil($countTotal / $perPageResults);
    $offset = ($pageCurrent - 1) * $perPageResults;
  
    $trackings = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name ORDER BY id DESC LIMIT %d OFFSET %d", $perPageResults, $offset));

    echo '<div class="pagination" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">';
    echo "<span>Showing $perPageResults of $countTotal total results.</span>";
    echo '<div>';
    echo ($pageCurrent > 1) ? '<a href="?page=trackit-dashboard&p=1" class="button">« First</a> ' : '<span class="button button-disabled">« First</span> ';
    echo ($pageCurrent > 1) ? '<a href="?page=trackit-dashboard&p=' . ($pageCurrent - 1) . '" class="button">‹ Previous</a> ' : '<span class="button button-disabled">‹ Previous</span> ';
  
    $start = max(1, min($pageCurrent - 2, $pagesTotal - 4));
    $end = min($pagesTotal, max($pageCurrent + 2, 5));
  
    for ($i = $start; $i <= $end; $i++) {
      if ($i == $pageCurrent) {
        echo "<span class=\"button button-primary\">$i</span> ";
      } else {
        echo "<a href=\"?page=trackit-dashboard&p=$i\" class=\"button\">$i</a> ";
      }
    }
  
    echo ($pageCurrent < $pagesTotal) ? '<a href="?page=trackit-dashboard&p=' . ($pageCurrent + 1) . '" class="button">Next ›</a> ' : '<span class="button button-disabled">Next ›</span> ';
    echo ($pageCurrent < $pagesTotal) ? '<a href="?page=trackit-dashboard&p=' . $pagesTotal . '" class="button">Last »</a>' : '<span class="button button-disabled">Last »</span>';
    echo '</div></div>';

    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>ID</th><th>Day</th><th>Time</th><th>Source URL</th><th>Source Custom Element</th></tr></thead>';
    echo '<tbody>';
    foreach ($trackings as $tracking) {
      $date = date('d-m-Y', strtotime($tracking->time));
      $time = date('H:i:s', strtotime($tracking->time));
      echo "<tr><td>{$tracking->id}</td><td>{$date}</td><td>{$time}</td><td>{$tracking->source_url}</td><td>{$tracking->source_custom_element}</td></tr>";
    }
    echo '</tbody></table></div>';
  }

  public function settings_page() {
    echo '<div class="wrap"><h1>Trackit Settings</h1>';
    echo '<form method="post" action="options.php">';
    settings_fields('trackit_options');
    do_settings_sections('trackit');
    submit_button('Save');
    echo '</form></div>';
    
    echo '<form action="' . admin_url('admin-post.php') . '" method="post">';
    echo '<input type="hidden" name="action" value="delete_tracking_data">';
    submit_button('Reset Tracking Data', 'delete');
    echo '</form>';
  }

  public function delete_tracking_data() {
    global $wpdb;
    $table_name = $wpdb->prefix . self::DB_TABLE_TRACKING;
    $wpdb->query("DELETE FROM $table_name");
    $wpdb->query("ALTER TABLE $table_name AUTO_INCREMENT = 1");
    wp_redirect(admin_url('admin.php?page=trackit-settings'));
    exit;
  }

  public function register_settings() {
    register_setting('trackit_options', self::OPTION_TRACK_ROLES);
    register_setting('trackit_options', self::OPTION_ERASE_UNINSTALL);
    add_settings_section('trackit_settings_section', '', null, 'trackit');
    add_settings_field('trackit_delete_field', 'Delete Data on Uninstall', array($this, 'delete_option_field'), 'trackit', 'trackit_settings_section');
    add_settings_field('trackit_track_roles_field', 'Track User Roles', array($this, 'track_roles_option_field'), 'trackit', 'trackit_settings_section');
  }

  public function track_roles_option_field() {
    $all_roles = wp_roles()->get_names();
    $all_roles['guest'] = 'Guest';

    $selected_roles = get_option(self::OPTION_TRACK_ROLES, array());
    if (!is_array($selected_roles)) {
      $selected_roles = array();
    }

    foreach ($all_roles as $role_value => $role_name) {
      $checked = in_array($role_value, $selected_roles) ? 'checked' : '';
      echo '<label>';
      echo '<input type="checkbox" name="' . self::OPTION_TRACK_ROLES . '[]" value="' . esc_attr($role_value) . '" ' . $checked . '> ';
      echo esc_html($role_name);
      echo '</label><br>';
    }
  }

  public function delete_option_field() {
    $option = get_option(self::OPTION_ERASE_UNINSTALL);
    echo '<input type="checkbox" name="' . self::OPTION_ERASE_UNINSTALL . '" value="1" ' . checked(1, $option, false) . '>';
  }

  public function trackit_track_visit($source_custom_element = '') {  
    $selected_roles = get_option(self::OPTION_TRACK_ROLES, array());
  
    if (!empty($selected_roles)) {
      if (in_array('guest', $selected_roles) && !is_user_logged_in()) {
        $this->insert_visit($source_custom_element);
        return;
      }
  
      if (is_user_logged_in()) {
        $current_user = wp_get_current_user();
        foreach ($current_user->roles as $role) {
          if (in_array($role, $selected_roles)) {
            $this->insert_visit($source_custom_element);
            return;
          }
        }
      }
    }
  }  

  private function insert_visit($source_custom_element) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $source_url = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

    global $wpdb;
    $table_name = $wpdb->prefix . self::DB_TABLE_TRACKING;

    $wpdb->insert(
      $table_name,
      array(
        'source_url' => $source_url,
        'source_custom_element' => $source_custom_element,
      ),
      array( '%s', '%s' )
    );
  }

  public static function uninstall_plugin() {
    if (get_option(self::OPTION_ERASE_UNINSTALL) == '1') {
      global $wpdb;
      $table_name = $wpdb->prefix . self::DB_TABLE_TRACKING;
      $wpdb->query("DROP TABLE IF EXISTS $table_name");
      delete_option(self::OPTION_ERASE_UNINSTALL);
      delete_option(self::OPTION_TRACK_ROLES);
    }
  }
}

new Trackit();
