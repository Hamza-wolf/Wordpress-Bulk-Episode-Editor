<?php
/**
 * Plugin Name: Bulk Episode Editor
 * Plugin URI: https://github.com/yourusername/bulk-episode-editor
 * Description: Simple episode loader with slug editing
 * Version: 1.1
 * Author: Hami
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

class BulkEpisodeEditor {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_bulk_edit_episodes', [$this, 'handle_bulk_edit']);
    }

    public function add_admin_menu() {
        add_menu_page(
            'Episode Manager',
            'Episode Manager',
            'manage_options',
            'episode-manager',
            [$this, 'admin_page'],
            'dashicons-editor-ol'
        );
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_episode-manager') return;
        
        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', "
            jQuery(document).ready(function($) {
                $('#load-episodes').click(function() {
                    var series = $('#series').val();
                    if (!series) {
                        alert('Please select a series');
                        return;
                    }
                    
                    $('#episodes-table tbody').html('<tr><td colspan=\"4\">Loading episodes...</td></tr>');
                    $('#bulk-edit-section').hide();
                    
                    $.post(ajaxurl, {
                        action: 'bulk_edit_episodes',
                        nonce: '" . wp_create_nonce('bulk_edit_nonce') . "',
                        series: series,
                        subaction: 'load'
                    }, function(response) {
                        if (response.success && response.data && response.data.length > 0) {
                            var rows = '';
                            response.data.sort((a, b) => parseInt(a.number) - parseInt(b.number));
                            response.data.forEach(function(ep) {
                                rows += '<tr>' +
                                    '<td><input type=\"checkbox\" class=\"episode-check\" value=\"' + ep.ID + '\"></td>' +
                                    '<td>' + (ep.number || 'N/A') + '</td>' +
                                    '<td>' + ep.title + '</td>' +
                                    '<td><a href=\"/wp-admin/post.php?post=' + ep.ID + '&action=edit\" target=\"_blank\">Edit</a></td>' +
                                '</tr>';
                            });
                            $('#episodes-table tbody').html(rows);
                            $('#episodes-count').text('Found ' + response.data.length + ' episodes');
                            $('#bulk-edit-section').show();
                        } else {
                            $('#episodes-table tbody').html('<tr><td colspan=\"4\">No episodes found</td></tr>');
                            $('#episodes-count').text('No episodes found');
                        }
                    }).fail(function(xhr, status, error) {
                        console.error('Ajax Error:', error);
                        $('#episodes-table tbody').html('<tr><td colspan=\"4\">Error loading episodes</td></tr>');
                        $('#episodes-count').text('Error loading episodes');
                    });
                });

                $('#check-all').change(function() {
                    $('.episode-check').prop('checked', $(this).prop('checked'));
                });

                $('#update-slugs').click(function() {
                    var selected = $('.episode-check:checked').map(function() {
                        return $(this).val();
                    }).get();

                    if (selected.length === 0) {
                        alert('Please select episodes to update');
                        return;
                    }

                    $(this).prop('disabled', true);
                    $('#update-status').html('Updating slugs...').css('color', '#0073aa');

                    $.post(ajaxurl, {
                        action: 'bulk_edit_episodes',
                        nonce: '" . wp_create_nonce('bulk_edit_nonce') . "',
                        subaction: 'update',
                        posts: selected
                    }, function(response) {
                        $('#update-slugs').prop('disabled', false);
                        if (response.success) {
                            $('#update-status').html('Updated successfully!').css('color', '#46b450');
                            setTimeout(function() {
                                $('#load-episodes').click();
                            }, 500);
                        } else {
                            $('#update-status').html('Error: ' + response.data).css('color', '#dc3232');
                        }
                    });
                });
            });
        ");

        wp_add_inline_style('admin-bar', "
            .episode-manager-wrap { max-width: 1200px; margin: 20px; }
            .episode-manager-wrap .widefat { margin-top: 20px; }
            .episode-count { margin: 10px 0; font-style: italic; }
            #series { min-width: 200px; }
            .bulk-edit-box { margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ccd0d4; }
            .bulk-edit-box h3 { margin-top: 0; }
            .update-controls { margin-top: 15px; }
            #update-status { margin-left: 10px; }
        ");
    }

    public function admin_page() {
        $series = get_posts([
            'post_type' => 'anime',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ]);
        ?>
        <div class="wrap episode-manager-wrap">
            <h1>Episode Manager</h1>
            
            <div style="margin: 20px 0;">
                <select id="series">
                    <option value="">Select Anime Series</option>
                    <?php foreach ($series as $s): ?>
                        <option value="<?php echo esc_attr($s->ID); ?>">
                            <?php echo esc_html($s->post_title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="load-episodes" class="button button-primary">
                    Load Episodes
                </button>
            </div>

            <div id="episodes-count" class="episode-count"></div>

            <div id="bulk-edit-section" class="bulk-edit-box" style="display:none;">
                <h3>Bulk Edit Slugs</h3>
                <div class="update-controls">
                    <button type="button" id="update-slugs" class="button button-primary">
                        Add Random String to Slugs
                    </button>
                    <span id="update-status"></span>
                </div>
            </div>
            
            <table class="widefat striped" id="episodes-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="check-all"></th>
                        <th>Episode #</th>
                        <th>Title</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <?php
    }

    public function handle_bulk_edit() {
        check_ajax_referer('bulk_edit_nonce', 'nonce');
        
        $subaction = $_POST['subaction'] ?? 'load';
        $series = intval($_POST['series']);

        if ($subaction === 'load') {
            if (!$series) {
                wp_send_json_error('Please select a series');
                exit;
            }

            $posts = get_posts([
                'post_type' => 'post',
                'posts_per_page' => -1,
                'meta_query' => [
                    [
                        'key' => 'ero_seri',
                        'value' => $series,
                        'compare' => '='
                    ]
                ]
            ]);

            if (empty($posts)) {
                wp_send_json_error('No episodes found for this series');
                exit;
            }

            $episodes = [];
            foreach ($posts as $post) {
                $episode_number = '';
                $possible_meta_keys = ['_episode_number', 'episode_number', 'episode', 'ep_number', 'episode_num'];
                
                foreach ($possible_meta_keys as $meta_key) {
                    $value = get_post_meta($post->ID, $meta_key, true);
                    if (!empty($value)) {
                        $episode_number = $value;
                        break;
                    }
                }

                if (empty($episode_number)) {
                    if (preg_match('/episode\s*(\d+)/i', $post->post_title, $matches) || 
                        preg_match('/ep\s*(\d+)/i', $post->post_title, $matches) ||
                        preg_match('/\b(\d+)\b/', $post->post_title, $matches)) {
                        $episode_number = $matches[1];
                    }
                }

                $episodes[] = [
                    'ID' => $post->ID,
                    'number' => $episode_number ?: 'N/A',
                    'title' => $post->post_title
                ];
            }

            usort($episodes, function($a, $b) {
                if ($a['number'] === 'N/A') return 1;
                if ($b['number'] === 'N/A') return -1;
                return intval($a['number']) - intval($b['number']);
            });

            wp_send_json_success($episodes);
            exit;

        } elseif ($subaction === 'update') {
            $posts = array_map('intval', $_POST['posts'] ?? []);
            
            if (empty($posts)) {
                wp_send_json_error('Please select episodes to update');
                exit;
            }

            global $wpdb;
            $updated = 0;
            $batch_size = 50;
            $batches = array_chunk($posts, $batch_size);
            
            foreach ($batches as $batch) {
                $cases = [];
                $params = [];
                
                foreach ($batch as $post_id) {
                    $post = get_post($post_id);
                    if (!$post) continue;
                    
                    $random = substr(md5(uniqid() . $post_id . time() . rand()), 0, 6);
                    $new_slug = $post->post_name . '-' . $random;
                    
                    $cases[] = "WHEN %d THEN %s";
                    $params[] = $post_id;
                    $params[] = sanitize_title($new_slug);
                }
                
                if (!empty($cases)) {
                    $query = $wpdb->prepare(
                        "UPDATE {$wpdb->posts} SET post_name = (CASE ID " . 
                        implode(' ', $cases) . 
                        " END) WHERE ID IN (" . implode(',', array_fill(0, count($batch), '%d')) . ")",
                        array_merge($params, $batch)
                    );
                    
                    $result = $wpdb->query($query);
                    if ($result !== false) {
                        $updated += count($batch);
                    }
                }
            }

            clean_post_cache($posts);
            wp_send_json_success("Updated $updated episodes with random slugs");
            exit;
        }

        wp_send_json_error('Invalid subaction');
        exit;
    }
}

new BulkEpisodeEditor();