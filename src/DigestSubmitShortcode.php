<?php

namespace U7\FrontEditor;

class DigestSubmitShortcode
{

    public static $errors = [];


    public static $success = [];


    public static $shortcode_name = "digest-form";

    public static function init()
    {
        add_shortcode(self::$shortcode_name, function () {

            $data_args = self::get_data();
            ob_start();
            Core::render('form-digest-submit.php', $data_args);
            $content = ob_get_clean();
            return $content;
        });

        add_action('wp', [__CLASS__, 'form_handler']);

        add_action('form-submit-topic-before-fields', [__CLASS__, 'notices']);

        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);

        add_filter('the_content', [__CLASS__, 'post_contend_add_url']);
    }

    public static function post_contend_add_url($content)
    {
        $post = get_post();
        
        if( ! $url = get_post_meta($post->ID, 'url-main', true)){
            return $content;
        }

        $button = sprintf('<p><a href="%s" target="_blank" rel="noopener noreferrer nofollow ugc">Подробнее...</a></p>', $url);

        $content .= $button;

        return $content;
    }

    public static function notices()
    {
        if (isset(self::$success['id'])) {

            $notices = [];
            $notices[] = array(
                'notice' => 'Пост опубликован в дайджест'
            );

            wc_get_template(
                "notices/success.php",
                array(
                    'notices'  => $notices,
                )
            );
        }
    }


    public static function assets()
    {
        $post = get_post();
        if (!has_shortcode($post->post_content, self::$shortcode_name)) {
            return;
        }

        //don't upload because it breaks the site's style
        // wp_enqueue_style('choices-base', Core::get_file_url('assets/choices/base.min.css'), [], '20200909' );

        // wp_enqueue_style('choices', Core::get_file_url('assets/choices/choices.min.css'), [], '20200909' );
        // wp_enqueue_script('choices', Core::get_file_url('assets/choices/choices.min.js'), [], '20200909', true);

        wp_enqueue_style('select2', Core::get_file_url('assets/select2/select2.min.css'), [], '20200909');
        wp_enqueue_script('select2', Core::get_file_url('assets/select2/select2.min.js'), [], '20200909', true);

        wp_enqueue_script('front-editor-config', Core::get_file_url('assets/config.js'), ['select2', 'jquery', 'wp-api'], '20200909', true);
        wp_enqueue_style('editor-form', Core::get_file_url('assets/style.css'), [], '20200909');
    }



    public static function form_handler()
    {

        if ('/editor/' != $_POST['_wp_http_referer']) {
            return;
        }

        $post_data = [
            'ID'    => intval($_GET['id']) ? intval($_GET['id']) : '',
            'post_title'    => wp_strip_all_tags($_POST['title']),
            'post_content'  => esc_textarea($_POST['description']),
            'post_author'   => get_user_by('login', 'digestbot')->ID,
            'post_category' => [get_term_by('slug', 'digest', 'category')->term_id],
            'comment_status' => 'open'
        ];

        if (isset($_POST['post_category'])) {

            $cids = $_POST['post_category'];
            $cids = array_map('intval', $cids);

            $post_data['post_category'] = $cids;
        }

        if (empty($post_data['post_title'])) {
            self::$errors[] = 'Пустой заголовок формы. Нужно указать заголовок';
        }

        if (empty($post_data['post_content'])) {
            self::$errors[] = 'Нет описания. Нужно добавить описание';
        }

        // if (empty($_POST['post_url'])) {
        //     // self::$errors[] = 'Нет URL. Нужно добавить URL';
        // }

        if (!empty(self::$errors)) {
            return;
        }

        if ($userId = get_current_user_id()) {
            $post_data['post_author'] = $userId;
        }

        if (!empty($_POST['save'])) {
            $status = get_post_status($post_data['ID']);
            if($status != 'publish'){
                $post_data['post_status'] = 'draft';
            } else {
                $post_data['post_status'] = 'publish';
            }
        } 

        if (!empty($_POST['publish'])) {
            $post_data['post_status'] = 'publish';
        }

        $post_id = wp_insert_post(wp_slash($post_data));

        if(empty($_POST['additional-enable'])){
            delete_post_meta($post_id, 'additional-enable');
        } else {
            update_post_meta($post_id, 'additional-enable', 1);
        }

        self::$success['id'] = $post_id;

        if (!empty($_POST['post_url'])) {
            $url = esc_url($_POST['post_url']);
            update_post_meta($post_id, 'ext-link-block', $url);
            update_post_meta($post_id, 'url-main', $url);

            $image_url = self::get_url_image_from_meta_tags($url);

        }

        if(!empty($image_url)){
            $image_featured = self::save_image_as_featured($post_id, $image_url);

            // dd($image_featured);
        }


        if (!empty($_POST['tags']) && is_array($_POST['tags'])) {
            $tags = array_map('intval', $_POST['tags']);
            if (is_array($tags)) {
                wp_set_post_tags($post_id, $tags);
            }
        } else {
            wp_set_post_tags($post_id, []);
        }


        $url_redirect = site_url('editor');
        $url_redirect = add_query_arg('id', $post_id, $url_redirect);
        wp_redirect($url_redirect);
    }



    public static function save_image_as_featured($post_id, $url_img)
    {

        $imageUrl = $url_img;

        $imageUrl = strtok($imageUrl, '?');

        // Get the file name
        $filename = substr($imageUrl, (strrpos($imageUrl, '/')) + 1);

        if (!(($uploads = wp_upload_dir(current_time('mysql'))) && false === $uploads['error'])) {
            return null;
        }

        // Generate unique file name
        $filename = wp_unique_filename($uploads['path'], $filename);

        // Move the file to the uploads dir
        $new_file = $uploads['path'] . "/$filename";

        if (!ini_get('allow_url_fopen')) {
            $file_data = curl_get_file_contents($imageUrl);
        } else {
            $file_data = @file_get_contents($imageUrl);
        }

        if (!$file_data) {
            return null;
        }

        file_put_contents($new_file, $file_data);

        // Set correct file permissions
        $stat = stat(dirname($new_file));
        $perms = $stat['mode'] & 0000666;
        @chmod($new_file, $perms);

        // Get the file type. Must to use it as a post thumbnail.
        $wp_filetype = wp_check_filetype($filename, $mimes = null);

        extract($wp_filetype);

        // No file type! No point to proceed further
        if ((!$type || !$ext) && !current_user_can('unfiltered_upload')) {
            return null;
        }

        // Compute the URL
        $url = $uploads['url'] . "/$filename";

        // Construct the attachment array
        $attachment = array(
            'post_mime_type' => $type,
            'guid' => $url,
            'post_parent' => null,
            'post_title' => $imageTitle,
            'post_content' => '',
        );

        $thumb_id = wp_insert_attachment($attachment, $file, $post_id);

        if (!is_wp_error($thumb_id)) {
            require_once(ABSPATH . '/wp-admin/includes/image.php');

            // Added fix by misthero as suggested
            wp_update_attachment_metadata($thumb_id, wp_generate_attachment_metadata($thumb_id, $new_file));
            update_attached_file($thumb_id, $new_file);

            set_post_thumbnail($post_id, $thumb_id);

            return $thumb_id;
        }

        return null;
    }



    public static function get_url_image_from_meta_tags($url)
    {
        $args = [
            'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/83.0.4103.116 Safari/537.36',
        ];


        $http = wp_remote_get($url, $args);
        $data = wp_remote_retrieve_body($http);
        $dom = new \DOMDocument('1.0', 'UTF-8');

        // $prev_libxml_use_internal_errors = libxml_use_internal_errors(true);

        $dom->loadHTML($data, LIBXML_NOWARNING | LIBXML_NOERROR);

        // libxml_clear_errors();

        $head = $dom->getElementsByTagName('head')->item(0);
        $meta_list = $head->getElementsByTagName("meta");

        foreach ($meta_list as $item) {
            // echo $item->getAttribute("property");
            if ($item->getAttribute("property") == "og:image") {
                $url_image = $item->getAttribute("content");
            }
        }

        if (isset($url_image)) {
            return $url_image;
        }

        // libxml_use_internal_errors($prev_libxml_use_internal_errors);

        return false;
     
    }

    public static function get_data()
    {

        $data = [
            'nonce' => wp_create_nonce('digest-submit-form')
        ];

        if (isset($_GET['id']) && $post = get_post($_GET['id'])) {
            $data['id'] = intval($_GET['id']);
        } else {
            $data['id'] = '';
        }

        if (empty($post)) {
            $data = [
                'post_title' => '',
                'post_content' => '',
                'url' => '',
                "terms_checklist_args" => [
                    'selected_cats' => [88]
                ]
            ];
        } else {
            $data = [
                'post_title' => $post->post_title,
                'post_content' => $post->post_content,
                'url' => get_post_meta($post->ID, 'ext-link-block', true),
            ];

            if ($categories = wp_get_post_categories($post->ID, ['fields' => 'all'])) {

                $data['terms_checklist_args'] = [];
                $data['terms_checklist_args']['selected_cats'] = [];
                foreach ($categories as $category) {
                    $data['terms_checklist_args']['selected_cats'][] = $category->term_id;
                }
            }
        }

        $data['terms_checklist_args']['exclude'][] = 123;

        $data['tags_options'] = wp_get_post_tags($post->ID);
        $data['additional_enable'] = get_post_meta($post->ID, 'additional-enable', true);

        // if(get)

        //     'selected_cats' => [88]
        // ];


        return $data;
    }
}

DigestSubmitShortcode::init();