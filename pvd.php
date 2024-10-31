<?php
/*
Plugin Name: Post via Dropbox
Plugin URI: http://paolo.bz/post-via-dropbox
Description: Post to WordPress blog via Dropbox
Version: 2.20
Author: Paolo Bernardi
Author URI: http://paolo.bz
*/
require('vendor/autoload.php');
use Kunnu\Dropbox\Dropbox;
use Kunnu\Dropbox\DropboxApp;
use Kunnu\Dropbox\Store\PersistentDataStoreInterface;

class WordPressPersistent implements PersistentDataStoreInterface {

    public function __construct() {
        $this->prefix = 'pvd_';
    }

    public function get($key) {
        if ($res = get_option($this->prefix.$key)) {
            return $res;
        }
        return null;
    }
    public function set($key, $value) {
        update_option($this->prefix.$key, $value);
    }
    public function clear($key) {
        if (get_option($this->prefix.$key)) {
            delete_option($this->prefix.$key);
        }
    }
}

class Post_via_Dropbox {

    private $plugin_name = 'Post via Dropbox';
    private $key_dropbox = 'dDl0N3FpaGFnaWlzaWxt';
    private $secret_dropbox = 'YmdnYWhxem9tOWcyOXIy';
    private $options;
    private $encryptkey;
    private $dropbox = null;

    public function __construct() {
        /**
        * Activation Hook
        */
        register_activation_hook(__FILE__, function() {

            if ( !version_compare(PHP_VERSION, '5.3.0', '>=') || !in_array('curl', get_loaded_extensions())) {
                add_action('update_option_active_plugins', function() {deactivate_plugins(plugin_basename(__FILE__));});
                wp_die('Sorry. ' . $this->plugin_name  . ' required PHP at least 5.3.0 and cURL extension. ' . $this->plugin_name  . ' was deactivated. <a href=\''.admin_url().'\'>Go Back</a>');
            }

            if (!get_option('pvd_options')) {
                add_option('pvd_options', array('interval' => 3600, 'ext' => 'txt', 'markdown' => 1, 'status' => 'publish', 'post_type' => 'post'));
            }
            if (!get_option('pvd_errors')) {
                add_option('pvd_errors', array());
            }
            if (!get_option('pvd_logs')) {
                add_option('pvd_logs', array());
            }

            // generate encryption key
            $pwd = wp_generate_password(24, true, true);
            $key = substr(sha1($pwd),0, 32);
            if (!get_option('pvd_encryptkey')) {
                add_option('pvd_encryptkey', $key);
            }

        });

        register_deactivation_hook(__FILE__, function() {
            delete_option('pvd_access_token');
            delete_option('pvd_request_token');
            delete_option('pvd_options');
            delete_option('pvd_logs');
            delete_option('pvd_errors');
            delete_option('pvd_encryptkey');
            delete_option('pvd_active');
            delete_option('pvd_auth_code');
            wp_clear_scheduled_hook('pvd_cron');
        });

        $this->getAccessToken();
        $this->access_token = get_option('pvd_access_token');

        add_action('admin_menu', array($this, 'addAdminMenu'));


        $this->options = get_option('pvd_options');
        $this->encryptkey = get_option('pvd_encryptkey');
        $this->active = get_option('pvd_active');




        add_action('admin_init', function() {
            register_setting('pvd_options', 'pvd_options', function($input) {
                $options = get_option('pvd_options');
                $newValues['delete'] = (!$input['delete'] ? 0 : 1);
                $newValues['markdown'] = (!$input['markdown'] ? 0 : 1);
                $newValues['simplified'] = (!$input['simplified'] ? 0 : 1);
                $newValues['author'] = intval($input['author']);
                $newValues['interval'] = intval($input['interval']);
                $newValues['cat'] = intval($input['cat']);
                $newValues['ext'] = preg_replace('#[^A-z0-9]#', '', $input['ext']);

                $post_types = get_post_types();
                $post_type = 'post';
                foreach ($post_types as $post_type_) {
                    if ($input['post_type'] == $post_type_) {
                        $post_type = $input['post_type'];
                        break;
                    }
                }

                $newValues['post_type'] = $post_type;

                $statuses = array('publish', 'draft', 'private', 'pending', 'future');
                foreach ($statuses as $status) {
                    if ($input['status'] == $status) {
                        $status_check = 1;
                    }
                }
                $newValues['status'] = ($status_check ? $input['status'] : 'draft');
                $newValues['restart'] = filter_var($input['restart'], FILTER_VALIDATE_BOOLEAN);
                #return array_merge($options, $newValues);
                return $newValues;
            });

            register_setting('pvd_active', 'pvd_active', function($input) {
                $newValue = filter_var($input, FILTER_VALIDATE_BOOLEAN);
                return $newValue;
             });

            register_setting('pvd_auth_code', 'pvd_auth_code', function($input) {
                $newValue = htmlspecialchars($input);
                return $newValue;
            });



        });



        /**
        * Cron section
        */

        add_action('admin_init', array($this, 'cronSetup'));

        add_filter('cron_schedules', array($this, 'cronSchedule'));

        add_action('pvd_cron', array($this, 'init'));

        /**
        * Show errors, if there are
        */


        add_action('admin_notices', array($this, 'show_errors'));


        /**
        * Manual action
        */

        if (isset($_GET['pvd_manual']) && is_admin()) {
            if ($_GET['pvd_manual'] == sha1($this->encryptkey)) {
                if (version_compare($wp_version, '3.9', '>=') >= 0) { #workaround for manual posting (since wp 3.9)
                    include_once(ABSPATH . WPINC . '/pluggable.php');
                }
                global $wp_rewrite;
                $wp_rewrite = new WP_Rewrite();
                $this->init();
            }
        }

        // if ($_GET['pvd_linkaccount']) {
        //     $this->linkAccount();
        // }
        //
        $this->unlink();
    }

