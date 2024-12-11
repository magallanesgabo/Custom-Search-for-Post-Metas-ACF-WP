<?php
/*
Plugin Name: Produ Admin Search
Description: Este plugin mejora el rendimiento y la búsqueda en los Custom Post Types y nos permite buscar por Custom Fields.
Version: 2.0
Author: Produ DEV Team - Gabriel M
*/

require_once plugin_dir_path(__FILE__) . 'includes/produ-contact-functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/produ-company-functions.php';

function pas_create_intermediate_tables() {
    pas_create_intermediate_table_contacts_searcher();
    pas_create_intermediate_table_companies_searcher();
}
register_activation_hook(__FILE__, 'pas_create_intermediate_tables');