<?php
/**
 * Plugin Name: SENTRAIQ Moderation (MVP)
 * Plugin URI:  https://github.com/isaac-rnd/sentraiq-wordpress-plugin
 * Description: Content moderation demo: intercepts uploads and post saves and returns mock decisions.
 * Version:     0.1
 * Author:      Isaac
 * Text Domain: sentraiq-moderation
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SENTRAIQ_Moderation {
    private $option_name = 'sentraiq_options';
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_filter( 'wp_handle_upload_prefilter', [ $this, 'on_upload_prefilter' ] );
        add_action( 'save_post', [ $this, 'on_save_post' ], 10, 3 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_ajax_sentraiq_manual_test', [ $this, 'ajax_manual_test' ] );
        add_action( 'wp_ajax_sentraiq_check_text', [ $this, 'ajax_check_text' ] );
        add_action( 'admin_notices', [ $this, 'maybe_show_block_notice' ] );
    }
    public function add_admin_menu() {
        add_options_page('SENTRAIQ Moderation','SENTRAIQ','manage_options','sentraiq-moderation',[ $this,'render_settings_page' ]);
    }
    public function register_settings() { register_setting( 'sentraiq_group', $this->option_name ); }
    public function get_options() { $opts = get_option( $this->option_name ); return is_array($opts) ? $opts : []; }
    public function render_settings_page() {
        $opts = $this->get_options();
        $api_url = isset($opts['api_url']) ? esc_url_raw($opts['api_url']) : '';
        $api_key = isset($opts['api_key']) ? esc_attr($opts['api_key']) : '';
        $mode = isset($opts['enforcement']) ? $opts['enforcement'] : 'block'; ?>
        <div class="wrap">
            <h1>SENTRAIQ Moderation — Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'sentraiq_group' ); do_settings_sections( 'sentraiq_group' ); ?>
                <table class="form-table">
                    <tr><th>SENTRAIQ API URL</th><td><input type="url" name="<?php echo $this->option_name; ?>[api_url]" value="<?php echo esc_attr($api_url); ?>" style="width:420px" /></td></tr>
                    <tr><th>API Key</th><td><input type="text" name="<?php echo $this->option_name; ?>[api_key]" value="<?php echo esc_attr($api_key); ?>" style="width:320px" /></td></tr>
                    <tr><th>Enforcement</th><td>
                        <select name="<?php echo $this->option_name; ?>[enforcement]">
                            <option value="block" <?php selected($mode,'block'); ?>>Block</option>
                            <option value="warn" <?php selected($mode,'warn'); ?>>Warn</option>
                            <option value="quarantine" <?php selected($mode,'quarantine'); ?>>Quarantine (draft)</option>
                            <option value="allow" <?php selected($mode,'allow'); ?>>Allow</option>
                        </select></td></tr>
                </table><?php submit_button(); ?>
            </form>
            <h2>Test Server</h2>
            <input id="sentraiq_manual_image_url" type="text" style="width:420px" placeholder="https://..." />
            <button id="sentraiq_manual_test_btn" class="button button-primary">Run manual test</button>
            <div id="sentraiq_test_result" style="margin-top:12px;"></div>
        </div><?php
    }
    public function enqueue_admin_assets($hook_suffix) {
        $screen = get_current_screen();
        if ($screen && ($screen->id === 'settings_page_sentraiq-moderation' || in_array($screen->base,['post','post-new']))) {
            wp_enqueue_style('sentraiq-admin-css', plugin_dir_url(__FILE__).'assets/admin.css');
            wp_enqueue_script('sentraiq-admin-js', plugin_dir_url(__FILE__).'assets/admin.js',['jquery'],'0.1',true);
            wp_localize_script('sentraiq-admin-js','sentraiq_ajax',['ajax_url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('sentraiq_nonce')]);
        }
    }
    public function on_upload_prefilter($file) {
        $name = isset($file['name'])?$file['name']:'';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext,['exe','bmp'])) { $file['error']="SENTRAIQ: file type '{$ext}' not allowed."; return $file; }
        foreach(['nsfw','bad'] as $w){ if(stripos($name,$w)!==false){$file['error']="SENTRAIQ: filename contains prohibited content."; return $file;}}
        return $file;
    }
    public function on_save_post($post_ID,$post,$update){
        if(defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE) return;
        if(wp_is_post_revision($post_ID)) return;
        if(!in_array($post->post_type,['post','page'])) return;
        $allowed=$this->mock_text_moderation($post->post_content);
        $mode=$this->get_options()['enforcement']??'block';
        if(!$allowed && $mode!=='allow'){
            remove_action('save_post',[ $this,'on_save_post' ],10);
            wp_update_post(['ID'=>$post_ID,'post_status'=>'draft']);
            add_filter('redirect_post_location',fn($loc)=>add_query_arg('sentraiq_moderation_blocked','1',$loc));
            add_action('save_post',[ $this,'on_save_post' ],10,3);
        }
    }
    private function mock_text_moderation($text){
        foreach(['danger','badword','forbidden'] as $w){ if(stripos($text,$w)!==false) return false; }
        return true;
    }
    public function ajax_manual_test(){
        if(!current_user_can('manage_options')) wp_send_json_error(['message'=>'no_permission'],403);
        check_ajax_referer('sentraiq_nonce','security');
        $url=esc_url_raw($_POST['image_url']??'');
        if(empty($url)) wp_send_json_error(['message'=>'empty_url']);
        $allowed=stripos($url,'bad')===false;
        wp_send_json_success(['decision'=>$allowed?'allow':'block','score'=>$allowed?0.06:0.99,'reasons'=>$allowed?[]:['mock_nsfw']]);
    }
    public function ajax_check_text(){
        if(!current_user_can('edit_posts')) wp_send_json_error(['message'=>'no_permission'],403);
        check_ajax_referer('sentraiq_nonce','security');
        $allowed=$this->mock_text_moderation(wp_kses_post($_POST['text']??''));
        wp_send_json_success(['allowed'=>$allowed]);
    }
    public function maybe_show_block_notice(){
        if(isset($_GET['sentraiq_moderation_blocked'])&&$_GET['sentraiq_moderation_blocked']=='1'){
            echo '<div class="notice notice-error is-dismissible"><p><strong>SENTRAIQ:</strong> Content flagged. Post set to Draft.</p></div>';
        }
    }
}
new SENTRAIQ_Moderation();
?>