    /**
    * Wordpress Options Page
    */

    public function addAdminMenu() {
        add_submenu_page('options-general.php', 'Options Post via Dropbox', $this->plugin_name, 'manage_options', 'pvd-settings', array($this, 'adminpage'));
    }

    // public function linkAccount() {
    //     if ($_GET['pvd_pass'] != sha1($this->encryptkey)) {
    //         wp_die("No way, sorry. You are not authorised to see this page.");
    //     }
    //     if (!$_GET['pvd_complete']) {
    //         delete_option("pvd_access_token");
    //         delete_option("pvd_request_token");
    //     } else {
    //         update_option("pvd_active", 1);
    //         echo    "<script>
    //                 window.opener.location.reload();
    //                 window.close();
    //                 </script>";
    //     }
    //     try {
    //         $callback_url = add_query_arg( array('pvd_pass'=>sha1($this->encryptkey), 'pvd_linkaccount'=>1, 'pvd_complete'=>1), admin_url('index.php') );
    //         $this->connect($callback_url);
    //     } catch(Exception $e) {
    //         wp_die($e->getMessage());
    //     }

    //     return null;
    // }
    //

    public function createLinkAccessToken() {
         if (!$this->dropbox) {
             $this->connect();
         }
         $authHelper = $this->dropbox->getAuthHelper();
         $authUrl = $authHelper->getAuthUrl();
         return $authUrl;
    }

    public function getAccessToken() {
         if (!$auth_code = get_option("pvd_auth_code")) {
            return null;
         }
         if (!$this->dropbox) {
             $this->connect();
         }
         $authHelper = $this->dropbox->getAuthHelper();
         $access_token = $authHelper->getAccessToken($auth_code);
         update_option("pvd_access_token", $access_token->getToken());
         update_option("pvd_active", 1);
         delete_option("pvd_auth_code");
        header('location: '.admin_url('options-general.php?page=pvd-settings'));
    }

    public function checkStatus() {
        if (!$this->dropbox) {
             $this->connect();
        }
        try {
            $account = $this->dropbox->getCurrentAccount();
            if ($email = $account->getEmail()) {
                return $email;
            }
        }
        catch (Exception $e) {

        }
        return false;
    }

    public function unlink() {
        if ($_GET['pvd_unlink'] == sha1($this->encryptkey)) {
            delete_option('pvd_access_token');
            delete_option('pvd_auth_code');
            delete_option('pvd_active');
            header('location: '.admin_url('options-general.php?page=pvd-settings'));
        }
        return null;
    }

