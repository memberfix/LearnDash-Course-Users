<?php
/*
Plugin Name: LearnDash Course Users List
Description: Lists all users enrolled in a selected LearnDash course in the WordPress admin area and allows exporting the list as a CSV file with a custom name.
Version: 1.3
Author: Trim MemberFix
License: GPL2
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Retrieve all LearnDash course IDs
function learndash_get_all_course_ids() {
    $query_args = array(
        'post_type'         => 'sfwd-courses',
        'post_status'       => 'publish',
        'fields'            => 'ids',
        'orderby'           => 'title',
        'order'             => 'ASC',
        'nopaging'          => true,
    );

    $query = new WP_Query($query_args);
    return $query->posts;
}

// Retrieve all users for a specific course
function learndash_get_course_users($course_id) {
    if (empty($course_id)) {
        return false;
    }

    $users = learndash_get_users_for_course($course_id);
    if (is_object($users)) {
        $user_data = [];
        foreach ($users->results as $user_id) {
            $user_info = get_userdata($user_id);
            if ($user_info) {
                $course_progress = learndash_user_get_course_progress($user_id, $course_id);
                $percentage = !empty($course_progress['total']) 
                    ? floor(($course_progress['completed'] / $course_progress['total']) * 100) 
                    : 0;

                $user_data[] = [
                    'id'            => $user_id,
                    'name'          => $user_info->user_login,
                    'email'         => $user_info->user_email,
                    'course_status' => $course_progress['status'] ?? 'not_started',
                    'percentage'    => $percentage . '%',
                ];
            }
        }
        return $user_data;
    }
    return false;
}

// Add admin menu for LearnDash Course Users
function learndash_course_users_menu() {
    add_submenu_page(
        'learndash-lms',              // Parent menu slug
        'Course Users',               // Page title
        'Course Users',               // Menu title
        'manage_options',             // Capability
        'ld-course-users',            // Menu slug
        'learndash_course_users_page' // Callback function
    );
}
add_action('admin_menu', 'learndash_course_users_menu');

// Admin page callback function
function learndash_course_users_page() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('LearnDash Course Users - Trim MemberFix', 'learndash-course-users'); ?></h1>
        <form method="get" action="">
            <input type="hidden" name="page" value="ld-course-users" />
            <label for="course"><?php echo esc_html__('Select Course:', 'learndash-course-users'); ?></label>
            <select id="course" name="course">
                <option value=""><?php esc_html_e('-- Select Course --', 'learndash-course-users'); ?></option>
                <?php
                $courses = learndash_get_all_course_ids();
                foreach ($courses as $course_id) {
                    $selected = (isset($_GET['course']) && $_GET['course'] == $course_id) ? 'selected' : '';
                    echo '<option value="' . esc_attr($course_id) . '" ' . esc_attr($selected) . '>' . esc_html(get_the_title($course_id)) . '</option>';
                }
                ?>
            </select>
            <input type="submit" value="<?php esc_attr_e('View Users', 'learndash-course-users'); ?>" class="button button-primary" />
        </form>
        <?php
        if (isset($_GET['course']) && !empty($_GET['course'])) {
            $course_id = intval($_GET['course']);
            $users = learndash_get_course_users($course_id);
            if ($users) {
                ?>
                <h2><?php echo esc_html__('Users Enrolled in:', 'learndash-course-users') . ' ' . esc_html(get_the_title($course_id)); ?></h2>
                <form method="post" action="">
                    <input type="hidden" name="ld_course_id" value="<?php echo esc_attr($course_id); ?>" />
                    <label for="csv_filename"><?php echo esc_html__('Enter CSV File Name:', 'learndash-course-users'); ?></label>
                    <input type="text" name="csv_filename" id="csv_filename" placeholder="course_users.csv" required />
                    <input type="submit" name="ld_export_csv" value="<?php esc_attr_e('Export as CSV', 'learndash-course-users'); ?>" class="button button-secondary" />
                </form>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('User ID', 'learndash-course-users'); ?></th>
                            <th><?php esc_html_e('Name', 'learndash-course-users'); ?></th>
                            <th><?php esc_html_e('Email', 'learndash-course-users'); ?></th>
                            <th><?php esc_html_e('Course Status', 'learndash-course-users'); ?></th>
                            <th><?php esc_html_e('Progress (%)', 'learndash-course-users'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user) { ?>
                            <tr>
                                <td><?php echo esc_html($user['id']); ?></td>
                                <td><?php echo esc_html($user['name']); ?></td>
                                <td><?php echo esc_html($user['email']); ?></td>
                                <td><?php echo esc_html($user['course_status']); ?></td>
                                <td><?php echo esc_html($user['percentage']); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
                <?php
            } else {
                echo '<p>' . esc_html__('No users found for this course.', 'learndash-course-users') . '</p>';
            }
        }
        ?>
    </div>
    <?php
}

// Export as CSV handler
function learndash_export_course_users_csv() {
    if (isset($_POST['ld_export_csv']) && isset($_POST['ld_course_id'])) {
        $course_id = intval($_POST['ld_course_id']);
        $file_name = sanitize_file_name($_POST['csv_filename'] ?? 'course_users.csv');

        // Ensure file name ends with .csv
        if (!str_ends_with($file_name, '.csv')) {
            $file_name .= '.csv';
        }

        $users = learndash_get_course_users($course_id);

        if ($users) {
            // Send CSV headers
            header('Content-Type: text/csv');
            header("Content-Disposition: attachment;filename=\"$file_name\"");
            $output = fopen('php://output', 'w');

            // CSV header row
            fputcsv($output, ['User ID', 'Name', 'Email', 'Course Status', 'Progress (%)']);

            // Add user data to CSV
            foreach ($users as $user) {
                fputcsv($output, $user);
            }

            fclose($output);
            exit; // Terminate script after download.
        }
    }
}
add_action('admin_init', 'learndash_export_course_users_csv');
