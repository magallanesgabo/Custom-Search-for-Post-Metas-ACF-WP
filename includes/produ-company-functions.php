<?php
/**
 * Funciones para el post type 'produ-company'
 */

// Crear tabla intermedia para empresas
function pas_create_intermediate_table_companies_searcher() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'search_tb_companies';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id INT NOT NULL AUTO_INCREMENT,
        post_id INT NOT NULL,
        company_info TEXT DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY `post_id` (`post_id`),
        FULLTEXT `company_info` (`company_info`)
    ) $charset_collate;";
    $wpdb->query($sql);
}

function pas_produ_companies_custom_admin_posts_request($request, $query) {
    global $pagenow, $typenow, $wpdb;
    $table_name = $wpdb->prefix . 'search_tb_companies';

    if (is_admin() && $pagenow == 'edit.php' && $typenow === 'produ-company' && $query->is_main_query()) {
        $search_term = isset($query->query['s']) ? esc_sql($query->query['s']) : '';
        $limit = $query->get('posts_per_page');
        $page = $query->get('paged') ? $query->get('paged') : 1;
        $offset = ($page - 1) * $limit;

        $request = "SELECT SQL_CALC_FOUND_ROWS DISTINCT {$wpdb->posts}.* 
                    FROM {$wpdb->posts} 
                    INNER JOIN $table_name ON {$wpdb->posts}.ID = {$table_name}.post_id 
                    WHERE 1=1 
                    AND {$wpdb->posts}.post_type = 'produ-company' ";

        if ($search_term) {
            $request .= "AND (
                            MATCH({$table_name}.company_info) AGAINST ('\"$search_term\"' IN BOOLEAN MODE) 
                            OR {$wpdb->posts}.ID LIKE '%$search_term%'
                            OR {$wpdb->posts}.post_title LIKE '%$search_term%' 
                            OR {$wpdb->posts}.post_modified LIKE '%$search_term%'
                            OR EXISTS (
                                SELECT 1 FROM {$wpdb->postmeta} pm
                                WHERE pm.post_id = {$wpdb->posts}.ID
                                AND (
                                    pm.meta_key = 'direccion' AND pm.meta_value LIKE '%$search_term%'
                                    OR pm.meta_key = 'codigo_postal' AND pm.meta_value LIKE '%$search_term%'
                                    OR pm.meta_key = 'categories_company' AND pm.meta_value LIKE '%$search_term%'
                                    OR pm.meta_key = 'contact_information' AND pm.meta_value LIKE '%$search_term%'
                                    OR pm.meta_key = 'pais' AND pm.meta_value LIKE '%$search_term%'
                                )
                            )
                            OR EXISTS (
                                SELECT 1 FROM {$wpdb->term_relationships} tr
                                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                                INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                                WHERE tr.object_id = {$wpdb->posts}.ID
                                AND (t.name LIKE '%$search_term%' OR t.slug LIKE '%$search_term%')
                            )
                          ) ";
        }

        $request .= "ORDER BY {$wpdb->posts}.post_title ASC 
                     LIMIT $limit OFFSET $offset;";
    }

    return $request;
}
add_filter('posts_request', 'pas_produ_companies_custom_admin_posts_request', 10, 2);

function pas_sync_company_details_on_post_save($post_id) {
    if (get_post_type($post_id) !== 'produ-company') {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'search_tb_companies';

    $direccion = get_field('direccion', $post_id);
    $codigo_postal = get_field('codigo_postal', $post_id);
    $pais = get_field('pais', $post_id);
    $state = isset($pais['stateName']) ? $pais['stateName'] : '';
    $city = isset($pais['cityName']) ? $pais['cityName'] : '';
    $country = isset($pais['countryName']) ? $pais['countryName'] : '';

    $contacts_info = [];
    $contacts = get_field('contact_information', $post_id);

    if ($contacts) {
        foreach ($contacts as $contact) {
            $term = get_term($contact['contact_type'], 'contact_type');
            if ($term && isset($term->name)) {
                $contacts_info[] = sprintf('%s: %s', $term->name, $contact['valor']);
            } else {
                $contacts_info[] = $contact['valor'];
            }
        }
    }

    $company_info = implode(', ', array_filter([
        $direccion,
        $codigo_postal,
        $city,
        $state,
        $country,
        implode(', ', $contacts_info),
    ]));
    $company_info = $wpdb->_real_escape($company_info);

    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(id) FROM $table_name WHERE post_id = %d LIMIT 1;",
        $post_id
    ));

    if ($exists == 0) {
        $wpdb->insert(
            $table_name,
            array(
                'post_id'       => $post_id,
                'company_info'  => $company_info
            ),
            array(
                '%d',
                '%s'
            )
        );
    } else {
        $wpdb->update(
            $table_name,
            array(
                'company_info'  => $company_info
            ),
            array('post_id'     => $post_id),
            array('%s'),
            array('%d')
        );
    }
}
add_action('save_post', 'pas_sync_company_details_on_post_save');