    public function adminpage() {
        $options = $this->options;
        // $url_popup = add_query_arg( array(
        //                                     'pvd_linkaccount' => '1',
        //                                     'pvd_pass' => sha1($this->encryptkey),
        //     ) );
        if (!$logged = $this->checkStatus()) {
            $url_popup = $this->createLinkAccessToken();
        } else {
            $unlink_url = add_query_arg('pvd_unlink', sha1($this->encryptkey));
        }
        ?>
        <script>
            jQuery(document).ready(function() {
                jQuery('.pvd_linkAccount').click(function(e) {
                    e.preventDefault();
                    window.open('<?php echo $url_popup; ?>', 'Dropbox', 'width=850,height=600');
                });
            });
        </script>
        <div class='wrap'>
        <h2><?php echo $this->plugin_name; ?> Options</h2>
        <h3>Dropbox Status</h3>

        <?php
        // try {
        //  if (!$this->dropbox) {
        //      $this->connect();
        //      $data = $this->dropbox->accountInfo();
        //  }
        //  echo 'Your Dropbox account is correctly linked ( account: <strong style=\'color:green;\'>'.$data['body']->email.'</strong> )<br />';
        //  echo '<p class=\'submit\'> <button class=\'pvd_linkAccount button-primary\'>Re-link Account</button> </p>';
        // }
        // catch (Exception $e) {
        //  echo 'Your Dropbox account is <strong style=\'color:red;\'>not linked </strong>( '.$e->getMessage().' )<br />';
        //  echo '<p class=\'submit\'><button class=\'pvd_linkAccount button-primary\'>Link Account</button></p>';

        // }

        ?>

        <form method='post' action='options.php'>
            <table class='form-table' style='background: #e6e6e6; border: 1px solid #ccc'>
                <?php settings_fields('pvd_auth_code'); ?>

                <?php
                    if (!$logged):
                ?>
                <tr valign='top'>
                    <th scope='row'><label for=''> Authorization Code </label> </th>
                    <td>
                        <input size='50' type='text' name='pvd_auth_code' value='<?php echo ($access_token ? $access_token : ''); ?>'  /> <?php submit_button(__('Save')); ?>
                        <p><button class='pvd_linkAccount button-primary'>Get Authorization Code</button></p>

                    </td>
                </tr>
                <?php
                    else:
                ?>
                <tr valign='top'>
                    <th scope='row'><label for=''> <div style='padding: 4px;'>Status </div></label> </th>
                    <td>

                       <div style='color: green;'>Logged! (Account: <?php echo $logged; ?>)</div>
                       <div><a href='<?php echo $unlink_url; ?>'>Unlink</a></div>

                    </td>
                </tr>
                <?php
                    endif;
                ?>
            </table>
        </form>


        <h3>Settings (<a href='#help'>HELP & FAQ</a>)</h3>

        <form method='post' action='options.php'>
            <table class='form-table'>
                <?php settings_fields('pvd_options'); ?>

                <tr valign='top'>
                    <th scope='row'><laber for=''> Delete file after posted? </label> </th>
                    <td>
                        <input type='checkbox' name='pvd_options[delete]' value='1' <?php echo ($options['delete'] ? 'checked' : null); ?> />
                    </td>
                </tr>

                <tr valign='top'>
                    <th scope='row'><laber for=''> Markdown Syntax? </label> </th>
                    <td>
                        <input type='checkbox' name='pvd_options[markdown]' value='1' <?php echo ($options['markdown'] ? 'checked' : null); ?> />
                    </td>
                </tr>

                <tr valign='top'>
                    <th scope='row'><laber for=''> Simplified posting? (without using tags) </label> </th>
                    <td>
                        <input type='checkbox' name='pvd_options[simplified]' value='1' <?php echo ($options['simplified'] ? 'checked' : null); ?> />
                    </td>
                </tr>

                <tr valign='top'>
                    <th scope='row'><laber for=''> File Extensions  </label> </th>
                    <td>
                        <input type='text' name='pvd_options[ext]' value='<?php echo $options['ext']; ?>' />
                    </td>
                </tr>

                <tr valign='top'>
                    <th scope='row'><laber for=''> Default Author </label> </th>
                    <td>
                        <select name='pvd_options[author]'>
                            <?php
                                $users = get_users();
                                foreach ($users as $user) {
                                    if (user_can($user->ID, 'publish_posts')) {
                                        echo '<option value=\''.$user->ID.'\''.($options['author'] == $user->ID ? ' selected' : null).'>'.$user->user_login.'</option>';
                                    }
                                }
                            ?>
                        </select>
                    </td>
                </tr>

                <tr valign='top'>
                    <th scope='row'><laber for=''> Default Status  </label> </th>
                    <td>
                        <select name='pvd_options[status]'>
                            <?php
                                $statuses = array('publish', 'draft', 'private', 'pending', 'future');
                                foreach ($statuses as $status) {
                                    echo '<option value=\''.$status.'\''.($options['status'] == $status ? ' selected' : null).'>'.ucfirst($status).'</option>';
                                }
                            ?>
                        </select>
                    </td>
                </tr>

                <tr valign='top'>
                    <th scope='row'><laber for=''> Default Category </label> </th>
                    <td>
                        <select name='pvd_options[cat]'>
                            <?php
                                $categories = get_categories();
                                foreach ($categories as $cat) {
                                    echo '<option value=\''.$cat->cat_ID.'\''.($options['cat'] == $cat->cat_ID ? ' selected' : null).'>'.$cat->name.'</option>';
                                }

                            ?>
                        </select>
                    </td>
                </tr>

                <tr valign='top'>
                    <th scope='row'><laber for=''> Default Post Type </label> </th>
                    <td>
                        <select name='pvd_options[post_type]'>
                            <?php
                                $post_types = get_post_types();
                                foreach ($post_types as $post_type) {
                                    echo '<option value=\''.$post_type.'\''.($options['post_type'] == $post_type ? ' selected' : null).'>'.$post_type.'</option>';
                                }

                            ?>
                        </select>
                    </td>
                </tr>

                <tr valign='top'>
                    <th scope='row'><laber for=''> Check your Dropbox folder: </label> </th>
                    <td>
                        <select id='interval_cron' name='pvd_options[interval]'>
                            <?php
                                $intervals = array( 300 => 'Every five minutes',
                                                    600 => 'Every ten minutes',
                                                    1800 => 'Every thirty minutes',
                                                    3600 => 'Every hour',
                                                    7200 => 'Every two hours');
                                foreach ($intervals as $seconds => $text) {

                                    echo '<option value=\''.$seconds.'\''.($options['interval'] == $seconds ? ' selected' : null).'>'.$text.'</option>';
                                }
                            ?>
                        </select>
                    </td>
                </tr>

                <input type='hidden' name='pvd_options[restart]' value='1' />

            </table>
            <?php submit_button(__('Save')); ?>
        </form>

        <h3>Status Cron</h3>
        <?php if ($active = get_option('pvd_active') && $next_cron = wp_next_scheduled('pvd_cron')) : ?>
            Cron via WP-Cron is: <strong style='color:green'>Active</strong> and next job is scheduled for: <strong><?php echo get_date_from_gmt( date('Y-m-d H:i:s', $next_cron ), 'd/m/Y H:i' ); ?></strong>
        <?php else: ?>
            Cron via WP-Cron is: <strong style='color:red'>Disabled</strong>
        <?php endif; ?>
        <form method='post' action='options.php'>
            <?php
                settings_fields('pvd_active');
                $active = $this->active;
            ?>
            <input type='hidden' name='pvd_active' value='<?php echo !$active; ?>' />
            <?php submit_button( __(($active ?  'Disable' : 'Activate')) ); ?>
        </form>


        <h3> Activity (last 20 operations)</h3>

        <table cellspacing='10'>
            <tr>
                <td>time</td>
                <td>file</td>
                <td>id</td>
            </tr>
            <?php
                if ($logs = get_option('pvd_logs')) {
                    foreach ($logs as $log) {
                        echo '<tr>';
                        echo '<td>'.date('H:i d/m/Y', $log[0]).'</td>';
                        echo '<td>'.$log[1].'</td>';
                        echo '<td>'.($log[2] ? '<strong style=\'color: green\'>'.$log[2].'</strong>' : '<strong style=\'color: red\'>Error - not posted</strong>');
                        echo '</tr>';

                    }

                }
            ?>
        </table>
        <p class='submit'>
            <a href='<?php echo add_query_arg(array('pvd_manual' => sha1($this->encryptkey) ) ); ?>'><button class='button-primary'>Manual check</button></a>
        </p>

        <h3> Errors (last 20 messagges) </h3>
            <?php
                if ($errors = array_reverse(get_option('pvd_errors'))) {
                    echo '<table cellspacing=\'10\'>
                            <tr>
                                <td>time</td>
                                <td>error</td>
                            </tr>';

                    foreach ($errors as $error) {
                        echo '<tr>';
                        echo '<td>'.date('d/m/Y H:i', $error[1]).'</td>';
                        echo '<td>'.$error[0].'</td>';
                        echo '</tr>';
                    }

                }
                else {
                    echo "There're no errors.";
                }
                $this->empty_errors();

            ?>
        </table>

        <h2 id='help' style='margin-top: 20px;'>Help & FAQ</h2>

        <p>
        <strong>How it works?</strong><br />
        Post via Dropbox checks automatically the existance of new files in your Dropbox folder and then it proceeds to update your blog. Once posted, the text files is moved into subidirectory "posted", if you have not selected "delete" option.
        </p>

        <p>
        <strong>Some examples of what you can do</strong><br />
        You can post using only your favorite text editor without using browser.<br />
        You can post a bunch of posts at a time or it can make more easy the import process from another platform.<br />
        You can post from your mobile device using a text editor app with Dropbox support.<br />
        There're many ways of using it: text files are very flexible and they can adapt with no much efforts.
        </p>

        <p>
        <strong>Where do I put my text files?</strong><br />
        Text files must be uploaded inside <strong>Dropbox/Apps/Post_via_Dropbox/</strong> . The text file may have whatever extensions you want (default .txt) and it should have UTF-8 encoding.
        </p>

        <p>
        <strong>How should be the text files?</strong><br />
        Why WordPress is able to read informations in proper manner, you must use some tags like <strong>[title] [/title]</strong> and <strong>[content] [/content]</strong>.<br />
        If you have selected <strong>"Simplified posting"</strong>, you can avoid using these tags: the title of the post will be the filename while the content of the post will be the content of the text file. It is very fast and clean.<br />
        Moreover, you can formatted your post with <strong>Markdown syntax</strong> (selecting the "Markdown option"). More info about Markdown <a href="http://daringfireball.net/projects/markdown/syntax">here</a>.
        </p>

        <p>
        <strong>How edit a post in Wordpress via this plugin?</strong><br />
                You can edit an existing post specifying the ID of the post. There're two ways: <strong>1) using [id] tag</strong> or <strong>2) prepend to filename the ID </strong> (example: 500-filename.txt).<br />
The quickest way to edit an existing post, already posted via Dropbox, is move the file from the subfolder 'posted' to the principal folder.
        </p>


        <ul>
        <strong>This is the list of the tags that you can use (if you have not selected "Simplified posting"):</strong><br />
        <li><strong>[title]</strong></strong> post title <strong><strong>[/title]</strong></strong> (mandatory) </li>
            <li> <strong>[content]</strong> the content of the post (you can use Markdown syntax) <strong>[/content]</strong> (mandatory) </li>
            <li> <strong>[category]</strong> category, divided by comma <strong>[/category]</strong> </li>
            <li> <strong>[tag]</strong> tag, divided by comma <strong>[/tag]</strong> </li>
            <li> <strong>[status]</strong> post status (publish, draft, private, pending, future) <strong>[/status]</strong> </li>
            <li> <strong>[excerpt]</strong> post excerpt <strong>[/excerpt]</strong> </li>
            <li> <strong>[id]</strong> if you want to modify an existing post, you should put here the ID of the post <strong>[/id]</strong> </li>
            <li> <strong>[date]</strong> the date of the post (it supports english date format, like 1/1/1970 00:00 or 1 jan 1970 and so on, or UNIX timestamp) <strong>[/date]</strong></li>
            <li><strong>[sticky]</strong> stick or unstick the post (use word 'yes' / 'y' or 'no' / 'n') <strong>[/sticky]</strong></li>
<li> <strong>[customfield]</strong> custom fields (you must use this format: field_name1=value & field_name2=value ) <strong>[/customfield]</strong></li>
<li><strong>[taxonomy]</strong> taxonomies (you must use this format: taxonomy_slug1=term1,term2,term3 & taxonomy_slug2=term1,term2,term3) <strong>[/taxonomy]</strong></li>
<li><strong>[slug]</strong> the name (slug) for you post <strong>[/slug]</strong></li>
<li><strong>[comment]</strong> comments status (use word 'open' or 'closed') <strong>[/comment]</strong></li>
<li><strong>[ping]</strong> ping status (use word 'open' or 'closed') <strong>[/ping]</strong></li>
<li><strong>[post_type]</strong> Post Type (e.g. 'post', 'page', etc) <strong>[/post_type]</strong></li>
        </ul>
        The only necessary tags are <strong>[title]</strong> and <strong>[content]</strong>
        <br /><br />

        </div>
            <?php

    }

