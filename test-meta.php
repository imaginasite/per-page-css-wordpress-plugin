<?php
require_once 'wp-load.php';
$pt = get_post_type_object('wp_template');
echo "wp_template supports custom-fields: " . (post_type_supports('wp_template', 'custom-fields') ? 'yes' : 'no') . "\n";
