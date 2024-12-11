<?php
/**
 * Funciones para el post type 'produ-contact'
 */

/**
 * Creates the intermediate table for storing searchable contact information.
 * The table includes columns for post ID, contact information, and associated companies.
 */
function pas_create_intermediate_table_contacts_searcher() {
    global $wpdb;
    $table_name = $wpdb->prefix.'search_tb_contacts';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id INT NOT NULL AUTO_INCREMENT,
        post_id INT NOT NULL,
        contact_info TEXT DEFAULT NULL,
        companies TEXT DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY `post_id` (`post_id`),
        FULLTEXT `contact_info` (`contact_info`)
    ) $charset_collate;";

    $wpdb->query($sql);
}
register_activation_hook(__FILE__, 'pas_create_intermediate_table_contacts_searcher');

/**
 * Customizes the admin post request to add search functionality for 'produ-contact' post type.
 * The search is performed on the contact info stored in the custom table and filtered by company and other parameters.
 *
 * @param string $request The original SQL query.
 * @param WP_Query $query The current query object.
 * @return string The modified SQL query.
 */
function pas_produ_contacts_custom_admin_posts_request($request, $query) {
    global $pagenow, $typenow, $wpdb;
    $table_name = $wpdb->prefix.'search_tb_contacts';
    if ( is_admin() &&
        $pagenow == 'edit.php'
        && isset($query->query['post_type'])
        && $query->query['post_type'] === 'produ-contact'
        && $query->is_main_query() ) {

        $search_term = isset($query->query['s']) ? esc_sql($query->query['s']) : '';

        $exp_search_terms = explode(' ', $search_term);
        $post_status = isset($query->query['post_status']) && $query->query['post_status'] ? $query->query['post_status'] : 'any';
        $company     = (isset($_GET['produ_company']) && ($_GET['produ_company'])) ? $_GET['produ_company'] : 0;
        $order       = (isset($query->query['order']) && $query->query['order']) ? $query->query['order'] : 'ASC';
        $orderby     = (isset($query->query['orderby']) && $query->query['orderby']) && $query->query['orderby'] ? 'post_'.$query->query['orderby'] : 'post_title';
        $orderby     = $orderby !== 'post_mytitle' ? $orderby : 'post_title';
        $limit       = $query->get('posts_per_page');
        $page        = $query->get('paged') ? $query->get('paged') : 1;
        $offset      = ($page - 1) * $limit;
        $flag        = FALSE;

        $request = $wpdb->prepare(
            "SELECT SQL_CALC_FOUND_ROWS DISTINCT {$wpdb->posts}.*,
                    MATCH ({$table_name}.contact_info) AGAINST (%s IN BOOLEAN MODE) AS relevancia
                    FROM {$wpdb->posts}
                    INNER JOIN $table_name ON {$wpdb->posts}.ID = {$table_name}.post_id
                    WHERE 1=1 AND {$wpdb->posts}.post_type = %s ",
            $search_term,
            'produ-contact'
        );

        if ($company) {
            $request .= $wpdb->prepare("AND (FIND_IN_SET(%d, {$table_name}.companies) > 0) ", $company);
        }

        if ($search_term) {
            $request .= $wpdb->prepare(
                "AND (MATCH({$table_name}.contact_info) AGAINST (%s IN BOOLEAN MODE) OR {$table_name}.post_id LIKE %s) ",
                $search_term,
                '%' . $wpdb->esc_like($search_term) . '%'
            );
        }

        if ($post_status !== 'any') {
            $request .= $wpdb->prepare("AND {$wpdb->posts}.post_status = %s ", $post_status);
        } else {
            $request .= "AND {$wpdb->posts}.post_status != 'trash' ";
        }

        $request .= $wpdb->prepare("ORDER BY {$wpdb->posts}.{$orderby} {$order}, relevancia DESC LIMIT %d OFFSET %d;", $limit, $offset);
    }
    return $request;
}
add_filter('posts_request', 'pas_produ_contacts_custom_admin_posts_request', 10, 2);

/**
 * Syncs the contact details for 'produ-contact' posts to the custom search table.
 * Updates or inserts contact info and associated company IDs when a post is saved.
 *
 * @param int $post_id The ID of the post being saved.
 */
function pas_sync_contact_details_on_post_save($post_id) {
    if (get_post_type($post_id) !== 'produ-contact') {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix.'search_tb_contacts';

    $name = get_field('meta_company_user_name');
    $lastname = get_field('meta_company_user_last_name');
    $full_name = $name." ".$lastname;

    $companies = [];
    $companies_ids = [];
    $contacts = [];
    $positions = [];

    if (have_rows('meta_contact_company', $post_id)) {
        while ( have_rows( 'meta_contact_company', $post_id ) ) {
            the_row();
            $company_id = get_sub_field( 'meta_job_company' );
            $end_fc = get_sub_field( 'meta_job_end' );
            $positions[] = get_sub_field( 'meta_job_position' );
            $post_company = get_post( $company_id );
            if ( ! empty( $company_id ) && $end_fc === '') {
                $companies[] = $post_company->post_title;
                $companies_ids[] = $post_company->ID;
                while ( have_rows( 'meta_job_vcontact' ) ) {
                    the_row();
                    $contact_value = get_sub_field( 'value' );
                    if ( ! empty( $contact_value ) ) {
                        $contacts[] = $contact_value;
                    }
                }
            }

            while ( have_rows( 'meta_vcontact_personal' ) ) {
                the_row();
                $contact_value1 = get_sub_field( 'value_personal' );
                if ( ! empty( $contact_value1 ) ) {
                    $contacts[] = $contact_value1;
                }
            }
        }
    }


    $contact_info = implode(', ', array_filter([$full_name, implode(', ', $companies), implode(', ', $contacts), implode(', ', $positions)]));
    $contact_info = $wpdb->_real_escape($contact_info);

    $name = get_field('meta_company_user_name', $post_id);
    $lastname = get_field('meta_company_user_last_name', $post_id);
    $full_name = $name." ".$lastname;

    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(id) FROM $table_name WHERE post_id = %d LIMIT 1;",
        $post_id
    ));

    if ($exists == 0) {
        $wpdb->insert(
            $table_name,
            array(
                'post_id'       => $post_id,
                'contact_info'  => $contact_info,
                'companies'     => implode(',', $companies_ids)
            ),
            array(
                '%d',
                '%s',
                '%s'
            )
        );
    } else {
        $wpdb->update(
            $table_name,
            array(
                'contact_info'  => $contact_info,
                'companies'     => implode(',', $companies_ids)
            ),
            array('post_id'     => $post_id),
            array('%s', '%s'),
            array('%d')
        );
    }
}
add_action('save_post', 'pas_sync_contact_details_on_post_save');