    public function cronSetup() {
        if ($this->active) {
            $options = $this->options;
            if ( !wp_next_scheduled( 'pvd_cron' ) || $options['restart'] ) {
                wp_clear_scheduled_hook('pvd_cron');
                wp_schedule_event( time()+240, 'pvd_interval', 'pvd_cron');
                $options['restart'] = 0;
                update_option('pvd_options', $options);
            }
        }
        else {
            wp_clear_scheduled_hook('pvd_cron');
        }
    }

    public function cronSchedule($schedules) {
        $schedules['pvd_interval'] = array('interval' => $this->options['interval'], 'display' => 'Post via Dropbox userInterval');
        return $schedules;
    }

    private function connect($callback = false) {
        // spl_autoload_register(function($class){
        //  $class = explode('\\', $class);
        //  if ($class[0] == 'Dropbox') {
        //      $class = end($class);
        //      include_once(plugin_dir_path(__FILE__) .'dropbox/' . $class . '.php');
        //  }

        // });

        // $encrypter = new \Dropbox\OAuth\Storage\Encrypter($this->encryptkey);
        // $storage = new \Dropbox\OAuth\Storage\Wordpress($encrypter);
        // $OAuth = new \Dropbox\OAuth\Consumer\Curl(base64_decode($this->key_dropbox), base64_decode($this->secret_dropbox), $storage, $callback);
        // $this->dropbox = new \Dropbox\API($OAuth);
        $access_token = ($this->access_token ? $this->access_token : null);
        $persistentDataStore = new WordPressPersistent();
        $config = ['persistent_data_store' => $persistentDataStore,];
        $app = new DropboxApp(base64_decode($this->key_dropbox), base64_decode($this->secret_dropbox), $access_token);
        $this->dropbox = new Dropbox($app, $config);
    }

