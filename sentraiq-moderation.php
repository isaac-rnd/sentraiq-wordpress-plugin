<?php
/**
 * Plugin Name: SENTRAIQ Moderation
 * Plugin URI:  https://github.com/isaac-rnd/sentraiq-wordpress-plugin
 * Description: Demo plugin with Elementor compatibility: live editor alerts and robust save enforcement.
 * Version:     0.3
 * Author:      Isaac
 * Text Domain: sentraiq-moderation
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SENTRAIQ_Moderation_V3 {
    private $option_name = 'sentraiq_options_v2';
    private $meta_key = '_sentraiq_moderation';
    private $transient_redirect = 'sentraiq_moderation_blocked';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_filter( 'wp_handle_upload_prefilter', [ $this, 'on_upload_prefilter' ] );
        add_action( 'save_post', [ $this, 'on_save_post' ], 10, 3 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_ajax_sentraiq_manual_test', [ $this, 'ajax_manual_test' ] );
        add_action( 'wp_ajax_sentraiq_check_text', [ $this, 'ajax_check_text' ] );
        add_action( 'wp_ajax_sentraiq_check_save_status', [ $this, 'ajax_check_save_status' ] );
        add_action( 'admin_notices', [ $this, 'maybe_show_block_notice' ] );
        add_filter( 'the_content', [ $this, 'append_moderation_box' ] );
    }

    public function add_admin_menu() {
        add_options_page( 'SENTRAIQ Moderation', 'SENTRAIQ', 'manage_options', 'sentraiq-moderation', [ $this, 'render_settings_page' ] );
    }

    public function register_settings() {
        register_setting( 'sentraiq_group_v2', $this->option_name );
    }

    private function get_options() {
        $opts = get_option( $this->option_name );
        if ( ! is_array( $opts ) ) $opts = [];
        return $opts;
    }

    public function render_settings_page() {
        $opts = $this->get_options();
        $server = isset($opts['server']) ? esc_url_raw($opts['server']) : '';
        $api_key = isset($opts['api_key']) ? esc_attr($opts['api_key']) : '';
        $mode = isset($opts['enforcement']) ? $opts['enforcement'] : 'block';
        ?>
        <div class="wrap">
            <h1>SENTRAIQ Moderation — Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'sentraiq_group_v2' ); do_settings_sections( 'sentraiq_group_v2' ); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Server</th>
                        <td><input type="url" name="<?php echo $this->option_name; ?>[server]" value="<?php echo esc_attr( $server ); ?>" style="width:420px" placeholder="https://your-sentraiq.example.com" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">API Key</th>
                        <td>
                            <input type="text" name="<?php echo $this->option_name; ?>[api_key]" value="<?php echo esc_attr( $api_key ); ?>" style="width:320px" />
                            &nbsp; <a href="https://isaac-rnd.github.io/sentraiq-product-page/" target="_blank" rel="noopener">Get API Key</a>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Enforcement</th>
                        <td>
                            <select name="<?php echo $this->option_name; ?>[enforcement]">
                                <option value="block" <?php selected( $mode, 'block' ); ?>>Block (set to Draft)</option>
                                <option value="warn" <?php selected( $mode, 'warn' ); ?>>Warn (allow but warn)</option>
                                <option value="quarantine" <?php selected( $mode, 'quarantine' ); ?>>Quarantine (set to Draft)</option>
                                <option value="allow" <?php selected( $mode, 'allow' ); ?>>Allow</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2>Manual test (image URL)</h2>
            <p>Enter image URL and click <strong>Run manual test</strong>. If a server is configured it will be called; otherwise mock logic applies.</p>
            <input id="sentraiq_manual_image_url" type="text" style="width:420px" placeholder="https://..." />
            <button id="sentraiq_manual_test_btn" class="button button-primary">Run manual test</button>

            <div id="sentraiq_test_result" style="margin-top:12px;"></div>
        </div>
        <?php
    }

    public function enqueue_admin_assets( $hook_suffix ) {
        // Determine whether to load assets
        $load = false;
        // Try to detect current screen safely
        if ( function_exists( 'get_current_screen' ) ) {
            $screen = get_current_screen();
            if ( $screen && ( $screen->id === 'settings_page_sentraiq-moderation' || in_array( $screen->base, array('post', 'post-new') ) ) ) {
                $load = true;
            }
        }
        // also load on Elementor edit action (post.php?action=elementor) or if post param present
        if ( ! $load ) {
            $p = isset($_GET['post']) ? intval($_GET['post']) : 0;
            $a = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
            if ( $a === 'elementor' || $p ) $load = true;
        }

        if ( $load ) {
            wp_enqueue_style( 'sentraiq-admin-css', plugin_dir_url( __FILE__ ) . 'assets/admin.css' );
            wp_enqueue_script( 'sentraiq-admin-js', plugin_dir_url( __FILE__ ) . 'assets/admin.js', array( 'jquery' ), '0.3', true );
            // Pass data to JS: post_id and whether we're in elementor action
            $post_id = isset($_GET['post']) ? intval($_GET['post']) : 0;
            $is_elementor = (isset($_GET['action']) && $_GET['action'] === 'elementor') ? true : false;
            wp_localize_script( 'sentraiq-admin-js', 'sentraiq_ajax', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'sentraiq_nonce_v3' ),
                'post_id'  => $post_id,
                'is_elementor' => $is_elementor
            ) );
        }
    }

    public function on_upload_prefilter( $file ) {
        $name = isset( $file['name'] ) ? $file['name'] : '';
        $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        $opts = $this->get_options();
        $server = isset($opts['server']) ? $opts['server'] : '';
        $api_key = isset($opts['api_key']) ? $opts['api_key'] : '';

        // quick local checks
        $bad_exts = array( 'exe', 'bmp' );
        if ( in_array( $ext, $bad_exts ) ) {
            $file['error'] = "SENTRAIQ: file type '{$ext}' is not allowed.";
            return $file;
        }
        foreach ( array('nsfw','bad') as $w ) {
            if ( stripos( $name, $w ) !== false ) {
                $file['error'] = "SENTRAIQ: filename contains prohibited content.";
                return $file;
            }
        }

        // If server configured, try to send for moderation (base64 payload)
        if ( ! empty( $server ) ) {
            $tmp = isset($file['tmp_name']) ? $file['tmp_name'] : '';
            if ( $tmp && file_exists( $tmp ) ) {
                $data = base64_encode( file_get_contents( $tmp ) );
                $payload = array(
                    'content_id' => 'upload-' . time(),
                    'images' => array( 'data:'.$file['type'].';base64,'.$data )
                );
                $resp = $this->call_server( $server, $api_key, $payload );
                if ( is_array($resp) ) {
                    set_transient('sentraiq_last_upload_result', $resp, 30);
                    if ( isset($resp['approved']) && !$resp['approved'] ) {
                        $msg = isset($resp['reason']) ? $resp['reason'] : 'Blocked by SENTRAIQ';
                        $file['error'] = 'SENTRAIQ: ' . $msg;
                        return $file;
                    }
                }
            }
        }

        return $file;
    }

    public function on_save_post( $post_ID, $post, $update ) {
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_ID ) ) return;
        if ( ! in_array( $post->post_type, array('post', 'page') ) ) return;

        $content = isset( $post->post_content ) ? $post->post_content : '';
        $opts = $this->get_options();
        $server = isset($opts['server']) ? $opts['server'] : '';
        $api_key = isset($opts['api_key']) ? $opts['api_key'] : '';
        $mode = isset($opts['enforcement']) ? $opts['enforcement'] : 'block';

        if ( ! empty( $server ) ) {
            $payload = array(
                'content_id' => 'post-'.$post_ID.'-'.time(),
                'text' => mb_substr( wp_strip_all_tags($content), 0, 2000 )
            );
            $resp = $this->call_server( $server, $api_key, $payload );
        } else {
            $resp = array( 'approved' => $this->mock_text_moderation( $content ), 'reason' => $this->mock_reason($content) );
        }

        if ( is_array($resp) ) {
            update_post_meta( $post_ID, $this->meta_key, $resp );
        } else {
            update_post_meta( $post_ID, $this->meta_key, array('approved' => true, 'reason' => 'invalid_response') );
        }

        $approved = isset($resp['approved']) ? $resp['approved'] : true;
        if ( !$approved && $mode !== 'allow' ) {
            # set transient flag for the post so editor JS can detect and show notice (Elementor)
            set_transient( 'sentraiq_blocked_post_' . $post_ID, json_encode($resp), 30 );
            # set transient used by redirect filter to add admin notice param
            set_transient( $this->transient_redirect, '1', 30 );
            # prevent recursion
            remove_action( 'save_post', array( $this, 'on_save_post' ), 10 );
            wp_update_post( array( 'ID' => $post_ID, 'post_status' => 'draft' ) );
            add_action( 'save_post', array( $this, 'on_save_post' ), 10, 3 );
        }
    }

    private function mock_reason($text){ return stripos($text,'badword')!==false?'contains badword':'OK'; }

    private function mock_text_moderation( $text ) {
        $bad = array('danger','badword','forbidden');
        foreach ( $bad as $w ) {
            if ( stripos( $text, $w ) !== false ) {
                return false;
            }
        }
        return true;
    }

    public function ajax_manual_test() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error(array('message'=>'no_permission'),403);
        check_ajax_referer( 'sentraiq_nonce_v3', 'security' );
        $image_url = isset($_POST['image_url']) ? esc_url_raw($_POST['image_url']) : '';
        if ( empty($image_url) ) wp_send_json_error(array('message'=>'empty_url'));
        $opts = $this->get_options();
        $server = isset($opts['server']) ? $opts['server'] : '';
        $api_key = isset($opts['api_key']) ? $opts['api_key'] : '';
        if ( ! empty($server) ) {
            $payload = array( 'content_id' => 'manual-'.time(), 'images' => array($image_url) );
            $resp = $this->call_server( $server, $api_key, $payload );
            if ( is_array($resp) ) wp_send_json_success( $resp );
        }
        $allowed = stripos($image_url,'bad')===false;
        wp_send_json_success(array( 'approved' => $allowed, 'reason' => $allowed? 'OK':'mock_nsfw' ));
    }

    public function ajax_check_text() {
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error(array('message'=>'no_permission'),403);
        check_ajax_referer( 'sentraiq_nonce_v3', 'security' );
        $text = isset($_POST['text']) ? wp_kses_post($_POST['text']) : '';
        $opts = $this->get_options();
        $server = isset($opts['server']) ? $opts['server'] : '';
        $api_key = isset($opts['api_key']) ? $opts['api_key'] : '';
        if ( ! empty($server) ) {
            $payload = array( 'content_id' => 'live-'.time(), 'text' => mb_substr(wp_strip_all_tags($text),0,2000) );
            $resp = $this->call_server( $server, $api_key, $payload );
            if ( is_array($resp) ) {
                wp_send_json_success(array( 'allowed' => isset($resp['approved']) ? (bool)$resp['approved'] : true, 'reason' => isset($resp['reason']) ? $resp['reason'] : '' ));
            }
        }
        $allowed = $this->mock_text_moderation($text);
        wp_send_json_success(array( 'allowed' => $allowed, 'reason' => $allowed? 'OK':'contains flagged words' ));
    }

    public function ajax_check_save_status() {
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error(array('message'=>'no_permission'),403);
        check_ajax_referer( 'sentraiq_nonce_v3', 'security' );
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if ( ! $post_id ) wp_send_json_success(array('blocked'=>false));
        $t = get_transient( 'sentraiq_blocked_post_' . $post_id );
        if ( $t ) {
            $data = json_decode( $t, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) $data = array('approved'=>false,'reason'=>'blocked');
            delete_transient( 'sentraiq_blocked_post_' . $post_id );
            wp_send_json_success(array('blocked'=>true,'data'=>$data));
        }
        wp_send_json_success(array('blocked'=>false));
    }

    private function call_server( $server, $api_key, $payload ) {
        if ( empty($server) ) return array('approved' => true, 'reason' => 'no_server');
        $args = array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body' => wp_json_encode($payload),
            'timeout' => 15,
        );
        if ( ! empty($api_key) ) $args['headers']['x-api-key'] = $api_key;
        $res = wp_remote_post( $server, $args );
        if ( is_wp_error($res) ) return array( 'approved' => false, 'reason' => 'server_error' );
        $body = wp_remote_retrieve_body($res);
        $data = json_decode($body, true);
        if ( json_last_error() !== JSON_ERROR_NONE ) return array( 'approved' => false, 'reason' => 'invalid_json' );
        return $data;
    }

    public function maybe_show_block_notice() {
        if ( isset( $_GET['sentraiq_moderation_blocked'] ) && $_GET['sentraiq_moderation_blocked'] == '1' ) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>SENTRAIQ:</strong> Content flagged. Post set to Draft.</p></div>';
        }
    }

    public function append_moderation_box( $content ) {
        if ( ! is_singular() ) return $content;
        global $post;
        $meta = get_post_meta( $post->ID, $this->meta_key, true );
        if ( empty($meta) || !is_array($meta) ) {
            if ( is_string($meta) ) $meta = json_decode($meta, true);
        }
        if ( empty($meta) || !is_array($meta) ) return $content;
        $status = isset($meta['approved']) ? ($meta['approved'] ? 'Approved' : 'Rejected') : 'Unknown';
        $reason = isset($meta['reason']) ? esc_html($meta['reason']) : '';
        $html = '<div class="sentraiq-moderation-box" style="border:1px solid #ddd;padding:8px;margin:12px 0;background:#f9f9f9">';
        $html .= '<strong>Sentraiq Moderation:</strong> <span style="color:' . (isset($meta['approved']) && $meta['approved'] ? 'green' : 'red') . ';">' . esc_html($status) . '</span>';
        if ( ! empty($reason) ) $html .= ' — ' . esc_html($reason);
        $html .= '</div>';
        return $html . $content;
    }
}

new SENTRAIQ_Moderation_V3();
?>