    // private function get($cursor=null) {
    //     $results = array();
    //     if (!$this->dropbox) {
    //         return $results;
    //     }

    //     try {
    //         $res = $this->dropbox->delta($cursor);
    //     } catch (Exception $e) {
    //         $this->report_error($e->getMessage());
    //         return $results;
    //     }
    //     $ext = ($this->options['ext'] ? $this->options['ext'] : 'txt');
    //     foreach ($res['body']->entries as $file) {
    //         if ( pathinfo($file[0], PATHINFO_EXTENSION) == $ext && pathinfo($file[0], PATHINFO_DIRNAME) == '/' ) {
    //             $results[] = $file[0];
    //         }
    //     }
    //     return $results;
    // }

    private function get($cursor=null) {
        $results = array();
        if (!$this->dropbox) {
            return $results;
        }

        try {
            $res = $this->dropbox->listFolder("/")->getItems()->all();
        } catch (Exception $e) {
            $this->report_error($e->getMessage());
            return $results;
        }
        $ext = ($this->options['ext'] ? $this->options['ext'] : 'txt');
        foreach ($res as $file) {
            // if ($file->getTag() != 'file') {
            //     continue;
            // }
            $file_path = $file->getPathLower();
            if ( pathinfo($file_path, PATHINFO_EXTENSION) == $ext && pathinfo($file_path, PATHINFO_DIRNAME) == '/' ) {
                $results[] = $file_path;
            }
        }
        return $results;
    }

    private function insert($posts) {
        $results = array();
        if (!$this->dropbox) {
            return $results;
        }

        $options = $this->options;
        if ($options['markdown']) {
            require(plugin_dir_path(__FILE__).'Michelf/Markdown.php');
        }

        if (!$posts || !is_array($posts)) {
            return $results;
        }

        foreach ($posts as $post) {
            $id = '';

            try {
                $res = $this->dropbox->download($post)->getContents();
            } catch (Exception $e) {
                $this->report_error($e->getMessage());
                continue;
            }

            if ($options['simplified']) {
                extract( $this->parse_simplified($post, $res) );
            } else {
                extract( $this->parse($res) );
            }


            if ($options['markdown']) {
                $content = \Michelf\Markdown::defaultTransform($content);
            }

            $post_array = array(    'ID'                =>  ( $id ? intval($id) : (preg_match('#^(\d+)-#', pathinfo($post, PATHINFO_BASENAME), $matched) ? $matched[1] : null ) ),
                                    'post_title'        =>  wp_strip_all_tags($title),
                                    'post_content'      =>  $content,
                                    'post_excerpt'      =>  ( $excerpt ? $excerpt : '' ),
                                    'post_category'     =>  ( $category ? array_map(function($el) {return get_cat_ID(trim($el));}, explode(',', $category) ) : ($options['cat'] ? array($options['cat']) : null) ),
                                    'tags_input'        =>  ( $tag ? $tag : null),
                                    'post_status'       =>  ( $status == 'draft' || $status == 'publish' || $status == 'pending' || $status == 'future' || $status == 'private' ? $status : ($options['status'] ? $options['status'] : 'draft') ),
                                    'post_author'       =>  ( $options['author'] ? $options['author'] : null ),
                                //  'post_author'       =>  ( $author ? )
                                    'post_date'         =>  ( $date ? strftime( "%Y-%m-%d %H:%M:%S", ( $this->isValidTimeStamp($date) ? $date : strtotime($date) ) ) : null ),
                                    'post_name'         =>  ( $slug ? $slug : null),
                                    'comment_status'    =>  ( $comment == 'open' ? 'open' : ($comment == 'closed' ? 'closed' : null) ),
                                    'ping_status'       =>  ( $ping == 'open' ? 'open' : ($ping == 'closed' ? 'closed' : null) ),
                                    'post_type'         =>  ( in_array($post_type, get_post_types()) ? $post_type : ($options['post_type'] ? $options['post_type'] : 'post'))
                                );

            $id = wp_insert_post( $post_array );

            //extra features
            if ($id) { // post inserted
                if ($sticky && ( $sticky == 'yes' || $sticky == 'y' )) {
                    stick_post( $id );
                } elseif ($sticky && ( $sticky == 'no' || $sticky == 'n' )) {
                    unstick_post( $id );
                }

                if ($customfield) {
                    $customfield_array = array();
                    parse_str($customfield, $customfield_array);
                    foreach ($customfield_array as $field => $value) {
                        $this->post_meta_helper( $id , $field, $value );
                    }
                }

                if ($taxonomy) {
                    $taxonomy_array = array();
                    parse_str($taxonomy, $taxonomy_array);
                    foreach ($taxonomy_array as $tax_slug => $value) {
                        wp_set_post_terms($id, $value, $tax_slug, $append = false);
                    }
                }
            }

            if ($id) { // post inserted
                // delete or move to 'posted' subfolder original text file
                if ($options['delete']) {
                    try {
                        $this->dropbox->delete($post);
                    }
                    catch (Exception $e) {
                        $this->report_error($e->getMessage());
                    }
                } else {
                    try {
                        $this->dropbox->move($post, '/posted/'.$id.'-'.preg_replace('#^[\d-]+#', '',pathinfo($post, PATHINFO_BASENAME)));
                    } catch (Exception $e) {
                        $this->report_error($e->getMessage());
                    }
                }
            } else { // post not inserted, probably there was an error
                $this->report_error('File '.$post.' was not posted');
            }

            $results[] = array(current_time('timestamp'), $post, $id);
        }

        return $results;
    }

    private function parse ($input) {
        #$input = $input['data'];
        $results = array();
        $tag = array(
                    'title'         =>      '#\[title\](.*?)\[/title\]#s',
                    'status'        =>      '#\[status\](.*?)\[/status\]#s',
                    'category'      =>      '#\[category\](.*?)\[/category\]#s',
                    'tag'           =>      '#\[tag\](.*?)\[/tag\]#s',
                    'content'       =>      '#\[content\](.*?)\[/content\]#s',
                    'excerpt'       =>      '#\[excerpt\](.*?)\[/excerpt\]#s',
                    'id'            =>      '#\[id\](.*?)\[/id\]#s',
                    'sticky'        =>      '#\[sticky\](.*?)\[/sticky\]#s',
                    'date'          =>      '#\[date\](.*?)\[/date\]#s',
                    'slug'          =>      '#\[slug\](.*?)\[/slug\]#s',
                    'customfield'   =>      '#\[customfield\](.*?)\[/customfield\]#s',
                    'taxonomy'      =>      '#\[taxonomy\](.*?)\[/taxonomy\]#s',
                    'comment'       =>      '#\[comment\](.*?)\[/comment\]#s',
                    'ping'          =>      '#\[ping\](.*?)\[/ping\]#s',
                    'post_type'     =>      '#\[post_type\](.*?)\[/post_type\]#s'
            );
        foreach ($tag as $key => $value) {
            if (preg_match($value, $input, $matched)) {
                $results[$key] = trim($matched[1]);
            } else {
                $results[$key] = '';
            }
        }
        return $results;
    }

    private function parse_simplified ($path, $input) {
        $title = trim(pathinfo($path, PATHINFO_FILENAME));
        $title = preg_replace('#^[\d-]+#', '', $title);
        $title = urldecode($title);
        $content = trim($input);
        return array(
                'title'=>$title,
                'content'=>$content
                    );
    }

    public function show_errors() {
        if ($this->options['error']) {
            $errors = get_option('pvd_errors');
            $phrase = '<p>'. $this->plugin_name .' - Something went wrong: <em>%s</em> (%s)</p>';
            echo '<div class=\'error\'>';
            foreach ($errors as $error) {
                if (!$error[2]) {
                    printf( $phrase, $error[0], date('d/m/Y H:i', $error[1]) );
                }
            }
            echo '<p>Please visit <a href=\''.menu_page_url( 'pvd-settings', 0 ).'\'>plugin page</a> for more informations.</p>';
            echo '</div>';
        }
    }

    private function report_error($msg) {
        $errors = get_option('pvd_errors');
        $errors[] = array($msg, current_time('timestamp'), 0);
        $this->options['error'] = 1;
        update_option('pvd_options', $this->options);
        update_option('pvd_errors', $errors);
        return null;
    }

    private function empty_errors() {
        $options = get_option('pvd_options');
        $options['error'] = 0;
        update_option('pvd_options', $options);
        $errors = get_option('pvd_errors');
        $errors = array_map(function($el) { $el[2] = 1; return $el; }, $errors);
        $errors = array_slice($errors, 0, 20 );
        update_option('pvd_errors', $errors);
    }

    private function isValidTimeStamp($timestamp) {
        return ( (string) (int) $timestamp === $timestamp) && ($timestamp <= PHP_INT_MAX) && ($timestamp >= ~PHP_INT_MAX);
    }

    public function post_meta_helper( $post_id, $field_name, $value = '' ) {
        if ( empty( $value ) || ! $value )
        {
            delete_post_meta( $post_id, $field_name );
        }
        elseif ( ! get_post_meta( $post_id, $field_name ) )
        {
            add_post_meta( $post_id, $field_name, $value );
        }
        else
        {
            update_post_meta( $post_id, $field_name, $value );
        }
    }

    public function init() {

        $results = array();

        try {
            if (!$this->dropbox) {
                $this->connect();
            }
        } catch (Exception $e) {
            $this->report_error( $e->getMessage() );
            return null;
        }

        if ( $posts = $this->get() ) {
            $results = $this->insert($posts);
        }

        if ($results) {
            $logs = get_option('pvd_logs');
            $logs = array_merge_recursive($logs, $results);
            usort($logs, function($a, $b) {return ($a[0] >= $b[0] ? -1 : 1 ); });
            $logs = array_slice($logs, 0, 20);
            update_option('pvd_logs', $logs);
        }

        return null;
    }

}


// Where all starts! :-)
$Post_via_Dropbox = new Post_via_Dropbox();
?>
